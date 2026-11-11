#Requires -Version 5.1
# Limpeza EXPRESSA somente com confirmacao explicita; nao e um comando de update.
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $root
if (-not (Test-Path '.env')) { throw 'Execute INICIAR-ACE.bat antes desta reinstalacao limpa.' }
$envText = Get-Content '.env' -Raw
if ($envText -notmatch '(?m)^APP_ENV=local\s*$' -or $envText -notmatch '(?m)^DB_DATABASE=ace_db\s*$') {
    throw 'Abortado: permitido apenas para ACE local / ace_db.'
}
Write-Host 'ATENCAO: ESTE PROCEDIMENTO APAGA AS TABELAS DO BANCO ACE LOCAL.' -ForegroundColor Red
Write-Host 'Antes da operacao sera criado um dump SQL e copia do .env.' -ForegroundColor Yellow
$answer = Read-Host 'Para confirmar, digite exatamente REINSTALAR ACE LOCAL'
if ($answer -cne 'REINSTALAR ACE LOCAL') { throw 'Cancelado sem modificar dados.' }
& (Join-Path $PSScriptRoot 'docker-backup.ps1')
if ($LASTEXITCODE -ne 0) { throw 'Backup falhou. Reset cancelado.' }
# Seguranca: exige arquivo nao vazio e recente para nao apagar por engano.
$latest = Get-ChildItem (Join-Path $root 'backups') -Filter 'ace-mysql-*.sql' | Sort-Object LastWriteTime -Descending | Select-Object -First 1
if (-not $latest -or $latest.Length -lt 100) { throw 'Nao foi confirmado um dump SQL valido. Reset cancelado.' }
Write-Host "Backup confirmado: $($latest.FullName)" -ForegroundColor Green
# A migration e a seed abaixo devem ser as DA NOVA VERSAO do projeto.
docker compose -f compose.yaml build app web
if ($LASTEXITCODE -ne 0) { throw 'Build falhou: reset cancelado. O backup foi preservado.' }
$answer2 = Read-Host 'Confirme novamente digitando APAGAR APENAS ACE_DB'
if ($answer2 -cne 'APAGAR APENAS ACE_DB') { throw 'Cancelado.' }
docker compose -f compose.yaml stop app queue scheduler
if ($LASTEXITCODE -ne 0) { throw 'Nao foi possivel parar os workers. Reset cancelado.' }
# Nao remove volumes: migrate:fresh e usado apenas pela autorizacao explicita desta operacao.
docker compose -f compose.yaml run --rm -e ACE_DEMO_SEED_ENABLED=true -e ACE_DEMO_DEV_PASSWORD='AceDev!Local2026#K7' -e ACE_DEMO_USER_PASSWORD='AceUser!Local2026#Q9' migrate php artisan migrate:fresh --seed --force
if ($LASTEXITCODE -ne 0) { throw 'Reinstalacao falhou. Preserve o backup e consulte o README.' }
docker compose -f compose.yaml up -d
if ($LASTEXITCODE -ne 0) { throw 'O banco foi reinstalado, mas houve falha ao iniciar servicos. Consulte Docker.' }
Write-Host 'Instalacao limpa concluida. Contas de desenvolvimento descritas no README.' -ForegroundColor Green
