@echo off
REM Fast MySQL LOAD DATA import for NLP reference tables (XAMPP)
cd /d "%~dp0..\.."
echo Importing NLP datasets via LOAD DATA LOCAL INFILE...
C:\xampp\mysql\bin\mysql.exe --local-infile=1 -u root medconnect < scripts\data\import_nlp_load_data.sql
if errorlevel 1 (
  echo Import failed. Ensure local_infile is enabled in my.ini / my.cnf
  exit /b 1
)
echo Done.
