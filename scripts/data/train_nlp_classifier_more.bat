@echo off
REM Train classifier: archive + 500 trees (keeps thesis PC-* gate). Optional corpus mix below.
cd /d "%~dp0\..\..\"
set PY=ai_service\.venv\Scripts\python.exe
if not exist "%PY%" set PY=python
echo Training XGBoost on archive, pipeline-aligned (800 trees) + NLP transcript sample...
"%PY%" ai_service\train_disease_classifier.py --source archive --augment --n-estimators 800 --learning-rate 0.06 --nlp-corpus-max 5000
echo.
echo Symptom round-trip gate (must be 131/131):
"%PY%" scripts\dev\check_symptom_roundtrip.py
echo.
echo Quick thesis gate (PC-* test):
"%PY%" scripts\dev\evaluate_patient_ml_cases.py --split test --case-id-prefix PC- --source archive_source/dataset.csv --path both
echo.
echo Optional: mix full corpus (eval PC-* after; may need higher --archive-repeat):
echo   %PY% ai_service\train_disease_classifier.py --source both --n-estimators 500 --archive-repeat 50
echo Restart AI service: ai_service\restart_ai_service_silent.bat
exit /b 0
