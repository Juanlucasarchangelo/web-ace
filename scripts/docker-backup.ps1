#Requires -Version 5.1
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $root
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupDir = Join-Path $root 'backups'
New-Item -ItemType Directory -Force -Path $backupDir | Out-Null
$dump = Join-Path $backupDir "ace-mysql-$stamp.sql"

# O dump e gerado dentro do container e copiado SEM conversao de encoding.
# MYSQL_PWD evita expor a senha como argumento da linha de comando.
docker compose -f compose.yaml exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysqldump --single-transaction --quick --routines --triggers -u"$MYSQL_USER" "$MYSQL_DATABASE" > /tmp/ace-backup.sql'
if ($LASTEXITCODE -ne 0) { throw 'Falha ao gerar dump SQL. Verifique os containers.' }
$containerId = (docker compose -f compose.yaml ps -q db).Trim()
if ($LASTEXITCODE -ne 0 -or -not $containerId) { throw 'Nao foi possivel localizar o container MySQL.' }
docker cp "${containerId}:/tmp/ace-backup.sql" $dump
if ($LASTEXITCODE -ne 0) { throw 'Nao foi possivel copiar o dump MySQL.' }
docker compose -f compose.yaml exec -T db rm -f /tmp/ace-backup.sql
if ($LASTEXITCODE -ne 0) { throw 'Nao foi possivel remover arquivo temporario do container.' }
Copy-Item '.env' (Join-Path $backupDir "ace-env-$stamp.txt")
Write-Host "Backup salvo em $backupDir. IMPORTANTE: .env contem chaves e senhas; armazene os arquivos de forma criptografada e fora deste computador." -ForegroundColor Yellow
