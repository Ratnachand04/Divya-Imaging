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

set DB_PASS=root_password
set DB_NAME=diagnostic_center_db
if exist ".env" (
    for /f "tokens=1,2 delims==" %%a in ('findstr /r "^DB_PASS=" .env') do set DB_PASS=%%b
    for /f "tokens=1,2 delims==" %%a in ('findstr /r "^DB_NAME=" .env') do set DB_NAME=%%b
)

set BACKUP_FILE=dump\backup\diagnostic_center_db_%mydate%_%mytime%.sql
set MIRROR_DIR=dump\backup\sql_bundle_%mydate%_%mytime%

echo Backing up database to: %BACKUP_FILE%

docker exec diagnostic-center-db mysqldump --skip-ssl -u root -p%DB_PASS% %DB_NAME% > "%BACKUP_FILE%"

if %errorlevel% equ 0 (
    echo.
    echo Backup completed successfully: %BACKUP_FILE%

    if not exist "%MIRROR_DIR%" mkdir "%MIRROR_DIR%"
    xcopy "dump\init" "%MIRROR_DIR%\init\" /E /I /Y >nul
    echo SQL bundle mirrored: %MIRROR_DIR%
) else (
    echo.
    echo ERROR: Backup failed. Is the database container running?
    echo Run: docker-compose ps
)

echo.
pause
