@echo off
REM Install / repair medConnect AI service Python environment (Windows)
cd /d "%~dp0\.."

set PYTHON_BOOTSTRAP=py -3.11
where py >nul 2>&1 || set PYTHON_BOOTSTRAP=python
where python >nul 2>&1 || (
  echo Python 3.11+ not found. Install from https://www.python.org/downloads/
  echo Enable "Add python.exe to PATH", then run this script again.
  pause
  exit /b 1
)

set VENV_PYTHON=%CD%\ai_service\.venv\Scripts\python.exe

echo Using bootstrap: %PYTHON_BOOTSTRAP%
echo.

if not exist "%VENV_PYTHON%" (
  echo Creating virtual environment in ai_service\.venv ...
  %PYTHON_BOOTSTRAP% -m venv ai_service\.venv
  if errorlevel 1 (
    echo Failed to create venv.
    pause
    exit /b 1
  )
) else (
  echo Checking virtual environment...
  "%VENV_PYTHON%" --version >nul 2>&1
  if errorlevel 1 (
    echo Broken venv detected ^(Python path missing^). Recreating ai_service\.venv ...
    rmdir /s /q ai_service\.venv 2>nul
    %PYTHON_BOOTSTRAP% -m venv ai_service\.venv
    if errorlevel 1 (
      echo Failed to recreate venv.
      pause
      exit /b 1
    )
    set VENV_PYTHON=%CD%\ai_service\.venv\Scripts\python.exe
  ) else (
    echo Virtual environment OK. Repairing pip packages...
  )
)

set PIP_TRUST=--trusted-host pypi.org --trusted-host pypi.python.org --trusted-host files.pythonhosted.org

echo Upgrading pip...
"%VENV_PYTHON%" -m pip install %PIP_TRUST% --upgrade pip setuptools wheel
if errorlevel 1 goto :fail

echo Installing unified FastAPI + NLP dependencies...
"%VENV_PYTHON%" -m pip install %PIP_TRUST% -r ai_service\requirements.txt
if errorlevel 1 goto :fail

echo Installing legacy NLP extras (requirements-nlp.txt) if needed...
"%VENV_PYTHON%" -m pip install %PIP_TRUST% -r ai_service\requirements-nlp.txt
if errorlevel 1 goto :fail

echo Installing optional AI stack (faster-whisper, spacy) — may take several minutes...
"%VENV_PYTHON%" -m pip install %PIP_TRUST% faster-whisper spacy
if errorlevel 1 (
  echo Warning: whisper/spacy install failed. Transcription may be unavailable; NLP validation still works.
)

"%VENV_PYTHON%" -m spacy download en_core_web_sm 2>nul

echo Training disease prediction model (XGBoost on archive_source/dataset.csv)...
"%VENV_PYTHON%" ai_service\train_disease_classifier.py
if errorlevel 1 (
  echo Warning: disease model training failed. Transcript NLP still works; run train_disease_classifier.py manually.
)

echo.
echo Done. Start the unified FastAPI service with:
echo   ai_service\start_ai_service.bat   (NLP + OCR + ML — port 8765)
echo   API docs: http://127.0.0.1:8765/docs
echo.
pause
exit /b 0

:fail
echo Installation failed.
pause
exit /b 1
