@echo off
REM =====================================================================
REM  ZeroBook - one-click REVERSE sync:  live -> local
REM  Double-click to pull the changes you made online (zerobook.in) back
REM  into this local project. Shows a plan and asks before changing anything.
REM =====================================================================
setlocal
set "BASH=C:\Program Files\Git\bin\bash.exe"
if not exist "%BASH%" set "BASH=C:\laragon\bin\git\bin\bash.exe"

if not exist "%BASH%" (
  echo Could not find git-bash. Install Git for Windows, or fix the BASH path in sync-down.bat.
  pause
  exit /b 1
)

"%BASH%" -lc "cd '%~dp0' && bash sync-down.sh %*"

echo.
echo ==== Sync-down finished. Press any key to close. ====
pause >nul
