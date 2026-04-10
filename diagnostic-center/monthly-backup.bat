@echo off
REM ============================================================
REM Monthly Database Backup - Diagnostic Center
REM ============================================================
REM Saves SQL backup to: data_backup\YEAR\MONTH\
REM Usage: Run from the project root folder
REM ============================================================

echo.
echo === Monthly Database Backup ===
echo.

REM Get current year and month
for /f "tokens=1-3 delims=/ " %%a in ('date /t') do (
    set YEAR=%%c
    set MONTH=%%a
)

REM Create folder structure: data_backup\YEAR\MONTH
set BACKUP_DIR=data_backup\%YEAR%\%MONTH%
if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"

REM Generate timestamp for filename
for /f "tokens=1-3 delims=/ " %%a in ('date /t') do set mydate=%%c-%%a-%%b
for /f "tokens=1-2 delims=:. " %%a in ('echo %TIME%') do set mytime=%%a%%b

set BACKUP_FILE=%BACKUP_DIR%\backup_%mydate%_%mytime%.sql

echo Backup folder: %BACKUP_DIR%
echo Backup file:   %BACKUP_FILE%

REM Check if container is running
docker ps --filter "name=diagnostic-center-db" --format "{{.Names}}" | findstr "diagnostic-center-db" >nul 2>&1
if %errorlevel% neq 0 (
    echo.
    echo ERROR: Database container is not running.
    echo Run: docker-compose up -d
    echo.
    pause
    exit /b 1
)

REM Load DB password from .env if available
set DB_PASS=root_password
set DB_NAME=diagnostic_center_db
if exist ".env" (
    for /f "tokens=1,2 delims==" %%a in ('findstr /r "^DB_PASS=" .env') do set DB_PASS=%%b
    for /f "tokens=1,2 delims==" %%a in ('findstr /r "^DB_NAME=" .env') do set DB_NAME=%%b
)

echo Backing up database: %DB_NAME%
echo.

docker exec diagnostic-center-db mysqldump --skip-ssl --single-transaction --routines --triggers -u root -p%DB_PASS% %DB_NAME% > "%BACKUP_FILE%"

if %errorlevel% equ 0 (
    echo.
    echo Backup completed successfully!
    echo File: %BACKUP_FILE%
    for %%A in ("%BACKUP_FILE%") do echo Size: %%~zA bytes
    echo.

    REM Update the index via PHP if available
    where php >nul 2>&1
    if %errorlevel% equ 0 (
        php data_backup\update_index_cli.php "%BACKUP_FILE%" "%DB_NAME%" "%YEAR%" "%MONTH%"
    )
) else (
    echo.
    echo ERROR: Backup failed!
    echo Check that the database container is running: docker-compose ps
)

echo.
pause
