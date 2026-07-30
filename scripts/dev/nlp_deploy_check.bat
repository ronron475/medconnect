@echo off
REM Pre-deploy NLP smoke test (patient training cases — not a clinical guarantee).
cd /d "%~dp0\..\.."
set PY=ai_service\.venv\Scripts\python.exe
if not exist "%PY%" set PY=python

echo medConnect NLP deploy check
echo ===========================
"%PY%" scripts\dev\evaluate_patient_ml_cases.py --split test --path both
echo.
"%PY%" scripts\dev\find_hiligaynon_misses.py
echo.
echo If top-1 is below 100%% on test split, see docs\nlp-strengthening-guide.md
exit /b 0
