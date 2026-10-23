#Requires -Version 5.1
# Compatibilidade com atalho antigo: esta edicao do ACE usa EXCLUSIVAMENTE Docker.
$ErrorActionPreference = 'Stop'
& (Join-Path $PSScriptRoot 'docker-start.ps1')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
