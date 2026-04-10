@echo off
REM ============================================================
REM SSL Certificate Setup Script for Diagnostic Center
REM ============================================================
REM This script helps you set up SSL certificates.
REM Run this BEFORE enabling SSL in .env
REM ============================================================

echo.
echo ============================================
echo   SSL Certificate Setup
echo ============================================
echo.

if not exist "docker\ssl" mkdir docker\ssl

echo Choose an option:
echo.
echo   1. Generate self-signed certificate (for testing)
echo   2. I have my own certificate files (copy manually)
echo   3. Set up Let's Encrypt (free SSL - requires domain)
echo.

set /p choice="Enter choice (1/2/3): "

if "%choice%"=="1" goto selfsigned
if "%choice%"=="2" goto manual
if "%choice%"=="3" goto letsencrypt
goto end

:selfsigned
echo.
set /p domain="Enter your domain or IP (e.g., example.com or 192.168.1.100): "
echo.
echo Generating self-signed certificate for: %domain%
echo.

docker run --rm -v "%cd%\docker\ssl:/ssl" alpine/openssl req -x509 -nodes -days 365 -newkey rsa:2048 ^
    -keyout /ssl/private.key ^
    -out /ssl/certificate.crt ^
    -subj "/C=IN/ST=State/L=City/O=DiagnosticCenter/CN=%domain%"

echo.
echo Self-signed certificate generated!
echo.
echo NOTE: Browsers will show a warning for self-signed certificates.
echo       This is normal for testing. For production, use a real certificate.
echo.
echo Next steps:
echo   1. Edit .env and set ENABLE_SSL=true
echo   2. Set APACHE_SERVER_NAME=%domain%
echo   3. Run: docker-compose up -d --build
echo.
goto end

:manual
echo.
echo Please copy your SSL certificate files to the docker\ssl\ folder:
echo.
echo   docker\ssl\certificate.crt    (your SSL certificate)
echo   docker\ssl\private.key        (your private key)
echo   docker\ssl\ca_bundle.crt      (CA bundle, if provided)
echo.
echo After copying:
echo   1. Edit .env and set ENABLE_SSL=true
echo   2. Run: docker-compose up -d --build
echo.
goto end

:letsencrypt
echo.
echo For Let's Encrypt (free SSL), you need:
echo   - A registered domain name pointing to your server IP
echo   - Port 80 and 443 open on your firewall
echo.
echo Steps:
echo   1. Install certbot on your HOST machine (not in Docker)
echo   2. Run: certbot certonly --standalone -d yourdomain.com
echo   3. Copy the generated files:
echo      - /etc/letsencrypt/live/yourdomain.com/fullchain.pem  -^>  docker\ssl\certificate.crt
echo      - /etc/letsencrypt/live/yourdomain.com/privkey.pem    -^>  docker\ssl\private.key
echo   4. Edit .env:
echo      - ENABLE_SSL=true
echo      - APACHE_SERVER_NAME=yourdomain.com
echo   5. Run: docker-compose up -d --build
echo.
echo TIP: Set up a cron job to auto-renew: certbot renew
echo.
goto end

:end
pause
