@echo off
REM ============================================================
REM BUILD & PUSH - Diagnostic Center Docker Image
REM ============================================================
REM Run this on YOUR development machine to:
REM   1. Build the Docker image
REM   2. Push it to Docker Hub (ratnachand/diagnostic-center-web)
REM
REM After pushing, on any other machine just run deploy.bat
REM ============================================================

echo.
echo ============================================
echo   Build ^& Push Diagnostic Center Image
echo ============================================
echo.

REM ---- Check Docker ----
docker --version >nul 2>&1
if %errorlevel% neq 0 (
    echo ERROR: Docker is not installed or not running!
    echo Install Docker Desktop from https://docker.com
    pause
    exit /b 1
)

REM ---- Login to Docker Hub ----
echo Step 1: Logging into Docker Hub...
echo   (If not logged in, a browser/prompt will open)
docker login
if %errorlevel% neq 0 (
    echo ERROR: Docker Hub login failed!
    pause
    exit /b 1
)

REM ---- Build the image ----
echo.
echo Step 2: Building the Docker image...
echo   This may take 5-10 minutes on first build...
echo.
docker build -t ratnachand/diagnostic-center-web:latest .
if %errorlevel% neq 0 (
    echo ERROR: Build failed! Check the errors above.
    pause
    exit /b 1
)

echo.
echo Step 3: Tagging with date version...
for /f "tokens=1-3 delims=/ " %%a in ('date /t') do set TAG=%%c%%a%%b
docker tag ratnachand/diagnostic-center-web:latest ratnachand/diagnostic-center-web:%TAG%
echo   Tagged: ratnachand/diagnostic-center-web:%TAG%

REM ---- Push to Docker Hub ----
echo.
echo Step 4: Pushing to Docker Hub...
docker push ratnachand/diagnostic-center-web:latest
docker push ratnachand/diagnostic-center-web:%TAG%

if %errorlevel% neq 0 (
    echo ERROR: Push failed! Check your internet connection.
    pause
    exit /b 1
)

echo.
echo ============================================
echo   SUCCESS! Image pushed to Docker Hub
echo ============================================
echo.
echo   Image: ratnachand/diagnostic-center-web:latest
echo   Tag:   ratnachand/diagnostic-center-web:%TAG%
echo.
echo   On any other machine, run:
echo     deploy.bat
echo   to pull and start the website.
echo ============================================
echo.
pause
