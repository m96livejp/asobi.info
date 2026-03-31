@echo off
echo ========================================
echo  LibreTranslate Setup
echo ========================================
echo.

cd /d "%~dp0"

python --version >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Python not found.
    pause
    exit /b 1
)

if not exist "venv" (
    echo [1/2] Creating venv...
    python -m venv venv
    if errorlevel 1 (
        echo [ERROR] Failed to create venv.
        pause
        exit /b 1
    )
) else (
    echo [1/2] venv exists. Skip.
)

echo [2/2] Installing LibreTranslate...
call venv\Scripts\activate.bat
pip install libretranslate --quiet
if errorlevel 1 (
    echo [ERROR] Install failed.
    pause
    exit /b 1
)

echo.
echo ========================================
echo  Setup complete! Run start.bat to launch.
echo ========================================
pause
