@echo off
setlocal EnableExtensions
set "PROJECT=C:\xampp\htdocs\pctoz"
set "MYSQL=C:\xampp\mysql\bin\mysql.exe"
set "DUMP=C:\xampp\mysql\bin\mysqldump.exe"
set "BACKUPS=C:\xampp\pctoz_db_backups"
set "LATEST=%PROJECT%\database\pc_care.sql"
set "TESTSCRIPT=%PROJECT%\test_database_restore.bat"

if not exist "%BACKUPS%" mkdir "%BACKUPS%" || goto :fail
if not exist "%PROJECT%\database" mkdir "%PROJECT%\database" || goto :fail
for /f %%T in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd_HHmmss"') do set "STAMP=%%T"
if not defined STAMP goto :fail
set "ARCHIVE=%BACKUPS%\pc_care_%STAMP%.sql"
echo Exporting pc_care to "%ARCHIVE%"...
"%DUMP%" --user=root --single-transaction --routines --triggers --events --default-character-set=utf8mb4 pc_care > "%ARCHIVE%"
if errorlevel 1 goto :fail
for %%F in ("%ARCHIVE%") do if %%~zF LSS 100 goto :fail
if not exist "%TESTSCRIPT%" goto :fail
call "%TESTSCRIPT%" "%ARCHIVE%"
if errorlevel 1 goto :fail
copy /Y "%ARCHIVE%" "%LATEST%" >nul
if errorlevel 1 goto :fail

git -C "%PROJECT%" add -- "database/pc_care.sql"
if errorlevel 1 goto :fail
git -C "%PROJECT%" diff --cached --quiet -- "database/pc_care.sql"
if errorlevel 2 goto :fail
if errorlevel 1 (
    git -C "%PROJECT%" commit -m "DB backup %STAMP%"
    if errorlevel 1 goto :fail
) else (
    echo Database unchanged: no new commit needed.
)
git -C "%PROJECT%" push
if errorlevel 1 goto :fail
echo.
echo SUCCESS: Exported, restore-tested, and pushed to GitHub.
echo Timestamped backup: "%ARCHIVE%"
pause
exit /b 0

:fail
echo.
echo ERROR: Backup or verification failed. Git push was not performed by this run.
echo Check the error above. Do not assume the backup is valid.
pause
exit /b 1
