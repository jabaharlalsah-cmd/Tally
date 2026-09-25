@echo off
setlocal enabledelayedexpansion
cd /d "%~dp0"

set NOPAUSE=0
if /i "%~1"=="--no-pause" set NOPAUSE=1

echo ============================================
echo   Push updates to GitHub
echo   Folder: %~dp0
echo ============================================
echo.

git rev-parse --is-inside-work-tree >nul 2>&1
if errorlevel 1 (
    echo ERROR: This folder is not a git repository.
    if "%NOPAUSE%"=="0" pause
    exit /b 1
)

for /f "delims=" %%b in ('git rev-parse --abbrev-ref HEAD') do set BRANCH=%%b

if /i "%BRANCH%"=="live"        goto :blocked
if /i "%BRANCH%"=="production"  goto :blocked
if /i "%BRANCH%"=="deploy"      goto :blocked

echo Current branch: %BRANCH%
echo.

git add -A

git diff --cached --quiet
if %errorlevel%==0 (
    echo No local changes to commit - pushing to make sure GitHub is up to date...
) else (
    for /f "delims=" %%d in ('powershell -NoProfile -Command "Get-Date -Format \"yyyy-MM-dd HH:mm\""') do set NOW=%%d
    git commit -m "Update !NOW!" -m "Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
    if not "!errorlevel!"=="0" (
        echo.
        echo COMMIT FAILED - see the message above. Nothing was pushed.
        if "%NOPAUSE%"=="0" pause
        exit /b 1
    )
)

echo.
echo Pushing branch "%BRANCH%" to GitHub (origin)...
echo.
git push origin %BRANCH%

if %errorlevel%==0 (
    echo.
    echo ============================================
    echo   DONE - "%BRANCH%" is up to date on GitHub.
    echo ============================================
) else (
    echo.
    echo ============================================
    echo   PUSH FAILED - see the error above.
    echo ============================================
)
echo.
if "%NOPAUSE%"=="0" pause
exit /b 0

:blocked
echo ============================================
echo   STOPPED FOR SAFETY
echo.
echo   You are on branch "%BRANCH%".
echo   This script never auto-pushes a live/production/
echo   deploy branch - that is only ever updated by you,
echo   manually, on purpose.
echo ============================================
echo.
if "%NOPAUSE%"=="0" pause
exit /b 1
