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

# Detecta o banco ANTES de iniciar Docker. So sera criada seed se nao existia
# volume MySQL. Isso atende reinstalacao limpa que preserva .env, sem executar
# seed automaticamente em bancos existentes (mesmo quando usuarios = 0).
$existingDockerVolumes = @(docker volume ls --format '{{.Name}}')
Assert-Exit 'Consulta de volumes antes da inicializacao'
$mysqlVolumeAlreadyExists = $existingDockerVolumes -contains 'ace-credentials_mysql_data'
$freshInstall = -not (Test-Path '.env')
if ($freshInstall) {
    if ($mysqlVolumeAlreadyExists -or $existingDockerVolumes -contains 'ace-credentials_app_storage') {
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

# Em atualizacoes com .env existente, backup ANTES de qualquer migration.
# Abortamos o processo caso o backup nao possa ser obtido.
if (-not $freshInstall) {
    Write-Host "`nProtegendo banco ACE: backup automatico pre-atualizacao..." -ForegroundColor Cyan
    docker compose -f compose.yaml up -d --wait db
    Assert-Exit 'Inicializacao temporaria do MySQL para backup'
    & (Join-Path $PSScriptRoot 'docker-backup.ps1')
    if ($LASTEXITCODE -ne 0) { throw 'Backup falhou. Atualizacao cancelada.' }
}

Write-Host "`nConstruindo e inicializando ACE (nao remove volumes existentes)..." -ForegroundColor Cyan
docker compose -f compose.yaml up --build -d
Assert-Exit 'Inicializacao dos containers'
docker compose -f compose.yaml ps
Assert-Exit 'Verificacao de servicos'

# Seed ocorre apenas quando NAO havia volume MySQL antes da inicializacao.
# O .env pode ser preservado durante uma reinstalacao limpa; o banco, nao.
# Nunca executa seed ao iniciar com volume existente, mesmo que esteja vazio.
if (-not $mysqlVolumeAlreadyExists) {
    $envText = Get-Content '.env' -Raw -Encoding UTF8
    if ($envText -notmatch '(?m)^APP_ENV=local\s*$' -or $envText -notmatch '(?m)^DB_DATABASE=ace_db\s*$') {
        throw 'Banco novo detectado, mas seed automatica permitida somente em APP_ENV=local com DB_DATABASE=ace_db.'
    }
    $devPasswordMatch = [regex]::Match($envText, '(?m)^ACE_DEMO_DEV_PASSWORD=(.*)$')
    $userPasswordMatch = [regex]::Match($envText, '(?m)^ACE_DEMO_USER_PASSWORD=(.*)$')
    if (-not $devPasswordMatch.Success -or -not $userPasswordMatch.Success) {
        throw 'Configure ACE_DEMO_DEV_PASSWORD e ACE_DEMO_USER_PASSWORD no .env para a primeira instalacao local.'
    }
    $devSeedPassword = $devPasswordMatch.Groups[1].Value.Trim().Trim('"')
    $userSeedPassword = $userPasswordMatch.Groups[1].Value.Trim().Trim('"')
    if ($devSeedPassword.Length -lt 14 -or $userSeedPassword.Length -lt 14 -or $devSeedPassword -ceq $userSeedPassword) {
        throw 'As senhas DEMO devem ser distintas e ter pelo menos 14 caracteres.'
    }
    Write-Host 'Banco MySQL novo: criando DEV e conta de teste em ambiente local...' -ForegroundColor Cyan
    docker compose -f compose.yaml exec -T -e ACE_DEMO_SEED_ENABLED=true -e "ACE_DEMO_DEV_PASSWORD=$devSeedPassword" -e "ACE_DEMO_USER_PASSWORD=$userSeedPassword" app php artisan db:seed --force
    Assert-Exit 'Seed inicial DEV e teste'
} else {
    Write-Host 'Volume MySQL preexistente: seed automatica bloqueada para preservar os dados.' -ForegroundColor Green
}
Write-Host 'Inicializacao concluida. Consulte as credenciais locais e avisos no README.' -ForegroundColor Green

Write-Host "`nACE disponivel em http://127.0.0.1:8088" -ForegroundColor Green
Write-Host 'Seguranca: mantenha os arquivos .env, composer.lock e package-lock.json para backups/atualizacoes.' -ForegroundColor Yellow
