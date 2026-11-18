@echo off
setlocal
title ATLAS Portable - stopping
set "ROOT=%~dp0"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"
set "XAMPP=%ROOT%\xampp"
echo Stopping ATLAS (worker, Ollama, Apache, MySQL)...
taskkill /FI "WINDOWTITLE eq ATLAS worker*" /T /F >nul 2>&1
taskkill /IM ollama.exe /F >nul 2>&1
set "MYSQL_PORT=3307"
REM Stop only OUR MySQL (by port), never another MySQL running on this PC
"%XAMPP%\mysql\bin\mysqladmin.exe" -u root --port=%MYSQL_PORT% --host=127.0.0.1 shutdown >nul 2>&1
pushd "%XAMPP%"
call apache_stop.bat >nul 2>&1
popd
echo Done. It is now safe to restart the PC or unplug the USB drive.
timeout /t 3 >nul
