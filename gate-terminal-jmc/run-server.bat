@echo off
title JMC Gate Server — DO NOT CLOSE
cd /d "%~dp0"

REM Keep this window minimized when launched from Start Gate.bat / shell:startup.
if /I not "%~1"=="verbose" (
    powershell -NoProfile -Command "Add-Type -Name W -Namespace N -MemberDefinition '[DllImport(\"user32.dll\")] public static extern bool ShowWindow(IntPtr h,int n);'; $p = Get-Process -Id $PID; [void][N.W]::ShowWindow($p.MainWindowHandle, 2)" >nul 2>&1
)

npm start

echo.
echo  The gate server stopped. Press any key to close...
pause >nul
