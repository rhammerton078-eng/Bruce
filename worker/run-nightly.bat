@echo off
REM Mnemo's daily sleep cycle. Schedule in Windows Task Scheduler (e.g. daily at 2:00 AM).
cd /d "%~dp0.."
"C:\xampp\php\php.exe" worker\nightly.php >> storage\nightly.log 2>&1
