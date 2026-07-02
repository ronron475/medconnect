@echo off
REM Stop anything on port 8765 and start a fresh Python AI service.
cd /d "%~dp0\.."

echo Stopping processes listening on port 8765...
for /f "tokens=5" %%P in ('netstat -ano ^| findstr ":8765" ^| findstr "LISTENING"') do (
  echo   taskkill /F /PID %%P
  taskkill /F /PID %%P >nul 2>&1
)

timeout /t 2 /nobreak >nul
call "%~dp0start_ai_service.bat"
