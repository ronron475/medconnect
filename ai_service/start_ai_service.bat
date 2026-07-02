@echo off
REM Start medConnect Python AI service (port 8765)
cd /d "%~dp0\.."

set "RESOLVE=%~dp0resolve_python.bat"
set "PYTHON_EXE="
for /f "delims=" %%P in ('call "%RESOLVE%" 2^>nul') do set "PYTHON_EXE=%%P"

if not defined PYTHON_EXE (
  echo No working Python found.
  echo Run: ai_service\install_ai_dependencies.bat
  pause
  exit /b 1
)

echo Using Python: %PYTHON_EXE%
"%PYTHON_EXE%" --version

set MEDCONNECT_WHISPER_MODEL=small
if not exist storage\logs mkdir storage\logs

netstat -ano | findstr ":8765" | findstr "LISTENING" >nul
if %errorlevel%==0 (
  echo medConnect AI service is already running on port 8765.
  echo Health: http://127.0.0.1:8765/health
  pause
  exit /b 0
)

echo Starting medConnect FastAPI service...
echo Keep this window open while using NLP, OCR, or teleconsultation AI.
echo Health check: http://127.0.0.1:8765/health
echo API docs:    http://127.0.0.1:8765/docs
echo Log: storage\logs\ai_service.log
echo.
cd /d "%~dp0"
"%PYTHON_EXE%" -u -m uvicorn app.main:app --host 127.0.0.1 --port 8765

echo.
echo medConnect AI service stopped.
pause
