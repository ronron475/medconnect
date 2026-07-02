@echo off
REM OCR is now part of the unified FastAPI service on port 8765.
echo National ID OCR runs on the unified FastAPI service (port 8765).
echo Endpoint: http://127.0.0.1:8765/ocr/extract
echo.
call "%~dp0start_ai_service.bat"
