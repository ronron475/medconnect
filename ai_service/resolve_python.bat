@echo off
REM Resolve a working Python executable for medConnect AI service (Windows)
setlocal EnableDelayedExpansion

set "ROOT=%~dp0.."
set "VENV_PY=%ROOT%\ai_service\.venv\Scripts\python.exe"

if exist "%VENV_PY%" (
  "%VENV_PY%" --version >nul 2>&1
  if not errorlevel 1 (
    echo %VENV_PY%
    exit /b 0
  )
)

for %%P in (
  "C:\Users\Lenovo\AppData\Local\Programs\Python\Python311\python.exe"
  "C:\Users\Lenovo\AppData\Local\Programs\Python\Python312\python.exe"
) do (
  if exist %%P (
    echo %%~P
    exit /b 0
  )
)

where py >nul 2>&1
if not errorlevel 1 (
  for /f "delims=" %%V in ('py -3.11 -c "import sys; print(sys.executable)" 2^>nul') do (
    if exist "%%V" (
      echo %%V
      exit /b 0
    )
  )
)

where python >nul 2>&1
if not errorlevel 1 (
  for /f "delims=" %%V in ('python -c "import sys; print(sys.executable)" 2^>nul') do (
    if exist "%%V" (
      echo %%V
      exit /b 0
    )
  )
)

exit /b 1
