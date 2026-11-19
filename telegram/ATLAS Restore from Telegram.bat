@echo off
setlocal EnableExtensions
title ATLAS - restore from Telegram
REM =====================================================================
REM  After a Deep Freeze restart: rebuilds the AI from your Telegram files.
REM  1. From Telegram Saved Messages, download into Downloads:
REM        ATLAS-models.part01 ... ATLAS-models.part05
REM        OllamaSetup.exe
REM  2. Make sure ATLAS is in C:\xampp\htdocs\atlas (GitHub ZIP)
REM  3. Right-click this file > Run as administrator
REM  Then it runs "ATLAS Lab Setup.bat" for you.
REM =====================================================================
set "DL=%USERPROFILE%\Downloads"
set "AI=C:\ATLAS-AI"
set "APP=C:\xampp\htdocs\atlas"

echo.
echo   ATLAS restore from Telegram
echo   -------------------------------------------------------------
mkdir "%AI%" 2>nul

REM --- 1. Ollama (the AI program) ------------------------------------------
if exist "%AI%\ollama\ollama.exe" ( echo   [ok] Ollama already in %AI%\ollama & goto :models )
if not exist "%DL%\OllamaSetup.exe" ( echo   [!] OllamaSetup.exe not found in Downloads. Download it from Telegram first. & pause & exit /b 1 )
echo   Installing Ollama (about 1 minute)...
"%DL%\OllamaSetup.exe" /VERYSILENT /SUPPRESSMSGBOXES /NORESTART
taskkill /IM "ollama app.exe" /F >nul 2>&1
taskkill /IM ollama.exe /F >nul 2>&1
if not exist "%LOCALAPPDATA%\Programs\Ollama\ollama.exe" ( echo   [!] Ollama install failed. Double-click OllamaSetup.exe yourself, then run this again. & pause & exit /b 1 )
xcopy "%LOCALAPPDATA%\Programs\Ollama" "%AI%\ollama" /E /I /Y /Q >nul
echo   [ok] Ollama copied to %AI%\ollama

:models
REM --- 2. Models (the AI brain) ---------------------------------------------
if exist "%AI%\models\manifests" ( echo   [ok] Models already in %AI%\models & goto :setup )
for %%P in (01 02 03 04 05) do (
  if not exist "%DL%\ATLAS-models.part%%P" ( echo   [!] Missing ATLAS-models.part%%P in Downloads. Download all 5 parts from Telegram. & pause & exit /b 1 )
)
echo   Joining the 5 parts (a few minutes, about 9 GB)...
copy /b "%DL%\ATLAS-models.part01" + "%DL%\ATLAS-models.part02" + "%DL%\ATLAS-models.part03" + "%DL%\ATLAS-models.part04" + "%DL%\ATLAS-models.part05" "C:\ATLAS-models.tar" >nul
if errorlevel 1 ( echo   [!] Joining failed - is drive C: full? & pause & exit /b 1 )
echo   Unpacking...
tar -xf "C:\ATLAS-models.tar" -C "%AI%"
if errorlevel 1 ( echo   [!] Unpacking failed - a part may be damaged. Download it again. & pause & exit /b 1 )
del "C:\ATLAS-models.tar"
echo   [ok] Models restored to %AI%\models
echo   (You can now delete the 5 parts from Downloads to free space.)

:setup
REM --- 3. Start everything --------------------------------------------------
if not exist "%APP%\ATLAS Lab Setup.bat" ( echo   [!] ATLAS not found in %APP%. Extract the GitHub ZIP there, then run "ATLAS Lab Setup.bat". & pause & exit /b 1 )
echo.
echo   Starting ATLAS...
call "%APP%\ATLAS Lab Setup.bat"
