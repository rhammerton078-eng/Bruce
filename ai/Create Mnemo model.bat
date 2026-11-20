@echo off
setlocal EnableExtensions
title ATLAS - create Mnemo's own model
REM Creates "mnemo:7b" in Ollama from ai\Modelfile (instant, reuses qwen2.5:7b).
REM Ollama must be running (run "ATLAS Lab Setup.bat" first).
set "HERE=%~dp0"
set "OLL="
for %%P in ("C:\ATLAS-AI\ollama\ollama.exe" "%HERE%..\..\..\..\ollama\ollama.exe" "%LOCALAPPDATA%\Programs\Ollama\ollama.exe") do if not defined OLL if exist "%%~P" set "OLL=%%~P"
if not defined OLL set "OLL=ollama"
echo   Using %OLL%
"%OLL%" create mnemo:7b -f "%HERE%Modelfile"
if errorlevel 1 ( echo. & echo   [!] Failed. Is Ollama running and is qwen2.5:7b installed? & pause & exit /b 1 )
echo.
echo   [ok] mnemo:7b created.  In ATLAS: Settings ^> Chat ^& reasoning model ^> mnemo:7b ^> Save
pause
