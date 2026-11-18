@echo off
REM Saves a full backup of Mnemo's memory (database + files) into storage\backups
set XAMPP=C:\xampp
cd /d "%~dp0.."
"%XAMPP%\php\php.exe" -r "require 'src/bootstrap.php'; echo 'Saved: ' . (new Atlas\Maintenance())->backup(true, 'manual') . PHP_EOL;"
explorer storage\backups
pause
