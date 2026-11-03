param([string]$Projeto = '.')
$ErrorActionPreference = 'Stop'

try {
    $root = [System.IO.Path]::GetFullPath((Join-Path (Get-Location).Path $Projeto))
    $payload = Join-Path $PSScriptRoot 'payload'
    $required = @(
        'composer.json', 'routes/api.php',
        'app/Http/Controllers/Api/V1/GoogleDriveController.php',
        'app/Http/Controllers/Api/V1/GoogleDriveManagerController.php',
        'app/Services/GoogleDriveService.php',
        'app/Services/GoogleDriveManagerService.php',
        'resources/js/views/CloudPage.vue',
        'resources/js/views/SitesPage.vue'
    )
    foreach ($f in $required) {
        if (-not (Test-Path -LiteralPath (Join-Path $root $f))) {
            throw "Nao encontrei '$f'. Instale primeiro o ACE Drive Manager anterior e execute na pasta web-ace."
        }
    }

    $files = @(
        'app/Http/Controllers/Api/V1/GoogleDriveController.php',
        'app/Http/Controllers/Api/V1/GoogleDriveManagerController.php',
        'routes/ace-drive-manager.php',
        'database/migrations/2026_11_02_090000_allow_multiple_google_drive_connections_per_tenant.php',
        'resources/js/views/CloudPage.vue',
        'resources/css/ace-cloud-hub.css',
        'resources/css/ace-sites-tabs-fix.css'
    )
    foreach ($f in $files) {
        if (-not (Test-Path -LiteralPath (Join-Path $payload $f))) {
            throw "O pacote esta incompleto: $f"
        }
    }

    $routesPath = Join-Path $root 'routes/api.php'
    $sitesPath = Join-Path $root 'resources/js/views/SitesPage.vue'
    $routes = [System.IO.File]::ReadAllText($routesPath)
    $sites = [System.IO.File]::ReadAllText($sitesPath)
    if (-not $routes.Contains("Route::get('/cloud/google/status'")) {
        throw 'Nao encontrei a rota /cloud/google/status em routes/api.php. Nenhum arquivo alterado.'
    }
    if (-not $sites.Contains('ace-site-dialog-body')) {
        throw 'Nao encontrei a ficha de Sites esperada. Nenhum arquivo alterado.'
    }
    if (-not $routes.Contains('ace-drive-manager.php')) {
        throw 'As rotas do Drive Manager anterior nao estao registradas em routes/api.php. Instale a versao anterior antes.'
    }

    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $backup = Join-Path $root ('.ace-backups/cloud-hub-v2-' + $stamp)
    $backupList = @('routes/api.php','resources/js/views/SitesPage.vue') + $files
    foreach ($f in $backupList) {
        $old = Join-Path $root $f
        if (Test-Path -LiteralPath $old) {
            $copy = Join-Path $backup $f
            New-Item -ItemType Directory -Path (Split-Path -Parent $copy) -Force | Out-Null
            [System.IO.File]::Copy($old, $copy, $true)
        }
    }

    $utf8 = New-Object System.Text.UTF8Encoding($false)
    foreach ($f in $files) {
        $source = Join-Path $payload $f
        $target = Join-Path $root $f
        New-Item -ItemType Directory -Path (Split-Path -Parent $target) -Force | Out-Null
        [System.IO.File]::Copy($source, $target, $true)
        Write-Host "OK: $f"
    }

    if (-not $routes.Contains("/cloud/google/accounts/{accountId}")) {
        $insert = @'
        Route::get('/cloud/google/accounts', [GoogleDriveController::class, 'accounts'])->middleware('throttle:60,1');
        Route::delete('/cloud/google/accounts/{accountId}', [GoogleDriveController::class, 'disconnectAccount'])->whereNumber('accountId')->middleware('throttle:15,1');
'@
        $needle = "        Route::get('/cloud/google/status'"
        $routes = $routes.Replace($needle, $insert + [System.Environment]::NewLine + $needle)
        [System.IO.File]::WriteAllText($routesPath, $routes, $utf8)
        Write-Host 'OK: routes/api.php - rotas de contas adicionadas'
    } else {
        Write-Host 'OK: routes/api.php - ja atualizado'
    }

    if (-not $sites.Contains('ace-sites-tabs-fix.css')) {
        $sites = $sites.TrimEnd() + [System.Environment]::NewLine + '<style src="../../css/ace-sites-tabs-fix.css"></style>' + [System.Environment]::NewLine
        [System.IO.File]::WriteAllText($sitesPath, $sites, $utf8)
        Write-Host 'OK: SitesPage.vue - correcao das abas carregada'
    } else {
        Write-Host 'OK: SitesPage.vue - ja atualizado'
    }

    Write-Host ''
    Write-Host 'ACE CLOUD HUB V2: arquivos instalados!' -ForegroundColor Green
    Write-Host "Backup anterior: $backup"
    Write-Host 'ATENCAO: execute um backup do MySQL antes da migracao.' -ForegroundColor Yellow
    Write-Host 'Em web-ace, atualize: docker compose up -d --build app web'
    Write-Host 'Confira migrations: docker compose exec app php artisan migrate:status'
    Write-Host 'Abra: http://127.0.0.1:8088/nuvem'
}
catch {
    Write-Error $_.Exception.Message
    exit 1
}
