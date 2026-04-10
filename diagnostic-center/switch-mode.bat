@echo off
REM ============================================================
REM SWITCH MODE - Change website access IP/domain
REM ============================================================
REM Use this to quickly switch between localhost, private IP,
REM or public IP without redeploying everything.
REM Supports auto-detection of public and local IPs.
REM ============================================================

setlocal enabledelayedexpansion

echo.
echo ============================================
echo   Switch Website Access Mode
echo ============================================
echo.

REM ---- Read current settings ----
if exist ".env" (
    for /f "tokens=1,2 delims==" %%a in ('findstr "APACHE_SERVER_NAME" .env') do set CURRENT=%%b
    echo   Current: !CURRENT!
) else (
    echo   Current: not configured
)

echo.
echo Choose new mode:
echo.
echo   1. Localhost only       (http://localhost:8081)
echo   2. Private/Local IP     (LAN access)
echo   3. Public IP            (internet access)
echo   4. Domain name          (with domain)
echo   5. Auto-detect ALL      (configure both local + public)
echo   6. Show current status
echo.
set /p mode="Enter choice (1/2/3/4/5/6): "

if "%mode%"=="6" (
    echo.
    echo Current configuration:
    if exist ".env" (type .env) else (echo   No .env file found)
    echo.
    echo Container status:
    docker compose ps 2>nul || docker compose -f docker-compose.deploy.yml ps 2>nul
    echo.
    echo Network IPs:
    echo   Local:
    for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4"') do echo    %%a
    echo   Public:
    powershell -Command "try { (Invoke-WebRequest -Uri 'https://api.ipify.org' -TimeoutSec 5 -UseBasicParsing).Content } catch { 'Could not detect' }" 2>nul
    echo.
    pause
    exit /b 0
)

set NEW_NAME=localhost
if "%mode%"=="1" set NEW_NAME=localhost
if "%mode%"=="2" (
    echo.
    echo Your network IPs:
    for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4"') do echo    %%a
    echo.
    set /p NEW_NAME="Enter your private IP: "
)
if "%mode%"=="3" (
    echo.
    echo Detecting public IP...
    for /f %%a in ('powershell -Command "(Invoke-WebRequest -Uri 'https://api.ipify.org' -TimeoutSec 5 -UseBasicParsing).Content" 2^>nul') do set DETECTED_IP=%%a
    if defined DETECTED_IP (
        echo   Detected: !DETECTED_IP!
        set /p NEW_NAME="Enter your public IP [!DETECTED_IP!]: "
        if "!NEW_NAME!"=="" set NEW_NAME=!DETECTED_IP!
    ) else (
        set /p NEW_NAME="Enter your public IP: "
    )
)
if "%mode%"=="4" (
    echo.
    set /p NEW_NAME="Enter domain name: "
)
if "%mode%"=="5" (
    echo.
    echo   Auto-detecting IPs...
    
    REM Get local IP (first non-loopback IPv4)
    for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4" ^| findstr /v "127.0"') do (
        set LOCAL_IP=%%a
        set LOCAL_IP=!LOCAL_IP: =!
        goto :auto_got_local
    )
    :auto_got_local
    echo   Local IP: !LOCAL_IP!
    
    REM Get public IP
    for /f %%a in ('powershell -Command "(Invoke-WebRequest -Uri 'https://api.ipify.org' -TimeoutSec 5 -UseBasicParsing).Content" 2^>nul') do set PUBLIC_IP=%%a
    echo   Public IP: !PUBLIC_IP!
    
    REM Update .env with both IPs
    if defined LOCAL_IP (
        powershell -Command "(Get-Content '.env') -replace 'LOCAL_IP=.*', 'LOCAL_IP=!LOCAL_IP!' | Set-Content '.env'"
    )
    if defined PUBLIC_IP (
        powershell -Command "(Get-Content '.env') -replace 'PUBLIC_IP=.*', 'PUBLIC_IP=!PUBLIC_IP!' | Set-Content '.env'"
    )
    
    REM Set server name to public IP (primary focus)
    if defined PUBLIC_IP (
        set NEW_NAME=!PUBLIC_IP!
    ) else if defined LOCAL_IP (
        set NEW_NAME=!LOCAL_IP!
    ) else (
        set NEW_NAME=localhost
    )
    
    echo   Server Name set to: !NEW_NAME!
    echo   Dual IP Bind: true (both IPs will work)
    
    powershell -Command "(Get-Content '.env') -replace 'DUAL_IP_BIND=.*', 'DUAL_IP_BIND=true' | Set-Content '.env'"
)

REM ---- Update .env ----
if exist ".env" (
    REM Use PowerShell to do proper find-replace in the .env file
    powershell -Command "(Get-Content '.env') -replace 'APACHE_SERVER_NAME=.*', 'APACHE_SERVER_NAME=%NEW_NAME%' | Set-Content '.env'"
    echo.
    echo   Updated .env: APACHE_SERVER_NAME=%NEW_NAME%
) else (
    echo ERROR: No .env file found. Run deploy.bat first.
    pause
    exit /b 1
)

REM ---- Restart web container ----
echo.
echo Restarting web server...

REM Try both compose file options
docker compose restart web 2>nul
if %errorlevel% neq 0 (
    docker compose -f docker-compose.deploy.yml --env-file .env restart web 2>nul
)

echo.
echo ============================================
echo   DONE! Website now accessible at:
echo.
echo   http://%NEW_NAME%:8081
if defined LOCAL_IP echo   http://!LOCAL_IP!:8081  (LAN)
if defined PUBLIC_IP echo   http://!PUBLIC_IP!:8081  (Public)
echo ============================================
echo.
pause
