@echo off
setlocal
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\docker-start.ps1"
if errorlevel 1 (
  echo.
  echo Falha ao iniciar. Consulte a mensagem acima.
  pause
  exit /b 1
)
echo.
echo Abra: http://127.0.0.1:8088
pause
