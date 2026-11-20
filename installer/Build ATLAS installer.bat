@echo off
setlocal EnableExtensions
title ATLAS - build ATLAS-Setup.exe
REM =====================================================================
REM  Builds ATLAS-Setup.exe: ONE installer with the web server, MySQL,
REM  ATLAS, the AI program (Ollama) and the AI models. Run it on the PC
REM  where ATLAS already works (C:\xampp + C:\ATLAS-AI).
REM  Needs Inno Setup 7 or 6 (free): https://jrsoftware.org/isdl.php
REM  Output: 1 = C:\ATLAS-build\base   (used by the "Download my AI" button in ATLAS)
REM          2 = C:\ATLAS-build\output (a complete copy of this PC's AI)
REM =====================================================================
set "HERE=%~dp0"
set "SRC_XAMPP=C:\xampp"
set "SRC_AI=C:\ATLAS-AI"
set "BUILD=C:\ATLAS-build"
set "STAGE=%BUILD%\stage"
set "ISCC="
for %%I in ("%ProgramFiles%\Inno Setup 7\ISCC.exe" "%ProgramFiles(x86)%\Inno Setup 7\ISCC.exe" "%ProgramFiles%\Inno Setup 6\ISCC.exe" "%ProgramFiles(x86)%\Inno Setup 6\ISCC.exe" "%LOCALAPPDATA%\Programs\Inno Setup 7\ISCC.exe" "%LOCALAPPDATA%\Programs\Inno Setup 6\ISCC.exe") do if not defined ISCC if exist "%%~I" set "ISCC=%%~I"

echo.
echo   Build the ATLAS installer
echo   -------------------------------------------------------------
if not defined ISCC ( echo   [!] Inno Setup is not installed. Get it free from jrsoftware.org/isdl.php & pause & exit /b 1 )
echo   Using %ISCC%
if not exist "%SRC_XAMPP%\htdocs\atlas\index.php" ( echo   [!] ATLAS not found in %SRC_XAMPP%\htdocs\atlas & pause & exit /b 1 )
if not exist "%SRC_AI%\ollama\ollama.exe" ( echo   [!] Ollama not found in %SRC_AI%\ollama & pause & exit /b 1 )
if not exist "%SRC_AI%\models\manifests" ( echo   [!] AI models not found in %SRC_AI%\models & pause & exit /b 1 )

echo.
echo   Which Mnemo should the installer contain?
echo     1 = Base for the "Download my AI" button  (empty memory - do this once)
echo     2 = MY Mnemo       (everything it has learned on this PC)
choice /C 12 /N /M "  Press 1 or 2: "
if errorlevel 2 ( set "MODE=keep" ) else ( set "MODE=newborn" )

if "%MODE%"=="keep" (
  echo.
  echo   Stop MySQL now: XAMPP Control Panel ^> MySQL ^> Stop. Also stop "ATLAS worker".
  pause
  netstat -ano | findstr /R /C:":3306 .*LISTENING" >NUL
  if not errorlevel 1 ( echo   [!] MySQL is still running on port 3306. Stop it and run this again. & pause & exit /b 1 )
)

echo.
echo   [1/5] Cleaning %BUILD% ...
if exist "%STAGE%" rmdir /S /Q "%STAGE%"
mkdir "%STAGE%" 2>nul

echo   [2/5] Copying the web server and MySQL ...
robocopy "%SRC_XAMPP%" "%STAGE%\xampp" /E /NFL /NDL /NJH /NJS /NP /XF *.log /XD "%SRC_XAMPP%\htdocs" "%SRC_XAMPP%\mysql\data" "%SRC_XAMPP%\tomcat" "%SRC_XAMPP%\perl" "%SRC_XAMPP%\FileZillaFTP" "%SRC_XAMPP%\MercuryMail" "%SRC_XAMPP%\sendmail" "%SRC_XAMPP%\webdav" "%SRC_XAMPP%\tmp" "%SRC_XAMPP%\apache\logs" >nul
mkdir "%STAGE%\xampp\tmp" "%STAGE%\xampp\apache\logs" 2>nul
if "%MODE%"=="keep" (
  robocopy "%SRC_XAMPP%\mysql\data" "%STAGE%\xampp\mysql\data" /E /NFL /NDL /NJH /NJS /NP /XF *.err *.pid >nul
) else (
  robocopy "%SRC_XAMPP%\mysql\backup" "%STAGE%\xampp\mysql\data" /E /NFL /NDL /NJH /NJS /NP >nul
)

echo   [3/5] Copying ATLAS ...
set "A=%SRC_XAMPP%\htdocs\atlas"
if "%MODE%"=="keep" (
  robocopy "%A%" "%STAGE%\xampp\htdocs\atlas" /E /NFL /NDL /NJH /NJS /NP /XF worker.json worker.lock package.json /XD "%A%\.git" "%A%\docs\presentation" "%A%\storage\backups" "%A%\storage\exports" "%A%\storage\logs" >nul
) else (
  robocopy "%A%" "%STAGE%\xampp\htdocs\atlas" /E /NFL /NDL /NJH /NJS /NP /XF cards.json config.local.php allow_lan worker.json worker.lock package.json /XD "%A%\.git" "%A%\docs\presentation" "%A%\storage\uploads" "%A%\storage\backups" "%A%\storage\exports" "%A%\storage\cards" "%A%\storage\inbox" "%A%\storage\logs" >nul
)
for %%D in (uploads backups exports cards inbox logs) do mkdir "%STAGE%\xampp\htdocs\atlas\storage\%%D" 2>nul
"%SRC_XAMPP%\php\php.exe" "%HERE%make-config.php" "%A%\config.local.php" "%STAGE%\xampp\htdocs\atlas\config.local.php" %MODE%

echo   [4/5] Setting ATLAS's own ports (web 8765, MySQL 3307) ...
powershell -NoProfile -Command ^
  "$f='%STAGE%\xampp\mysql\bin\my.ini'; (Get-Content $f) -replace '^port\s*=\s*3306','port=3307' | Set-Content $f;" ^
  "$f='%STAGE%\xampp\apache\conf\httpd.conf'; (Get-Content $f) -replace '^Listen 80$','Listen 8765' -replace '^ServerName localhost:80$','ServerName localhost:8765' | Set-Content $f;" ^
  "$f='%STAGE%\xampp\apache\conf\extra\httpd-ssl.conf'; if (Test-Path $f) { (Get-Content $f) -replace '443','8766' | Set-Content $f }"
copy /Y "%HERE%Start ATLAS.bat" "%STAGE%\" >nul
copy /Y "%HERE%Stop ATLAS.bat" "%STAGE%\" >nul
copy /Y "%A%\assets\atlas.ico" "%STAGE%\" >nul
copy /Y "%A%\NOTICE.txt" "%STAGE%\" >nul

echo   [5/5] Copying the AI (Ollama + models, about 12 GB) ...
robocopy "%SRC_AI%\ollama" "%STAGE%\ollama" /E /NFL /NDL /NJH /NJS /NP >nul
robocopy "%SRC_AI%\models" "%STAGE%\models" /E /NFL /NDL /NJH /NJS /NP >nul

if "%MODE%"=="keep" ( echo   You can start MySQL again now. )
echo.
echo   Compiling the installer (this takes a while)...
REM Newborn = the base for "Download my AI" in ATLAS; MY Mnemo = a complete copy of this PC's AI
if "%MODE%"=="keep" ( set "OUT=%BUILD%\output" ) else ( set "OUT=%BUILD%\base" )
"%ISCC%" /DStage="%STAGE%" /O"%OUT%" "%HERE%ATLAS.iss"
if errorlevel 1 ( echo   [!] Inno Setup failed - see the messages above. & pause & exit /b 1 )
echo.
echo   -------------------------------------------------------------
echo   [ok] Done:  %OUT%\ATLAS-Setup.exe  (+ ATLAS-Setup-*.bin)
echo   Keep ALL files from that folder together when you copy them.
echo   -------------------------------------------------------------
if "%MODE%"=="newborn" echo   The "Download my AI" button in ATLAS is now ready to use.
explorer "%OUT%"
pause
