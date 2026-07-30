@echo off
REM Restart medConnect AI service (port 8765) without prompts — for dev / automation.
cd /d "%~dp0\.."

echo [medConnect] Stopping AI service on port 8765...
for /f "tokens=5" %%P in ('netstat -ano ^| findstr ":8765" ^| findstr "LISTENING"') do (
  taskkill /F /PID %%P >nul 2>&1
)

ping 127.0.0.1 -n 3 >nul

set "RESOLVE=%~dp0resolve_python.bat"
set "PYTHON_EXE="
for /f "delims=" %%P in ('call "%RESOLVE%" 2^>nul') do set "PYTHON_EXE=%%P"

if not defined PYTHON_EXE (
  echo [medConnect] ERROR: No Python found. Run ai_service\install_ai_dependencies.bat
  exit /b 1
)

if not exist storage\logs mkdir storage\logs
set MEDCONNECT_WHISPER_MODEL=small

echo [medConnect] Starting FastAPI on http://127.0.0.1:8765 ...
cd /d "%~dp0"
start "medConnect AI" /MIN "%PYTHON_EXE%" -u -m uvicorn app.main:app --host 127.0.0.1 --port 8765

ping 127.0.0.1 -n 4 >nul
powershell -NoProfile -Command "try { $r = Invoke-WebRequest -Uri 'http://127.0.0.1:8765/health' -UseBasicParsing -TimeoutSec 8; Write-Host ('[medConnect] Health OK status=' + $r.StatusCode) } catch { Write-Host '[medConnect] Health check pending — open http://127.0.0.1:8765/health' }"
exit /b 0
