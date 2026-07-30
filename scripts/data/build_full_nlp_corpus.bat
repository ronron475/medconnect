@echo off
REM Expand Hiligaynon coverage, complaints, scenarios, merge, gate, train.
cd /d "%~dp0..\.."
set PY=ai_service\.venv\Scripts\python.exe
if not exist "%PY%" set PY=python

pushd scripts\data
"%PY%" expand_hiligaynon_nlp_coverage.py
"%PY%" sync_hiligaynon_complaints_to_lexicon.py
"%PY%" expand_hiligaynon_variants.py
"%PY%" sync_training_phrases_to_lexicon.py
"%PY%" build_patient_training_dataset.py
"%PY%" build_realistic_patient_scenarios.py
"%PY%" build_chief_complaint_scenarios.py
"%PY%" build_hiligaynon_complaint_scenarios.py
"%PY%" merge_patient_training_corpus.py
"%PY%" sync_realistic_phrases_to_dictionary.py
popd

echo.
"%PY%" scripts\dev\check_symptom_roundtrip.py
if errorlevel 1 exit /b 1

echo.
echo Training classifier...
"%PY%" ai_service\train_disease_classifier.py --source archive --augment --n-estimators 800 --learning-rate 0.06 --nlp-corpus-max 5000

echo.
echo Test split (ML fast path)...
"%PY%" scripts\dev\evaluate_patient_ml_cases.py --split test --path ml --limit 500

echo.
echo Restart: ai_service\restart_ai_service_silent.bat
exit /b 0
