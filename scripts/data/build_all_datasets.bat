@echo off
REM Build official NLP reference datasets for medConnect
cd /d "%~dp0..\.."
echo Building ICD-10-CM conditions from CMS...
python scripts\data\build_icd10_conditions.py
if errorlevel 1 exit /b 1
echo Building clinical allergies dataset...
python scripts\data\build_allergies_official.py
if errorlevel 1 exit /b 1
echo Building comprehensive symptoms database...
python scripts\data\build_comprehensive_symptoms.py
if errorlevel 1 exit /b 1
echo.
echo Apply MySQL schema: mysql -u root medconnect ^< database\schema_nlp_reference.sql
echo Import data: php scripts\data\import_nlp_datasets.php --truncate
echo Done.
