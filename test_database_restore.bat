@echo off
setlocal EnableExtensions
set "MYSQL=C:\xampp\mysql\bin\mysql.exe"
set "TESTDB=pc_care_restore_test"
if "%~1"=="" (
    echo ERROR: SQL dump path missing.
    exit /b 1
)
if not exist "%~1" (
    echo ERROR: SQL dump not found: "%~1"
    exit /b 1
)
echo Recreating TEST database %TESTDB% (never pc_care)...
"%MYSQL%" --user=root --execute="DROP DATABASE IF EXISTS %TESTDB%; CREATE DATABASE %TESTDB% CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if errorlevel 1 exit /b 1
"%MYSQL%" --user=root --default-character-set=utf8mb4 %TESTDB% < "%~1"
if errorlevel 1 (
    echo ERROR: SQL import failed.
    exit /b 1
)
echo Checking restored key tables...
"%MYSQL%" --user=root --database=%TESTDB% --execute="SELECT COUNT(*) AS restored_sessions FROM diagnostic_sessions; SELECT COUNT(*) AS restored_observations FROM observations; SELECT COUNT(*) AS restored_findings FROM session_findings;"
if errorlevel 1 exit /b 1
echo RESTORE TEST PASSED. Test database retained for inspection.
exit /b 0
