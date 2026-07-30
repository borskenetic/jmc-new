@echo off
setlocal EnableExtensions
cd /d "%~dp0"

set "SHORTCUT=%USERPROFILE%\Desktop\Start Library Gate.lnk"
set "TARGET=%~dp0Start Gate.bat"
set "WORKDIR=%~dp0"

powershell -NoProfile -Command ^
  "$ws = New-Object -ComObject WScript.Shell; ^
   $s = $ws.CreateShortcut('%SHORTCUT%'); ^
   $s.TargetPath = '%TARGET%'; ^
   $s.WorkingDirectory = '%WORKDIR%'; ^
   $s.IconLocation = 'imageres.dll,109'; ^
   $s.Description = 'Start JMC library gate terminal'; ^
   $s.Save()"

if exist "%USERPROFILE%\Desktop\GUARD INSTRUCTIONS.txt" del "%USERPROFILE%\Desktop\GUARD INSTRUCTIONS.txt"
copy /Y "%~dp0GUARD INSTRUCTIONS.txt" "%USERPROFILE%\Desktop\GUARD INSTRUCTIONS.txt" >nul

echo.
echo  Desktop shortcut created: "Start Library Gate"
echo  Copied GUARD INSTRUCTIONS.txt to the desktop.
echo.
pause
