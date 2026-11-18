@echo off
REM Deep Freeze resets the desktop on every restart, so run this again after each restart.
set "TARGET=%~dp02 - Start ATLAS.bat"
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$s=(New-Object -ComObject WScript.Shell).CreateShortcut([Environment]::GetFolderPath('Desktop')+'\ATLAS.lnk');" ^
  "$s.TargetPath='%TARGET%'; $s.WorkingDirectory='%~dp0'; $s.WindowStyle=7; $s.IconLocation='%SystemRoot%\System32\imageres.dll,1023'; $s.Description='ATLAS Portable'; $s.Save()"
echo Shortcut "ATLAS" created on your desktop.
timeout /t 3 >nul
