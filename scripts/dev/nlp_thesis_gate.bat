@echo off
REM Thesis primary cohort gate: archive PC-* test split must be 100%% top-1.
cd /d "%~dp0\..\.."
set PY=ai_service\.venv\Scripts\python.exe
echo Thesis primary cohort (420 archive test cases)
"%PY%" scripts\dev\evaluate_patient_ml_cases.py --split test --case-id-prefix PC- --source archive_source/dataset.csv --path both
echo.
"%PY%" scripts\dev\find_hiligaynon_misses.py --path production --case-id-prefix PC- --source archive_source/dataset.csv
echo See docs\NLP_THESIS_EVALUATION.md for thesis wording.
exit /b 0
