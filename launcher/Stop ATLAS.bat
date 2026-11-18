@echo off
title ATLAS - stopping
set XAMPP=C:\xampp
echo Stopping ATLAS worker, Apache and MySQL (Ollama keeps running in the tray)...
taskkill /FI "WINDOWTITLE eq ATLAS worker*" /T /F >NUL 2>&1
call "%XAMPP%\apache_stop.bat" >NUL 2>&1
call "%XAMPP%\mysql_stop.bat" >NUL 2>&1
echo Done.
timeout /t 2 >NUL
