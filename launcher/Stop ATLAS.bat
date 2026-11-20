@echo off
title ATLAS - stopping
REM Stops the hidden background parts: learner, AI (Ollama), MySQL and Apache.
set "XAMPP=C:\xampp"
set "APP=%~dp0.."
echo Stopping ATLAS (learner, AI, memory, web server)...
taskkill /FI "WINDOWTITLE eq ATLAS worker*" /T /F >NUL 2>&1
if exist "%APP%\storage\worker.json" powershell -NoProfile -Command "$j = Get-Content '%APP%\storage\worker.json' -Raw | ConvertFrom-Json; $p = Get-Process -Id $j.pid -ErrorAction SilentlyContinue; if ($p -and $p.ProcessName -eq 'php') { Stop-Process -Id $j.pid -Force }"
taskkill /IM ollama.exe /F >NUL 2>&1
REM Save Mnemo's memory cleanly, then stop the web server
"%XAMPP%\mysql\bin\mysqladmin.exe" -u root --host=127.0.0.1 shutdown >NUL 2>&1 || call "%XAMPP%\mysql_stop.bat" >NUL 2>&1
call "%XAMPP%\apache_stop.bat" >NUL 2>&1
echo Done. It is now safe to shut down the PC.
timeout /t 3 >NUL
