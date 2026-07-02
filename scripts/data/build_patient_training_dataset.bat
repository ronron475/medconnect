@echo off
REM Build patient-style ML training cases from archive_source/dataset.csv
cd /d "%~dp0\..\.."

set PYTHON=ai_service\.venv\Scripts\python.exe
if not exist "%PYTHON%" set PYTHON=python

echo Building patient training dataset...
"%PYTHON%" scripts\data\build_patient_training_dataset.py
if errorlevel 1 (
  echo Build failed.
  pause
  exit /b 1
)

echo.
echo Optional: evaluate ML on patient cases
echo   "%PYTHON%" scripts\dev\evaluate_patient_ml_cases.py --split test --limit 30
echo.
pause
