@echo off
setlocal EnableExtensions
cd /d "%~dp0"

set "PORT=9173"
set "GATE_URL=http://127.0.0.1:%PORT%"

REM Read port from config.json if PowerShell is available
for /f "usebackq delims=" %%p in (`powershell -NoProfile -Command "try { (Get-Content 'config.json' -Raw | ConvertFrom-Json).port } catch { 9173 }" 2^>nul`) do set "PORT=%%p"
set "GATE_URL=http://127.0.0.1:%PORT%"
if /I "%~1"=="test" set "GATE_URL=%GATE_URL%?test=1"

REM --- Quick checks ---
where node >nul 2>&1
if errorlevel 1 (
    msg * "Node.js is not installed. Ask IT to run Setup Gate (First Time).bat"
    exit /b 1
)

if not exist "config.json" (
    msg * "Gate is not set up yet. Ask IT to run Setup Gate (First Time).bat"
    exit /b 1
)

if not exist "node_modules\" (
    msg * "Gate files missing. Ask IT to run Setup Gate (First Time).bat"
    exit /b 1
)

REM --- Is server already running? ---
set "SERVER_RUNNING=0"
powershell -NoProfile -Command "if ((Test-NetConnection -ComputerName 127.0.0.1 -Port %PORT% -WarningAction SilentlyContinue).TcpTestSucceeded) { exit 0 } else { exit 1 }" >nul 2>&1
if not errorlevel 1 set "SERVER_RUNNING=1"

if "%SERVER_RUNNING%"=="0" (
    REM Minimized so the black console does not cover the scan screen on unattended kiosks
    start "JMC Gate Server — DO NOT CLOSE" /MIN /D "%~dp0" "%~dp0run-server.bat"
    timeout /t 4 /nobreak >nul
)

REM --- Open scan screen (Edge first, then Chrome), fullscreen/kiosk-friendly ---
where msedge >nul 2>&1
if not errorlevel 1 (
    start "" msedge --kiosk "%GATE_URL%" --edge-kiosk-type=fullscreen --no-first-run --disable-features=TranslateUI
    goto :bring_browser_forward
)

where chrome >nul 2>&1
if not errorlevel 1 (
    start "" chrome --kiosk "%GATE_URL%" --no-first-run --disable-features=TranslateUI
    goto :bring_browser_forward
)

start "" "%GATE_URL%"

:bring_browser_forward
REM Give the browser a moment, then force it above the console window
timeout /t 2 /nobreak >nul
powershell -NoProfile -Command ^
  "$names = @('msedge','chrome','ApplicationFrameHost');" ^
  "foreach ($n in $names) {" ^
  "  Get-Process $n -ErrorAction SilentlyContinue | ForEach-Object {" ^
  "    try {" ^
  "      Add-Type -Name W -Namespace N -MemberDefinition '[DllImport(\"user32.dll\")] public static extern bool SetForegroundWindow(IntPtr h); [DllImport(\"user32.dll\")] public static extern bool ShowWindow(IntPtr h, int n);' -ErrorAction SilentlyContinue;" ^
  "      [void][N.W]::ShowWindow($_.MainWindowHandle, 9);" ^
  "      [void][N.W]::SetForegroundWindow($_.MainWindowHandle);" ^
  "    } catch {}" ^
  "  }" ^
  "}" >nul 2>&1

exit /b 0
