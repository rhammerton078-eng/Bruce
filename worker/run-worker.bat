@echo off
title ATLAS worker
REM Keeps processing uploads and the storage\inbox folder. Leave this window open, or schedule it at logon.
cd /d "%~dp0.."
"C:\xampp\php\php.exe" worker\ingest.php --watch
