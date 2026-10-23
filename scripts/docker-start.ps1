#Requires -Version 5.1
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $root

function Assert-Exit([string]$description) {
    if ($LASTEXITCODE -ne 0) {
        throw "$description falhou (codigo $LASTEXITCODE). Consulte os erros acima. Seus volumes e seu .env NAO foram apagados."
    }
}
function New-RandomHex([int]$length) {
    $data = New-Object byte[] $length
    $random = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $random.GetBytes($data) } finally { $random.Dispose() }
    return ([BitConverter]::ToString($data) -replace '-', '').ToLowerInvariant()
}
function New-AppKey {
    $data = New-Object byte[] 32
    $random = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $random.GetBytes($data) } finally { $random.Dispose() }
    return 'base64:' + [Convert]::ToBase64String($data)
}

Write-Host "`nACE - Docker / Laravel 13 / PHP 8.3" -ForegroundColor Cyan
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker Desktop nao encontrado. Instale e abra o Docker Desktop antes de continuar.'
}
docker compose version
Assert-Exit 'Docker Compose'
docker info --format '{{.ServerVersion}}' | Out-Null
Assert-Exit 'Docker Engine (abra o Docker Desktop)'

if (-not (Test-Path '.env')) {
    $allVolumes = @(docker volume ls --format '{{.Name}}')
    Assert-Exit 'Consulta de volumes'
    if ($allVolumes -contains 'ace-credentials_mysql_data' -or $allVolumes -contains 'ace-credentials_app_storage') {
        throw 'Volumes ACE anteriores detectados sem .env. RECUPERE o .env antigo; nao gere nova APP_KEY ou senha de banco.'
    }
    $template = Get-Content '.env.docker.example' -Raw -Encoding UTF8
    $template = $template.Replace('__ACE_APP_KEY__', (New-AppKey))
    $template = $template.Replace('__ACE_DB_PASSWORD__', (New-RandomHex 24))
    $template = $template.Replace('__ACE_MYSQL_ROOT_PASSWORD__', (New-RandomHex 24))
    [System.IO.File]::WriteAllText((Join-Path $root '.env'), $template, (New-Object System.Text.UTF8Encoding($false)))
    Write-Host '.env novo criado com chaves aleatorias (preserve esse arquivo).' -ForegroundColor Green
} else {
    $content = Get-Content '.env' -Raw -Encoding UTF8
    if ($content -notmatch '(?m)^DB_HOST=db\s*$' -or $content -notmatch '(?m)^DB_CONNECTION=mysql\s*$' -or $content -notmatch '(?m)^APP_KEY=base64:') {
        throw 'O .env encontrado nao parece ser do ACE Docker. Confira manualmente para evitar perda de dados.'
    }
    Write-Host '.env existente PRESERVADO.' -ForegroundColor Green
}

# Lockfiles sao gerados pela primeira vez DENTRO DO DOCKER.
# Este ambiente de chat nao pode acessar o registro Composer/npm nem executar o Docker Engine.
# Os arquivos gerados ficam no projeto e sao reutilizados nas proximas builds.
if (-not (Test-Path 'composer.lock')) {
    Write-Host "`nGerando composer.lock com PHP 8.3 no Docker (sem desativar bloqueios de seguranca)..." -ForegroundColor Cyan
    docker compose -f compose.yaml --profile setup run --rm --build --no-deps php-lock
    Assert-Exit 'Resolucao segura de dependencias PHP'
    if (-not (Test-Path 'composer.lock')) { throw 'Composer nao gerou composer.lock. Build cancelada.' }
    Write-Host 'composer.lock gerado.' -ForegroundColor Green
} else {
    Write-Host 'composer.lock existente PRESERVADO.' -ForegroundColor Green
}
if (-not (Test-Path 'package-lock.json')) {
    Write-Host "`nGerando package-lock.json dentro do Docker..." -ForegroundColor Cyan
    docker compose -f compose.yaml --profile setup run --rm --no-deps node-lock
    Assert-Exit 'Resolucao de dependencias do frontend'
    if (-not (Test-Path 'package-lock.json')) { throw 'npm nao gerou package-lock.json. Build cancelada.' }
    Write-Host 'package-lock.json gerado.' -ForegroundColor Green
} else {
    Write-Host 'package-lock.json existente PRESERVADO.' -ForegroundColor Green
}

Write-Host "`nValidando Compose..." -ForegroundColor Cyan
docker compose -f compose.yaml config --quiet
Assert-Exit 'Validacao do Docker Compose'

Write-Host "`nConstruindo e inicializando ACE (nao remove volumes existentes)..." -ForegroundColor Cyan
docker compose -f compose.yaml up --build -d
Assert-Exit 'Inicializacao dos containers'
docker compose -f compose.yaml ps
Assert-Exit 'Verificacao de servicos'

# A tentativa anterior pode ter criado o .env antes do Composer falhar.
# Por isso verificamos USUARIOS no banco e nao usamos somente a existencia do .env.
$status = @(docker compose -f compose.yaml exec -T app php artisan vault:bootstrap-admin --status)
Assert-Exit 'Consulta de administrador'
$statusText = ($status -join "`n").Trim()
if ($statusText -eq 'needs_setup') {
    Write-Host "`nCrie sua empresa e seu primeiro administrador (sem senha padrao)." -ForegroundColor Yellow
    docker compose -f compose.yaml exec app php artisan vault:bootstrap-admin
    Assert-Exit 'Criacao do administrador'
} elseif ($statusText -eq 'ready') {
    Write-Host 'Administrador existente preservado; nenhuma senha foi alterada.' -ForegroundColor Green
} else {
    throw "Status de inicializacao inesperado. Nao alteramos usuarios. Saida: $statusText"
}

Write-Host "`nACE disponivel em http://127.0.0.1:8088" -ForegroundColor Green
Write-Host 'Seguranca: mantenha os arquivos .env, composer.lock e package-lock.json para backups/atualizacoes.' -ForegroundColor Yellow
