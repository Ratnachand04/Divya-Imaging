@echo off
REM One-click mirror repair helper
REM Usage: repair-mirror.bat [--all] [--json]

cd /d "%~dp0"

where php >nul 2>&1
if %errorlevel% equ 0 (
    php data_backup\repair_mirror_tables.php %*
) else (
    docker exec diagnostic-center-web php /var/www/html/data_backup/repair_mirror_tables.php %*
)
if errorlevel 1 (
    echo Mirror repair finished with errors.
    exit /b 1
)

echo Mirror repair finished successfully.
exit /b 0
