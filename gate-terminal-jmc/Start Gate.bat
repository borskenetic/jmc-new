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

REM --- Find Edge / Chrome by full path (they are often NOT on PATH) ---
set "BROWSER="
set "BROWSER_KIND="

if exist "%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" (
    set "BROWSER=%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe"
    set "BROWSER_KIND=edge"
)
if not defined BROWSER if exist "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe" (
    set "BROWSER=%ProgramFiles%\Microsoft\Edge\Application\msedge.exe"
    set "BROWSER_KIND=edge"
)
if not defined BROWSER if exist "%ProgramFiles%\Google\Chrome\Application\chrome.exe" (
    set "BROWSER=%ProgramFiles%\Google\Chrome\Application\chrome.exe"
    set "BROWSER_KIND=chrome"
)
if not defined BROWSER if exist "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" (
    set "BROWSER=%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe"
    set "BROWSER_KIND=chrome"
)
if not defined BROWSER if exist "%LocalAppData%\Google\Chrome\Application\chrome.exe" (
    set "BROWSER=%LocalAppData%\Google\Chrome\Application\chrome.exe"
    set "BROWSER_KIND=chrome"
)

REM --- Open scan screen fullscreen (true kiosk; 1440x900 landscape OK) ---
if /I "%BROWSER_KIND%"=="edge" (
    start "" "%BROWSER%" --kiosk "%GATE_URL%" --edge-kiosk-type=fullscreen --no-first-run --disable-features=TranslateUI --check-for-update-interval=31536000
    exit /b 0
)

if /I "%BROWSER_KIND%"=="chrome" (
    start "" "%BROWSER%" --kiosk "%GATE_URL%" --no-first-run --disable-features=TranslateUI --check-for-update-interval=31536000
    exit /b 0
)

REM Last resort: default browser, then force maximize (SW_MAXIMIZE=3, NOT restore=9)
start "" "%GATE_URL%"
timeout /t 3 /nobreak >nul
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\focus-gate-browser.ps1" >nul 2>&1
if errorlevel 1 (
    powershell -NoProfile -Command ^
      "Add-Type -Name W -Namespace N -MemberDefinition '[DllImport(\"user32.dll\")] public static extern bool ShowWindow(IntPtr h,int n); [DllImport(\"user32.dll\")] public static extern bool SetForegroundWindow(IntPtr h);' -ErrorAction SilentlyContinue;" ^
      "$names=@('msedge','chrome','ApplicationFrameHost','iexplore');" ^
      "foreach($n in $names){ Get-Process $n -EA SilentlyContinue | Where-Object { $_.MainWindowHandle -ne 0 } | ForEach-Object { [void][N.W]::ShowWindow($_.MainWindowHandle,3); [void][N.W]::SetForegroundWindow($_.MainWindowHandle) } }" >nul 2>&1
)

exit /b 0
