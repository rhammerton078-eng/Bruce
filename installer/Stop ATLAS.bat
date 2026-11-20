@echo off
setlocal
title ATLAS - stopping
set "ROOT=%~dp0"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"
set "XAMPP=%ROOT%\xampp"
echo Stopping ATLAS (learner, AI, web server, memory)...
taskkill /FI "WINDOWTITLE eq ATLAS worker*" /T /F >nul 2>&1
taskkill /IM ollama.exe /F >nul 2>&1
"%XAMPP%\mysql\bin\mysqladmin.exe" -u root --port=3307 --host=127.0.0.1 shutdown >nul 2>&1
pushd "%XAMPP%"
call apache_stop.bat >nul 2>&1
popd
echo Done.
timeout /t 2 >nul
