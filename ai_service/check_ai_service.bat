@echo off
echo Checking medConnect AI service...
echo.
powershell -NoProfile -Command "try { (Invoke-WebRequest -Uri 'http://127.0.0.1:8765/health' -UseBasicParsing -TimeoutSec 5).Content } catch { Write-Host 'AI service is NOT running.'; Write-Host 'Start it with: .\start_ai_service.bat' }"
echo.
pause
