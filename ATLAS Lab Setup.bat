@echo off
setlocal EnableExtensions
title ATLAS - lab PC setup
REM =====================================================================
REM  One click after every restart on a frozen (Deep Freeze) lab PC.
REM  Put this repository in C:\xampp\htdocs\atlas, then run this file
REM  (right-click > Run as administrator).
REM
REM  - Finds the AI (Ollama + Qwen):
REM       1. a USB / other drive with  X:\ATLAS-AI\ollama\ollama.exe   -> no download at all
REM       2. otherwise C:\ATLAS-AI  (downloaded once per session, about 10 GB)
REM  - Starts the school's XAMPP (Apache + MySQL), Ollama and the background learner
REM  - Opens ATLAS
REM =====================================================================
set "APP=%~dp0"
if "%APP:~-1%"=="\" set "APP=%APP:~0,-1%"
set "XAMPP=C:\xampp"
set "URL=http://localhost/atlas/"

echo.
echo   ATLAS lab setup
echo   -------------------------------------------------------------

REM --- 1. Where is the AI? Prefer a USB / other drive copy (no download) ---
set "AI="
for %%D in (D E F G H I J K L M N O P Q R S T U V W Y Z) do (
  if not defined AI if exist "%%D:\ATLAS-AI\ollama\ollama.exe" set "AI=%%D:\ATLAS-AI"
)
if not defined AI set "AI=C:\ATLAS-AI"
echo   AI folder: %AI%
set "OLLAMA_MODELS=%AI%\models"
set "OLLAMA_KEEP_ALIVE=30m"
set "OLLAMA_IGPU_ENABLE="
REM Smaller AI working memory so qwen2.5:7b fits on a 6 GB graphics card (harmless on CPU-only PCs)
set "OLLAMA_FLASH_ATTENTION=1"
set "OLLAMA_KV_CACHE_TYPE=q8_0"

REM --- 2. Portable Ollama (download only if missing) ----------------------
if not exist "%AI%\ollama\ollama.exe" (
  echo   Downloading portable Ollama ^(about 1.5 GB^)...
  mkdir "%AI%\ollama" 2>nul
  mkdir "%AI%\models" 2>nul
  curl -L --fail -o "%AI%\ollama\ollama.zip" "https://github.com/ollama/ollama/releases/latest/download/ollama-windows-amd64.zip"
  if errorlevel 1 ( echo   [!] Download failed. Check the internet connection. & pause & exit /b 1 )
  tar -xf "%AI%\ollama\ollama.zip" -C "%AI%\ollama"
  del "%AI%\ollama\ollama.zip"
)
if not exist "%AI%\ollama\ollama.exe" ( echo   [!] Ollama could not be installed. & pause & exit /b 1 )

REM --- 2b. NVIDIA graphics card? Make sure this Ollama has the GPU files --
nvidia-smi >nul 2>&1
if errorlevel 1 ( echo   No NVIDIA graphics card: the AI runs on the CPU. & goto :gpudone )
dir /b "%AI%\ollama\lib\ollama\cuda_*" >nul 2>&1
if errorlevel 1 (
  echo   [!] NVIDIA graphics card found, but this Ollama has NO graphics-card files.
  echo       The AI will be slow. Install OllamaSetup.exe from ollama.com, then delete
  echo       %AI%\ollama and run "ATLAS Restore from Telegram.bat" again.
  timeout /t 8 >nul
) else ( echo   [ok] NVIDIA graphics card: the AI runs on the GPU )
:gpudone

REM --- 3. Start Ollama ----------------------------------------------------
taskkill /IM "ollama app.exe" /F >nul 2>&1
taskkill /IM ollama.exe /F >nul 2>&1
timeout /t 2 >nul
start "ATLAS Ollama" /MIN "%AI%\ollama\ollama.exe" serve
timeout /t 5 >nul

REM --- 4. Models (download only the missing ones) -------------------------
"%AI%\ollama\ollama.exe" list > "%TEMP%\atlas_models.txt" 2>nul
for %%M in (qwen2.5:7b nomic-embed-text qwen2.5vl:3b) do (
  findstr /B /I /C:"%%M " /C:"%%M:latest " "%TEMP%\atlas_models.txt" >nul
  if errorlevel 1 ( echo   Downloading %%M ... & "%AI%\ollama\ollama.exe" pull %%M ) else ( echo   [ok] %%M )
)
REM Mnemo's own model name (instant: reuses the qwen2.5:7b files)
findstr /B /I /C:"mnemo:7b " "%TEMP%\atlas_models.txt" >nul
if errorlevel 1 if exist "%APP%\ai\Modelfile" ( echo   Creating mnemo:7b ... & "%AI%\ollama\ollama.exe" create mnemo:7b -f "%APP%\ai\Modelfile" >nul 2>&1 && echo   [ok] mnemo:7b )
del "%TEMP%\atlas_models.txt" >nul 2>&1

REM --- 5. School XAMPP: Apache + MySQL (port 3306) ------------------------
netstat -ano | findstr /R /C:":3306 .*LISTENING" >NUL
if errorlevel 1 ( echo   Starting MySQL... & start "ATLAS MySQL" /MIN /D "%XAMPP%" mysql_start.bat ) else ( echo   [ok] MySQL running )
netstat -ano | findstr /R /C:":80 .*LISTENING" >NUL
if errorlevel 1 ( echo   Starting Apache... & start "ATLAS Apache" /MIN /D "%XAMPP%" apache_start.bat ) else ( echo   [ok] Apache running )

REM --- 6. Background learner ---------------------------------------------
taskkill /FI "WINDOWTITLE eq ATLAS worker*" /F >nul 2>&1
start "ATLAS worker" /MIN /D "%APP%" "%XAMPP%\php\php.exe" worker\ingest.php --watch

REM --- 7. Open ATLAS (setup page if the database is not installed yet) ----
echo   Waiting for ATLAS...
for /L %%i in (1,1,30) do (
  curl -s -o NUL -m 2 %URL%setup.php && goto :open
  timeout /t 1 >NUL
)
:open
start "" %URL%setup.php
echo.
echo   -------------------------------------------------------------
echo   Done. On the setup page: "Install database" (first time this session),
echo   then Open ATLAS and Insert your .atlascard to restore Mnemo's memory.
echo.
echo   TIP: copy %AI% to a USB drive as  X:\ATLAS-AI  - next time this
echo        script uses it and downloads nothing.
echo   -------------------------------------------------------------
pause
