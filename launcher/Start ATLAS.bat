@echo off
title ATLAS - starting Mnemo
REM ===== Edit these if your installs are elsewhere =====
set XAMPP=C:\xampp
set URL=http://localhost/atlas/
REM =====================================================

echo.
echo   ATLAS . Mnemo  -  offline knowledge engine
echo   ------------------------------------------

REM 1) MySQL
tasklist /FI "IMAGENAME eq mysqld.exe" | find /I "mysqld.exe" >NUL
if errorlevel 1 (
  echo   Starting MySQL...
  start "" /MIN "%XAMPP%\mysql_start.bat"
) else ( echo   MySQL already running )

REM 2) Apache
tasklist /FI "IMAGENAME eq httpd.exe" | find /I "httpd.exe" >NUL
if errorlevel 1 (
  echo   Starting Apache...
  start "" /MIN "%XAMPP%\apache_start.bat"
) else ( echo   Apache already running )

REM 3) Ollama (local AI)
tasklist /FI "IMAGENAME eq ollama.exe" | find /I "ollama.exe" >NUL
if errorlevel 1 (
  where ollama >NUL 2>&1
  if errorlevel 1 (
    echo   [!] Ollama not found. Install it from ollama.com - Mnemo cannot learn without it.
  ) else (
    echo   Starting Ollama...
    start "" /MIN ollama serve
  )
) else ( echo   Ollama already running )

REM 4) Background learner (keeps learning while the window is closed)
tasklist /V /FI "WINDOWTITLE eq ATLAS worker*" | find /I "cmd.exe" >NUL
if errorlevel 1 start "ATLAS worker" /MIN "%~dp0..\worker\run-worker.bat"

REM 5) Wait for the web server, then open as an app window
echo   Waiting for ATLAS...
for /L %%i in (1,1,30) do (
  curl -s -o NUL -m 2 %URL%setup.php && goto :open
  timeout /t 1 >NUL
)
:open
set EDGE="%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe"
if not exist %EDGE% set EDGE="%ProgramFiles%\Microsoft\Edge\Application\msedge.exe"
set CHROME="%ProgramFiles%\Google\Chrome\Application\chrome.exe"
if exist %EDGE% ( start "" %EDGE% --app=%URL% --window-size=1600,950
) else if exist %CHROME% ( start "" %CHROME% --app=%URL% --window-size=1600,950
) else ( start "" %URL% )
exit /b 0
