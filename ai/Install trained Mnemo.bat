@echo off
setlocal EnableExtensions
title ATLAS - install the trained Mnemo model
REM Step after Google Colab training (ai\finetune\Mnemo_Train_Colab.ipynb):
REM put mnemo-7b-q4_k_m.gguf in your Downloads folder, then run this file.
REM It creates "mnemo:7b-trained" next to mnemo:7b, so you can compare both.
set "HERE=%~dp0"
set "GGUF=%USERPROFILE%\Downloads\mnemo-7b-q4_k_m.gguf"
if not exist "%GGUF%" ( echo   [!] %GGUF% not found. Download it from Colab first. & pause & exit /b 1 )
set "DEST=C:\ATLAS-AI\trained"
if exist "%HERE%..\..\..\..\ollama\ollama.exe" set "DEST=%HERE%..\..\..\..\trained"
mkdir "%DEST%" 2>nul
echo   Copying the model (about 4.7 GB)...
copy /Y "%GGUF%" "%DEST%\mnemo-7b-q4_k_m.gguf" >nul || ( echo   [!] Copy failed. & pause & exit /b 1 )
copy /Y "%HERE%finetune\Modelfile.trained" "%DEST%\Modelfile" >nul
set "OLL="
for %%P in ("C:\ATLAS-AI\ollama\ollama.exe" "%HERE%..\..\..\..\ollama\ollama.exe" "%LOCALAPPDATA%\Programs\Ollama\ollama.exe") do if not defined OLL if exist "%%~P" set "OLL=%%~P"
if not defined OLL set "OLL=ollama"
pushd "%DEST%"
"%OLL%" create mnemo:7b-trained -f Modelfile
set "RC=%ERRORLEVEL%"
popd
if not "%RC%"=="0" ( echo   [!] Failed. Is Ollama running? & pause & exit /b 1 )
echo.
echo   [ok] mnemo:7b-trained installed.
echo   In ATLAS: Settings ^> "Use it" (or pick mnemo:7b-trained) ^> Save.
echo   Then Quality ^> Run benchmark to compare it with mnemo:7b.
pause
