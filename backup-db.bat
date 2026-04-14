@echo off
REM ============================================================
REM Database Backup Script - Diagnostic Center
REM ============================================================
REM Creates a timestamped SQL backup of the database
REM Usage: Run this script from the project root folder
REM ============================================================

echo.
echo === Database Backup ===
echo.

if not exist "dump\backup" mkdir "dump\backup"

for /f "tokens=1-3 delims=/ " %%a in ('date /t') do set mydate=%%c-%%a-%%b
for /f "tokens=1-2 delims=: " %%a in ('time /t') do set mytime=%%a%%b

set BACKUP_FILE=dump\backup\diagnostic_center_db_%mydate%_%mytime%.sql

echo Backing up database to: %BACKUP_FILE%

docker exec diagnostic-center-db mysqldump --skip-ssl -u root -p%DB_PASS% diagnostic_center_db > "%BACKUP_FILE%"

if %errorlevel% equ 0 (
    echo.
    echo Backup completed successfully: %BACKUP_FILE%
) else (
    echo.
    echo ERROR: Backup failed. Is the database container running?
    echo Run: docker-compose ps
)

echo.
pause
