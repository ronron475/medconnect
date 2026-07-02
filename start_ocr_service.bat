@echo off
REM Shortcut: start unified FastAPI service (includes OCR) from project root
cd /d "%~dp0"
call ai_service\start_ocr_service.bat
