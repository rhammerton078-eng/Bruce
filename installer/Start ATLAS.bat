@echo off
setlocal EnableExtensions
title ATLAS - starting
REM Installed ATLAS (ATLAS-Setup.exe). Everything lives in this folder:
REM   xampp\ (web server + MySQL + ATLAS)   ollama\ (AI program)   models\ (AI brain)
REM Uses its own ports (web 8765, MySQL 3307) so it never clashes with another XAMPP.
REM The AI, memory, web server and learner run hidden; ATLAS shows their status (bottom right).
set "ROOT=%~dp0"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"
cd /d "%ROOT%"
set "XAMPP=%ROOT%\xampp"
set "APP=%XAMPP%\htdocs\atlas"
set "LOGS=%APP%\storage\logs"
set "WEB_PORT=8765"
set "MYSQL_PORT=3307"
set "URL=http://localhost:%WEB_PORT%/atlas/"
set "OLLAMA_MODELS=%ROOT%\models"
set "OLLAMA_KEEP_ALIVE=30m"
set "OLLAMA_FLASH_ATTENTION=1"
set "OLLAMA_KV_CACHE_TYPE=q8_0"
mkdir "%LOGS%" 2>nul

echo.
echo   Starting your AI...
echo   -------------------------------------------------------------

REM First start, or the folder moved: let XAMPP fix its own paths
set "LAST="
if exist "%ROOT%\.location" set /p LAST=<"%ROOT%\.location"
if /I not "%LAST%"=="%ROOT%" (
  echo   Preparing for this folder ^(first start only^)...
  pushd "%XAMPP%"
  echo 1| call setup_xampp.bat >nul 2>&1
  popd
  >"%ROOT%\.location" echo %ROOT%
)

netstat -ano | findstr /R /C:":%MYSQL_PORT% .*LISTENING" >NUL
if errorlevel 1 ( echo   Starting memory... & powershell -NoProfile -Command "Start-Process -FilePath '%XAMPP%\mysql\bin\mysqld.exe' -ArgumentList '--defaults-file=%XAMPP%\mysql\bin\my.ini','--standalone' -WorkingDirectory '%XAMPP%' -WindowStyle Hidden" )
netstat -ano | findstr /R /C:":%WEB_PORT% .*LISTENING" >NUL
if errorlevel 1 ( echo   Starting web server... & powershell -NoProfile -Command "Start-Process -FilePath '%XAMPP%\apache\bin\httpd.exe' -WorkingDirectory '%XAMPP%' -WindowStyle Hidden" )

REM An AI that is already running on this PC (port 11434) is shared, never stopped
netstat -ano | findstr /R /C:":11434 .*LISTENING" >NUL
if errorlevel 1 ( echo   Starting the AI... & powershell -NoProfile -Command "Start-Process -FilePath '%ROOT%\ollama\ollama.exe' -ArgumentList 'serve' -WindowStyle Hidden -RedirectStandardOutput '%LOGS%\ollama-out.log' -RedirectStandardError '%LOGS%\ollama.log'" ) else ( echo   [ok] AI already running )
for /L %%i in (1,1,20) do (
  netstat -ano | findstr /R /C:":11434 .*LISTENING" >NUL && goto :aiup
  timeout /t 1 >NUL
)
:aiup
REM Make sure Mnemo's own model exists in that AI
if exist "%APP%\ai\Modelfile" "%ROOT%\ollama\ollama.exe" create mnemo:7b -f "%APP%\ai\Modelfile" >nul 2>&1

REM First start: database, the memory that came with it, and the AI's name
"%XAMPP%\php\php.exe" "%APP%\tools\first-run.php" "%ROOT%\package"

REM Background learner (one at a time; it exits by itself if one is already running)
powershell -NoProfile -Command "Start-Process -FilePath '%XAMPP%\php\php.exe' -ArgumentList 'worker\ingest.php','--watch' -WorkingDirectory '%APP%' -WindowStyle Hidden -RedirectStandardOutput '%LOGS%\worker-out.log' -RedirectStandardError '%LOGS%\worker-err.log'"

REM Started by the ATLAS app window: it shows ATLAS itself
if /I "%~1"=="nobrowser" exit /b 0

for /L %%i in (1,1,40) do (
  curl -s -o NUL -m 2 %URL%setup.php && goto :open
  timeout /t 1 >NUL
)
:open
set EDGE="%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe"
if not exist %EDGE% set EDGE="%ProgramFiles%\Microsoft\Edge\Application\msedge.exe"
set CHROME="%ProgramFiles%\Google\Chrome\Application\chrome.exe"
if exist %CHROME% ( start "" %CHROME% --app=%URL% --window-size=1600,950
) else if exist %EDGE% ( start "" %EDGE% --app=%URL% --window-size=1600,950
) else ( start "" %URL% )
exit /b 0
