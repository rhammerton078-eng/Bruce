@echo off
setlocal EnableExtensions
title ATLAS - build the AI into this folder
REM =====================================================================
REM  Downloads Ollama and the Qwen models ONE TIME into this folder:
REM      <this folder>\ollama\   the AI program (portable, no installer)
REM      <this folder>\models\   the AI models (qwen2.5:7b, nomic-embed-text, qwen2.5vl:3b)
REM  After that the AI is built in: copy this folder (or use it from a USB drive)
REM  on any Windows PC and it works without downloading or internet.
REM =====================================================================
set "ROOT=%~dp0"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"
cd /d "%ROOT%"
set "OLLAMA_MODELS=%ROOT%\models"

echo.
echo   Building the AI into: %ROOT%
echo   -------------------------------------------------------------
if /I "%ROOT:~0,2%"=="C:" (
  echo   [!] This folder is on drive C:. On a Deep Freeze PC it is wiped on restart.
  echo       Better: move this folder to D:, a USB SSD, or another unfrozen drive.
  choice /M "  Continue anyway"
  if errorlevel 2 exit /b 1
)

REM --- 1. Portable Ollama program -----------------------------------------
if exist "%ROOT%\ollama\ollama.exe" (
  echo   [ok] Ollama is already built in.
) else (
  echo   [1/3] Downloading portable Ollama ^(about 1-2 GB^)...
  if not exist "%ROOT%\ollama" mkdir "%ROOT%\ollama"
  curl -L --fail -o "%ROOT%\ollama\ollama.zip" "https://github.com/ollama/ollama/releases/latest/download/ollama-windows-amd64.zip"
  if errorlevel 1 (
    echo   [!] Download failed. Download this file in your browser:
    echo       https://github.com/ollama/ollama/releases/latest/download/ollama-windows-amd64.zip
    echo       and extract it into  %ROOT%\ollama   ^(ollama.exe must be inside^), then run this again.
    goto :end
  )
  echo   Extracting...
  tar -xf "%ROOT%\ollama\ollama.zip" -C "%ROOT%\ollama"
  del "%ROOT%\ollama\ollama.zip"
  if not exist "%ROOT%\ollama\ollama.exe" ( echo   [!] Extraction failed. & goto :end )
  echo   [ok] Ollama built in.
)

REM --- 2. Re-use models already downloaded on this PC's C: drive ----------
if not exist "%OLLAMA_MODELS%" mkdir "%OLLAMA_MODELS%"
if exist "%USERPROFILE%\.ollama\models\blobs" (
  echo   [2/3] Copying models already downloaded on C: ^(no need to download them again^)...
  robocopy "%USERPROFILE%\.ollama\models" "%OLLAMA_MODELS%" /E /NFL /NDL /NJH /NJS /NP >nul
)

REM --- 3. Download only the models that are still missing -----------------
taskkill /IM "ollama app.exe" /F >nul 2>&1
taskkill /IM ollama.exe /F >nul 2>&1
timeout /t 2 >nul
start "ATLAS Ollama" /MIN "%ROOT%\ollama\ollama.exe" serve
timeout /t 5 >nul

echo   [3/3] Checking models...
REM Ask Ollama itself which models are in this folder (works with every Ollama storage layout)
"%ROOT%\ollama\ollama.exe" list > "%TEMP%\atlas_models.txt" 2>nul
for %%M in (qwen2.5:7b nomic-embed-text qwen2.5vl:3b) do (
  findstr /B /I /C:"%%M " /C:"%%M:latest " "%TEMP%\atlas_models.txt" >nul
  if errorlevel 1 ( echo   Downloading %%M ... & "%ROOT%\ollama\ollama.exe" pull %%M ) else ( echo   [ok] %%M already built in - not downloading )
)
del "%TEMP%\atlas_models.txt" >nul 2>&1

echo.
echo   Models inside this folder:
"%ROOT%\ollama\ollama.exe" list
echo.
echo   Quick test (the first answer takes 10-60 seconds while the model loads):
"%ROOT%\ollama\ollama.exe" run qwen2.5:7b "Reply with one short sentence: hello, I am working."
taskkill /IM ollama.exe /F >nul 2>&1

echo.
echo   -------------------------------------------------------------
echo   Done. The AI is now built into:
echo       %ROOT%\ollama
echo       %ROOT%\models
echo   Copy the whole folder to another PC (or use it from a USB drive):
echo   nothing has to be downloaded again.
echo   -------------------------------------------------------------
:end
if /I not "%~1"=="nopause" pause
