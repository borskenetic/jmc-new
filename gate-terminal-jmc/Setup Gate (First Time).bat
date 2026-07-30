@echo off
setlocal EnableExtensions
title JMC Gate — Setup (First Time)
cd /d "%~dp0"

echo.
echo  ============================================
echo   JMC Library Gate — First-time setup
echo  ============================================
echo.
echo  Run this ONCE when installing on a new PC.
echo  Ask IT if anything fails.
echo.

REM --- Node.js ---
where node >nul 2>&1
if errorlevel 1 (
    echo  [ERROR] Node.js is not installed.
    echo.
    echo  1. Open https://nodejs.org in a browser
    echo  2. Download the LTS version and install it
    echo  3. Restart this PC
    echo  4. Run this setup again
    echo.
    pause
    exit /b 1
)

for /f "tokens=*" %%v in ('node -v') do set NODEVER=%%v
echo  [OK] Node.js found: %NODEVER%
echo.

REM --- config.json ---
if not exist "config.json" (
    echo  Creating config.json from template...
    copy /Y "config.example.json" "config.json" >nul
    echo.
    echo  IMPORTANT: An admin must fill in config.json:
    echo    - cloud_url    = your school server address
    echo    - device_token = from Admin - Gate Devices
    echo.
    echo  Opening config.json now. Save and close Notepad when done.
    echo.
    pause
    notepad "config.json"
)

findstr /C:"paste_token_from_admin" "config.json" >nul 2>&1
if not errorlevel 1 (
    echo  [WARNING] device_token still looks like the placeholder.
    echo  Ask an admin for a token from: Admin - Gate Devices
    echo.
    choice /C YN /M "Open config.json to edit now"
    if not errorlevel 2 notepad "config.json"
)

echo  Installing app files (may take 1-2 minutes)...
echo.
call npm install
if errorlevel 1 (
    echo.
    echo  [ERROR] Install failed. Contact IT.
    echo  If you see "better-sqlite3" errors, IT may need to install
    echo  Visual Studio Build Tools, then run this setup again.
    echo.
    pause
    exit /b 1
)

echo.
echo  ============================================
echo   Setup complete!
echo  ============================================
echo.
echo  Next steps:
echo    1. Double-click "Start Gate.bat" each day
echo    2. Read "GUARD INSTRUCTIONS.txt" on the desktop
echo.
echo  Testing connection to server...
call npm run sync
echo.
pause
