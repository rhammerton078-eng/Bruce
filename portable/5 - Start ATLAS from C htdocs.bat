@echo off
setlocal EnableExtensions
title ATLAS - start from C:\xampp\htdocs (memory stays on D:)
REM =====================================================================
REM  Use this when ATLAS must run from the school's XAMPP in C:\xampp\htdocs.
REM  C: is frozen (Deep Freeze), so nothing important is stored there:
REM    C:\xampp\htdocs\atlas  is only a JUNCTION (doorway) to  D:\ATLAS\xampp\htdocs\atlas
REM    Mnemo's memory = YOUR MySQL in D:\ATLAS\xampp on port 3307
REM    Qwen + Ollama  = D:\ATLAS\ollama and D:\ATLAS\models
REM  Run this after every restart (it re-creates the junction). Run as Administrator.
REM =====================================================================
set "ROOT=%~dp0"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"
set "CX=C:\xampp"
set "APP=%ROOT%\xampp\htdocs\atlas"
set "MYSQL_PORT=3307"
set "OLLAMA_MODELS=%ROOT%\models"
set "OLLAMA_KEEP_ALIVE=30m"
set "URL=http://localhost/atlas/"

echo.
echo   ATLAS from C:\xampp\htdocs   (everything important stays in %ROOT%)
echo   -------------------------------------------------------------
if not exist "%CX%\apache_start.bat" ( echo   [!] School XAMPP not found in %CX%. & pause & exit /b 1 )
if not exist "%APP%\index.php"      ( echo   [!] ATLAS not found in %APP%. Extract ATLAS-full.zip into D:\ first. & pause & exit /b 1 )
if not exist "%ROOT%\ollama\ollama.exe" ( echo   [!] Built-in Ollama not found. Run "0 - Build in the AI (Ollama + Qwen).bat". & pause & exit /b 1 )

REM 1) Junction C:\xampp\htdocs\atlas  ->  D:\ATLAS\xampp\htdocs\atlas
if exist "%CX%\htdocs\atlas" (
  fsutil reparsepoint query "%CX%\htdocs\atlas" >nul 2>&1
  if errorlevel 1 (
    echo   Found a normal folder C:\xampp\htdocs\atlas - renaming it to atlas-old-C
    if exist "%CX%\htdocs\atlas-old-C" rmdir /S /Q "%CX%\htdocs\atlas-old-C"
    ren "%CX%\htdocs\atlas" atlas-old-C
  ) else (
    rmdir "%CX%\htdocs\atlas"
  )
)
mklink /J "%CX%\htdocs\atlas" "%APP%" >nul
if errorlevel 1 ( echo   [!] Could not create the junction. Right-click this file and choose "Run as administrator". & pause & exit /b 1 )
echo   [ok] C:\xampp\htdocs\atlas  -^>  %APP%

REM 2) YOUR MySQL (memory) from D: on port 3307 - the school's MySQL on 3306 is left alone
netstat -ano | findstr /R /C:":%MYSQL_PORT% .*LISTENING" >NUL
if errorlevel 1 ( echo   Starting your MySQL on port %MYSQL_PORT%... & start "ATLAS MySQL" /MIN /D "%ROOT%\xampp" mysql_start.bat ) else ( echo   [ok] Your MySQL already running on port %MYSQL_PORT% )

REM 3) The school's Apache on C: (serves http://localhost)
netstat -ano | findstr /R /C:":80 .*LISTENING" >NUL
if errorlevel 1 ( echo   Starting Apache from C:\xampp... & start "ATLAS Apache" /MIN /D "%CX%" apache_start.bat ) else ( echo   [ok] Apache already running )

REM 4) Built-in Ollama with Qwen from D:
taskkill /IM "ollama app.exe" /F >nul 2>&1
tasklist /FI "IMAGENAME eq ollama.exe" | find /I "ollama.exe" >NUL
if not errorlevel 1 ( taskkill /IM ollama.exe /F >nul 2>&1 & timeout /t 2 >nul )
echo   Starting built-in Ollama (models: %OLLAMA_MODELS%)...
start "ATLAS Ollama" /MIN "%ROOT%\ollama\ollama.exe" serve

REM 5) Background learner
tasklist /V /FI "WINDOWTITLE eq ATLAS worker*" | find /I "php.exe" >NUL
if errorlevel 1 start "ATLAS worker" /MIN /D "%APP%" "%ROOT%\xampp\php\php.exe" worker\ingest.php --watch

REM 6) Open ATLAS
echo   Waiting for ATLAS...
for /L %%i in (1,1,40) do (
  curl -s -o NUL -m 2 %URL%setup.php && goto :open
  timeout /t 1 >NUL
)
:open
start "" %URL%
echo   Done. ATLAS is at %URL%   (memory and AI are on %ROOT:~0,2%)
timeout /t 4 >nul
exit /b 0
