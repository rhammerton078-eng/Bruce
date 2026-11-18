@echo off
setlocal EnableExtensions
title ATLAS Portable - starting
set "ROOT=%~dp0"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"
cd /d "%ROOT%"
set "XAMPP=%ROOT%\xampp"
set "OLLAMA_MODELS=%ROOT%\models"
set "OLLAMA_KEEP_ALIVE=30m"
set "URL=http://localhost/atlas/"
REM Port of YOUR MySQL in D:\ATLAS\xampp. Use 3307 when the PC already runs another MySQL on 3306
REM (school / lab PCs). Must match "port=" in xampp\mysql\bin\my.ini and 'port' in atlas\config.php.
set "MYSQL_PORT=3307"
REM If you changed Apache to port 8080, use:  set "URL=http://localhost:8080/atlas/"

echo.
echo   ATLAS Portable  -  everything runs from %ROOT%
echo   -------------------------------------------------------------

if not exist "%XAMPP%\xampp-control.exe" ( echo   [!] XAMPP not found in %XAMPP%. Run "1 - Setup Portable ATLAS.bat" first. & pause & exit /b 1 )
if not exist "%ROOT%\ollama\ollama.exe" ( echo   [!] Portable Ollama not found. Run "1 - Setup Portable ATLAS.bat" first. & pause & exit /b 1 )

REM USB drives can get a different letter on another PC: let XAMPP fix its paths
set "LAST="
if exist "%ROOT%\.drive" set /p LAST=<"%ROOT%\.drive"
if /I not "%LAST%"=="%ROOT:~0,2%" (
  echo   Drive letter changed ^(%LAST% -^> %ROOT:~0,2%^): updating XAMPP paths...
  pushd "%XAMPP%"
  echo 1| call setup_xampp.bat >nul 2>&1
  popd
  echo %ROOT:~0,2%> "%ROOT%\.drive"
)

REM 1) MySQL  (Mnemo's memory - stored in %XAMPP%\mysql\data, i.e. on THIS drive)
REM Check OUR port only - another MySQL (e.g. the school's on 3306) is left alone
netstat -ano | findstr /R /C:":%MYSQL_PORT% .*LISTENING" >NUL
if errorlevel 1 ( echo   Starting MySQL on port %MYSQL_PORT%... & start "ATLAS MySQL" /MIN /D "%XAMPP%" mysql_start.bat ) else ( echo   MySQL already running on port %MYSQL_PORT% )

REM 2) Apache
netstat -ano | findstr /R /C:":80 .*LISTENING" >NUL
if errorlevel 1 ( echo   Starting Apache... & start "ATLAS Apache" /MIN /D "%XAMPP%" apache_start.bat ) else ( echo   Apache already running )

REM 3) Portable Ollama (stop an installed C: copy so models come from this folder)
taskkill /IM "ollama app.exe" /F >nul 2>&1
tasklist /FI "IMAGENAME eq ollama.exe" | find /I "ollama.exe" >NUL
if not errorlevel 1 ( taskkill /IM ollama.exe /F >nul 2>&1 & timeout /t 2 >nul )
echo   Starting Ollama ^(models: %OLLAMA_MODELS%^)...
start "ATLAS Ollama" /MIN "%ROOT%\ollama\ollama.exe" serve

REM 4) Background learner
tasklist /V /FI "WINDOWTITLE eq ATLAS worker*" | find /I "php.exe" >NUL
if errorlevel 1 start "ATLAS worker" /MIN /D "%XAMPP%\htdocs\atlas" "%XAMPP%\php\php.exe" worker\ingest.php --watch

REM 5) Open ATLAS as an app window
echo   Waiting for ATLAS...
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
