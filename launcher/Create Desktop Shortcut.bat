@echo off
REM Creates an "ATLAS" shortcut on your desktop that starts everything and opens ATLAS as an app.
set TARGET=%~dp0Start ATLAS.bat
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$s=(New-Object -ComObject WScript.Shell).CreateShortcut([Environment]::GetFolderPath('Desktop')+'\ATLAS.lnk');" ^
  "$s.TargetPath='%TARGET%'; $s.WorkingDirectory='%~dp0'; $s.WindowStyle=7; $s.IconLocation='%SystemRoot%\System32\imageres.dll,1023'; $s.Description='ATLAS - Mnemo offline knowledge engine'; $s.Save()"
echo Shortcut "ATLAS" created on your desktop.
pause
