@echo off
setlocal EnableExtensions
title ATLAS Portable - one-time setup
REM =====================================================================
REM  Put this file in your portable ATLAS folder, e.g.  D:\ATLAS\  or  E:\ATLAS\ (USB)
REM  It downloads a portable Ollama INTO this folder and keeps the AI models here too,
REM  so nothing lives on the frozen C: drive.
REM =====================================================================
set "ROOT=%~dp0"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"
cd /d "%ROOT%"

echo.
echo   ATLAS Portable setup
echo   Folder: %ROOT%
echo   -------------------------------------------------------------
if /I "%ROOT:~0,2%"=="C:" (
  echo.
  echo   [!] WARNING: this folder is on drive C:.
  echo       If this PC uses Deep Freeze, C: is reset on restart and
  echo       EVERYTHING here will be lost. Move this folder to another
  echo       drive ^(D:, a USB SSD, ...^) and run this file again.
  echo.
  choice /M "  Continue anyway"
  if errorlevel 2 exit /b 1
)

REM --- 1. Check XAMPP is inside this folder ---------------------------
if not exist "%ROOT%\xampp\xampp-control.exe" (
  echo.
  echo   [!] XAMPP was not found in "%ROOT%\xampp".
  echo       Install XAMPP and choose this folder as the install location:
  echo           %ROOT%\xampp
  echo       Then run this file again.
  echo.
  pause & exit /b 1
)
if not exist "%ROOT%\xampp\htdocs\atlas\index.php" (
  echo.
  echo   [!] ATLAS was not found in "%ROOT%\xampp\htdocs\atlas".
  echo       Extract atlas.zip into  %ROOT%\xampp\htdocs
  echo       Then run this file again.
  echo.
  pause & exit /b 1
)
echo   [ok] XAMPP and ATLAS found.

REM --- 2. Built-in AI (skips everything that is already in this folder) ---
call "%ROOT%\0 - Build in the AI (Ollama + Qwen).bat" nopause
if not exist "%ROOT%\ollama\ollama.exe" ( echo   [!] The AI is not built in yet. & pause & exit /b 1 )

REM --- 4. Remember the drive letter (XAMPP paths are fixed if it changes) ---
echo %ROOT:~0,2%> "%ROOT%\.drive"

echo.
echo   -------------------------------------------------------------
echo   Setup finished.  From now on, start everything with:
echo       %ROOT%\2 - Start ATLAS.bat
echo   Tip: run "4 - Create Desktop Shortcut.bat" after every restart
echo        (the desktop is on C: and is reset by Deep Freeze).
echo   -------------------------------------------------------------
pause
