@echo off
setlocal
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\reset-local.ps1"
if errorlevel 1 (
 echo Reinstalacao interrompida. Veja o erro acima.
 pause
 exit /b 1
)
pause
