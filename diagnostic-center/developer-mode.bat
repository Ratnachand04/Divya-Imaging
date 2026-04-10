@echo off
REM ============================================================
REM DEVELOPER MODE - Toggle Ghost developer mode on/off
REM ============================================================
REM When ON:  File changes apply immediately (live editing)
REM When OFF: File changes cached (production mode)
REM ============================================================

setlocal enabledelayedexpansion

echo.
echo ============================================
echo   Developer Mode Toggle
echo ============================================
echo.

REM ---- Read current state ----
if exist ".env" (
    for /f "tokens=1,2 delims==" %%a in ('findstr "DEVELOPER_MODE" .env') do set CURRENT=%%b
    echo   Current State: !CURRENT!
) else (
    echo   ERROR: No .env file found. Run deploy.bat first.
    pause
    exit /b 1
)

echo.
echo   1. Turn ON  (live file changes, OPcache disabled)
echo   2. Turn OFF (cached mode, production performance)
echo   3. Hot Reload (force Apache to reload files NOW)
echo   4. Show status
echo   5. Auto-detect IPs and configure
echo.
set /p choice="Enter choice (1/2/3/4/5): "

if "%choice%"=="1" (
    powershell -Command "(Get-Content '.env') -replace 'DEVELOPER_MODE=.*', 'DEVELOPER_MODE=true' | Set-Content '.env'"
    echo.
    echo   Developer Mode: ON
    echo   Restarting container to apply...
    docker compose restart web 2>nul
    if %errorlevel% neq 0 (
        docker compose -f docker-compose.deploy.yml --env-file .env restart web 2>nul
    )
    echo   Done! File changes will now apply immediately.
)

if "%choice%"=="2" (
    powershell -Command "(Get-Content '.env') -replace 'DEVELOPER_MODE=.*', 'DEVELOPER_MODE=false' | Set-Content '.env'"
    echo.
    echo   Developer Mode: OFF
    echo   Restarting container to apply...
    docker compose restart web 2>nul
    if %errorlevel% neq 0 (
        docker compose -f docker-compose.deploy.yml --env-file .env restart web 2>nul
    )
    echo   Done! File changes are now cached.
)

if "%choice%"=="3" (
    echo.
    echo   Sending hot reload signal...
    docker exec diagnostic-center-web apache2ctl graceful 2>nul
    if %errorlevel% equ 0 (
        echo   Apache reloaded successfully!
    ) else (
        echo   ERROR: Could not reload Apache. Is the container running?
    )
)

if "%choice%"=="4" (
    echo.
    echo ---- Current Configuration ----
    if exist ".env" (type .env)
    echo.
    echo ---- Container Status ----
    docker compose ps 2>nul || docker compose -f docker-compose.deploy.yml ps 2>nul
    echo.
    echo ---- Network Info ----
    echo   Local IPs:
    for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4"') do echo     %%a
    echo.
    echo   Public IP:
    powershell -Command "(Invoke-WebRequest -Uri 'https://api.ipify.org' -TimeoutSec 5 -UseBasicParsing).Content" 2>nul
    echo.
)

if "%choice%"=="5" (
    echo.
    echo   Detecting IPs...
    
    REM Get local IP
    for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4" ^| findstr /v "127.0"') do (
        set LOCAL_IP=%%a
        set LOCAL_IP=!LOCAL_IP: =!
        goto :got_local
    )
    :got_local
    echo   Local IP: !LOCAL_IP!
    
    REM Get public IP
    for /f %%a in ('powershell -Command "(Invoke-WebRequest -Uri 'https://api.ipify.org' -TimeoutSec 5 -UseBasicParsing).Content" 2^>nul') do set PUBLIC_IP=%%a
    echo   Public IP: !PUBLIC_IP!
    
    REM Update .env
    if defined LOCAL_IP (
        powershell -Command "(Get-Content '.env') -replace 'LOCAL_IP=.*', 'LOCAL_IP=!LOCAL_IP!' | Set-Content '.env'"
        echo   Updated LOCAL_IP in .env
    )
    if defined PUBLIC_IP (
        powershell -Command "(Get-Content '.env') -replace 'PUBLIC_IP=.*', 'PUBLIC_IP=!PUBLIC_IP!' | Set-Content '.env'"
        echo   Updated PUBLIC_IP in .env
    )
    
    echo.
    echo   Restarting container with new IPs...
    docker compose restart web 2>nul
    if %errorlevel% neq 0 (
        docker compose -f docker-compose.deploy.yml --env-file .env restart web 2>nul
    )
    
    echo.
    echo ============================================
    echo   Access URLs:
    echo     http://localhost:8081
    if defined LOCAL_IP echo     http://!LOCAL_IP!:8081
    if defined PUBLIC_IP echo     http://!PUBLIC_IP!:8081
    echo ============================================
)

echo.
pause
