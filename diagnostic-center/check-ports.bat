@echo off
REM ============================================================
REM Port Conflict Checker - Run before Docker Compose
REM ============================================================
REM Checks if ports 8081, 8443, 3301, 8082 are already in use
REM by system Apache 2.4 or any other service on the host.
REM ============================================================

echo ============================================
echo   Port Conflict Pre-Check
echo ============================================
echo.

set CONFLICT=0
set APP_PORT=8081
set SSL_PORT=8443
set DB_PORT=3301
set PMA_PORT=8082

REM Load from .env if exists
if exist ".env" (
    for /f "tokens=1,2 delims==" %%a in (.env) do (
        if "%%a"=="APP_PORT" set APP_PORT=%%b
        if "%%a"=="SSL_PORT" set SSL_PORT=%%b
        if "%%a"=="DB_PORT" set DB_PORT=%%b
        if "%%a"=="PMA_PORT" set PMA_PORT=%%b
    )
)

echo Checking ports: HTTP=%APP_PORT%  HTTPS=%SSL_PORT%  DB=%DB_PORT%  PMA=%PMA_PORT%
echo.

REM Check each port
for %%P in (%APP_PORT% %SSL_PORT% %DB_PORT% %PMA_PORT%) do (
    call :CHECK_PORT %%P
)

echo.
if %CONFLICT%==0 (
    echo [OK] All ports are FREE. Docker can start safely.
    echo.
    echo ============================================
    echo   No conflicts with system Apache 2.4
    echo   Ports 80/443 left for system Apache
    echo   Ports %APP_PORT%/%SSL_PORT%/%DB_PORT%/%PMA_PORT% for Docker
    echo ============================================
) else (
    echo [WARNING] Port conflicts detected!
    echo.
    echo Solutions:
    echo   1. Stop the conflicting service: net stop ^<service^>
    echo   2. Change the port in .env file
    echo   3. Kill the process using the port: taskkill /PID ^<pid^> /F
    echo.
    echo To find what's using a port:
    echo   netstat -ano ^| findstr :PORT
)

echo.
pause
exit /b %CONFLICT%

:CHECK_PORT
set PORT=%1
echo Checking port %PORT%...

REM Use netstat to check if port is in use
set PORT_IN_USE=0
for /f "tokens=5" %%i in ('netstat -ano ^| findstr ":%PORT% " ^| findstr "LISTENING" 2^>nul') do (
    set PORT_IN_USE=1
    set PID=%%i
)

if %PORT_IN_USE%==1 (
    echo   [CONFLICT] Port %PORT% is already in use by PID %PID%
    
    REM Try to identify the process
    for /f "tokens=1" %%n in ('tasklist /fi "PID eq %PID%" /nh /fo csv 2^>nul ^| for /f "tokens=1 delims=," %%a in ('more') do @echo %%~a') do (
        echo   Process: %%n
    )
    
    REM Special check for Apache 2.4
    tasklist /fi "PID eq %PID%" /nh 2>nul | findstr /i "httpd apache" >nul
    if not errorlevel 1 (
        echo   ** This is Apache 2.4 (system Apache) **
        echo   Docker ports are specifically chosen to avoid this conflict.
        if %PORT%==80 echo   Change APP_PORT in .env (default 8081 avoids this)
        if %PORT%==443 echo   Change SSL_PORT in .env (default 8443 avoids this)
    )
    
    set CONFLICT=1
) else (
    echo   [FREE] Port %PORT% is available
)
exit /b 0
