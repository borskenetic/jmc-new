@echo off
setlocal EnableExtensions
cd /d "%~dp0"

if not exist "config.json" (
    msg * "Run Setup Gate (First Time).bat first."
    exit /b 1
)

echo Syncing with server...
call npm run sync
echo.
pause
