@echo off
setlocal
title ATLAS - stopping
set "ROOT=%~dp0"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"
set "XAMPP=%ROOT%\xampp"
set "APP=%XAMPP%\htdocs\atlas"
echo Stopping (learner, AI, memory, web server)...
if exist "%APP%\storage\worker.json" powershell -NoProfile -Command "$j = Get-Content '%APP%\storage\worker.json' -Raw | ConvertFrom-Json; $p = Get-Process -Id $j.pid -ErrorAction SilentlyContinue; if ($p -and $p.ProcessName -eq 'php') { Stop-Process -Id $j.pid -Force }"
REM Stop only the AI started from THIS folder (another ATLAS on this PC keeps its AI)
powershell -NoProfile -Command "Get-Process ollama -ErrorAction SilentlyContinue | Where-Object { $_.Path -like '%ROOT%\*' } | Stop-Process -Force"
"%XAMPP%\mysql\bin\mysqladmin.exe" -u root --port=3307 --host=127.0.0.1 shutdown >nul 2>&1
powershell -NoProfile -Command "Get-Process httpd -ErrorAction SilentlyContinue | Where-Object { $_.Path -like '%XAMPP%\*' } | Stop-Process -Force"
echo Done.
timeout /t 2 >nul
