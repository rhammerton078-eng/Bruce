@echo off
setlocal EnableExtensions
title ATLAS - starting
REM Installed ATLAS (ATLAS-Setup.exe). Everything lives in this folder:
REM   xampp\ (web server + MySQL + ATLAS)   ollama\ (AI program)   models\ (AI brain)
REM It uses its own ports (web 8765, MySQL 3307) so it never clashes with another XAMPP.
set "ROOT=%~dp0"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"
cd /d "%ROOT%"
set "XAMPP=%ROOT%\xampp"
set "WEB_PORT=8765"
set "MYSQL_PORT=3307"
set "URL=http://localhost:%WEB_PORT%/atlas/"
set "OLLAMA_MODELS=%ROOT%\models"
set "OLLAMA_KEEP_ALIVE=30m"
set "OLLAMA_FLASH_ATTENTION=1"
set "OLLAMA_KV_CACHE_TYPE=q8_0"

echo.
echo   ATLAS - Mnemo AI
echo   -------------------------------------------------------------

REM First run, or the folder moved: let XAMPP fix its own paths
set "LAST="
if exist "%ROOT%\.location" set /p LAST=<"%ROOT%\.location"
if /I not "%LAST%"=="%ROOT%" (
  echo   Preparing the web server for this folder ^(first start only^)...
  pushd "%XAMPP%"
  echo 1| call setup_xampp.bat >nul 2>&1
  popd
  >"%ROOT%\.location" echo %ROOT%
)

netstat -ano | findstr /R /C:":%MYSQL_PORT% .*LISTENING" >NUL
if errorlevel 1 ( echo   Starting memory ^(MySQL^)... & start "ATLAS MySQL" /MIN /D "%XAMPP%" mysql_start.bat ) else ( echo   [ok] memory running )
netstat -ano | findstr /R /C:":%WEB_PORT% .*LISTENING" >NUL
if errorlevel 1 ( echo   Starting web server... & start "ATLAS Apache" /MIN /D "%XAMPP%" apache_start.bat ) else ( echo   [ok] web server running )

taskkill /IM "ollama app.exe" /F >nul 2>&1
taskkill /IM ollama.exe /F >nul 2>&1
timeout /t 2 >nul
echo   Starting the AI...
start "ATLAS Ollama" /MIN "%ROOT%\ollama\ollama.exe" serve

tasklist /V /FI "WINDOWTITLE eq ATLAS worker*" | find /I "php.exe" >NUL
if errorlevel 1 start "ATLAS worker" /MIN /D "%XAMPP%\htdocs\atlas" "%XAMPP%\php\php.exe" worker\ingest.php --watch

echo   Opening ATLAS...
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
