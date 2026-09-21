@echo off
setlocal

echo ========================================
echo PCTOZ Database Backup
echo ========================================

C:\xampp\mysql\bin\mysqldump.exe -u root pc_care > database\pc_care.sql

if errorlevel 1 (
    echo.
    echo ERROR: Database backup failed.
    pause
    exit /b 1
)

echo.
echo Database backup created successfully.
echo.

git add database\pc_care.sql
git commit -m "DB backup"

if errorlevel 1 (
    echo.
    echo ERROR: Git commit failed.
    pause
    exit /b 1
)

git push

if errorlevel 1 (
    echo.
    echo ERROR: Git push failed.
    pause
    exit /b 1
)

echo.
echo ========================================
echo Database backup pushed successfully.
echo ========================================
pause