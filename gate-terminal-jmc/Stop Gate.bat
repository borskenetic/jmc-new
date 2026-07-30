@echo off
setlocal EnableExtensions
title JMC Gate — Stop
cd /d "%~dp0"

echo.
echo  Stopping JMC Gate server...
echo.

for /f "tokens=5" %%a in ('netstat -ano ^| findstr "LISTENING" ^| findstr ":9173"') do (
    taskkill /PID %%a /F >nul 2>&1
)

echo  Done. You can close this window.
echo  To start again, double-click "Start Gate.bat"
echo.
pause
