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
set "AUTO="
set "MODELS=lite"
if /I "%~2"=="full" set "MODELS=full"
if /I "%~1"=="newborn" ( set "AUTO=1" & set "MODE=newborn" & echo   Automatic build ^(started by the Download my AI button^) & goto :mode_set )
choice /C 12 /N /M "  Press 1 or 2: "
if errorlevel 2 ( set "MODE=keep" ) else ( set "MODE=newborn" )
echo.
choice /C YN /N /M "  Include picture understanding (qwen2.5vl, +3 GB)? Y/N: "
if errorlevel 2 ( set "MODELS=lite" ) else ( set "MODELS=full" )
:mode_ok
:mode_set
echo   AI models: %MODELS%


echo.
echo   [1/5] Reusing %STAGE% ^(only changed files are copied^) ...
mkdir "%STAGE%" 2>nul

echo   [2/5] Copying the web server and MySQL ...
robocopy "%SRC_XAMPP%" "%STAGE%\xampp" /MIR /NFL /NDL /NJH /NJS /NP /XF *.log /XD "%SRC_XAMPP%\htdocs" "%SRC_XAMPP%\mysql\data" "%SRC_XAMPP%\tomcat" "%SRC_XAMPP%\perl" "%SRC_XAMPP%\FileZillaFTP" "%SRC_XAMPP%\MercuryMail" "%SRC_XAMPP%\sendmail" "%SRC_XAMPP%\webdav" "%SRC_XAMPP%\tmp" "%SRC_XAMPP%\apache\logs" >nul
mkdir "%STAGE%\xampp\tmp" "%STAGE%\xampp\apache\logs" 2>nul
robocopy "%SRC_XAMPP%\mysql\backup" "%STAGE%\xampp\mysql\data" /MIR /NFL /NDL /NJH /NJS /NP >nul

echo   [3/5] Copying ATLAS ...
set "A=%SRC_XAMPP%\htdocs\atlas"
robocopy "%A%" "%STAGE%\xampp\htdocs\atlas" /MIR /NFL /NDL /NJH /NJS /NP /XF cards.json config.local.php allow_lan worker.json worker.lock package.json /XD "%A%\.git" "%A%\docs\presentation" "%A%\storage\uploads" "%A%\storage\backups" "%A%\storage\exports" "%A%\storage\cards" "%A%\storage\inbox" "%A%\storage\logs" >nul
for %%D in (uploads backups exports cards inbox logs) do mkdir "%STAGE%\xampp\htdocs\atlas\storage\%%D" 2>nul
"%SRC_XAMPP%\php\php.exe" "%HERE%make-config.php" "%A%\config.local.php" "%STAGE%\xampp\htdocs\atlas\config.local.php" newborn

echo   [4/5] Setting ATLAS's own ports (web 8765, MySQL 3307) ...
powershell -NoProfile -Command ^
  "$f='%STAGE%\xampp\mysql\bin\my.ini'; (Get-Content $f) -replace '^port\s*=\s*3306','port=3307' | Set-Content $f;" ^
  "$f='%STAGE%\xampp\apache\conf\httpd.conf'; (Get-Content $f) -replace '^Listen 80$','Listen 8765' -replace '^ServerName localhost:80$','ServerName localhost:8765' | Set-Content $f;" ^
  "$f='%STAGE%\xampp\apache\conf\extra\httpd-ssl.conf'; if (Test-Path $f) { (Get-Content $f) -replace '443','8766' | Set-Content $f }"
copy /Y "%HERE%Start ATLAS.bat" "%STAGE%\" >nul
copy /Y "%HERE%Stop ATLAS.bat" "%STAGE%\" >nul
copy /Y "%A%\assets\atlas.ico" "%STAGE%\" >nul
copy /Y "%A%\NOTICE.txt" "%STAGE%\" >nul

echo   Making the desktop app (its own window, no browser) ...
set "WV=%BUILD%\webview2"
if not exist "%WV%\lib\net462\Microsoft.Web.WebView2.WinForms.dll" (
  mkdir "%WV%" 2>nul
  curl -L -s --fail -o "%WV%\webview2.zip" "https://www.nuget.org/api/v2/package/Microsoft.Web.WebView2"
  tar -xf "%WV%\webview2.zip" -C "%WV%" 2>nul
)
set "CSC=%WINDIR%\Microsoft.NET\Framework64\v4.0.30319\csc.exe"
if exist "%WV%\lib\net462\Microsoft.Web.WebView2.WinForms.dll" if exist "%CSC%" (
  copy /Y "%WV%\lib\net462\Microsoft.Web.WebView2.Core.dll" "%STAGE%\" >nul
  copy /Y "%WV%\lib\net462\Microsoft.Web.WebView2.WinForms.dll" "%STAGE%\" >nul
  copy /Y "%WV%\runtimes\win-x64\native\WebView2Loader.dll" "%STAGE%\" >nul
  "%CSC%" /nologo /target:winexe /platform:x64 /optimize /win32icon:"%A%\assets\atlas.ico" /out:"%STAGE%\ATLAS.exe" /r:System.Windows.Forms.dll /r:System.Drawing.dll /r:"%STAGE%\Microsoft.Web.WebView2.Core.dll" /r:"%STAGE%\Microsoft.Web.WebView2.WinForms.dll" "%HERE%app\AtlasApp.cs"
)
if not exist "%WV%\MicrosoftEdgeWebView2RuntimeInstallerX64.exe" curl -L -s --fail -o "%WV%\MicrosoftEdgeWebView2RuntimeInstallerX64.exe" "https://go.microsoft.com/fwlink/?linkid=2124701"
if exist "%WV%\MicrosoftEdgeWebView2RuntimeInstallerX64.exe" copy /Y "%WV%\MicrosoftEdgeWebView2RuntimeInstallerX64.exe" "%STAGE%\" >nul
if exist "%STAGE%\ATLAS.exe" ( echo   [ok] ATLAS.exe ) else ( echo   [!] Desktop app could not be made ^(no internet?^). The installer opens ATLAS in an app window instead. )

echo   [5/5] Copying the AI (Ollama + models, about 12 GB) ...
robocopy "%SRC_AI%\ollama" "%STAGE%\ollama" /MIR /NFL /NDL /NJH /NJS /NP >nul
"%SRC_XAMPP%\php\php.exe" "%A%\tools\stage-models.php" "%SRC_AI%\models" "%STAGE%\models" %MODELS%
if errorlevel 1 ( echo   [!] Copying the AI models failed. & pause & exit /b 1 )

echo.
echo   Compiling the installer (this takes a while)...
REM Newborn = the base for "Download my AI" in ATLAS; MY Mnemo = a complete copy of this PC's AI
if "%MODE%"=="keep" ( set "OUT=%BUILD%\output" ) else ( set "OUT=%BUILD%\base" )
if "%MODELS%"=="lite" set "OUT=%OUT%-lite"
"%ISCC%" /DStage="%STAGE%" /O"%OUT%" "%HERE%ATLAS.iss"
if errorlevel 1 ( echo   [!] Inno Setup failed - see the messages above. & pause & exit /b 1 )
if "%MODE%"=="keep" "%SRC_XAMPP%\php\php.exe" "%A%\tools\export-package.php" "%OUT%"
if "%MODE%"=="keep" if "%MODELS%"=="lite" "%SRC_XAMPP%\php\php.exe" -r "$f=$argv[1].'/mnemo-package/identity.json'; $j=json_decode(file_get_contents($f),true); $j['vision']=false; file_put_contents($f,json_encode($j,JSON_PRETTY_PRINT));" "%OUT%"
echo.
echo   -------------------------------------------------------------
echo   [ok] Done:  %OUT%\ATLAS-Setup.exe  (+ ATLAS-Setup-*.bin)
echo   Keep ALL files from that folder together when you copy them.
echo   -------------------------------------------------------------
if "%MODE%"=="newborn" echo   The "Download my AI" button in ATLAS is now ready to use.
if defined AUTO exit /b 0
explorer "%OUT%"
pause
