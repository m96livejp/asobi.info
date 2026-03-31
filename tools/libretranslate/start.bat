@echo off
echo ========================================
echo  LibreTranslate
echo ========================================
echo.

cd /d "%~dp0"

if not exist "venv" (
    echo [ERROR] Run setup.bat first.
    pause
    exit /b 1
)

call venv\Scripts\activate.bat

echo Port: 5000
echo Stop: Ctrl+C
echo Endpoint: http://127.0.0.1:5000
echo ========================================
echo.

libretranslate --host 0.0.0.0 --port 5000 --load-only ja,en
