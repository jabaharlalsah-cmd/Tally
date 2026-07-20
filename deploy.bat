@echo off
REM =====================================================================
REM  ZeroBook - one-click deploy to https://zerobook.in
REM  Double-click this file to build + upload + migrate + cache on live.
REM =====================================================================
setlocal
set "BASH=C:\Program Files\Git\bin\bash.exe"
if not exist "%BASH%" set "BASH=C:\laragon\bin\git\bin\bash.exe"

if not exist "%BASH%" (
  echo Could not find git-bash. Install Git for Windows, or fix the BASH path in deploy.bat.
  pause
  exit /b 1
)

"%BASH%" -lc "cd '%~dp0' && bash deploy.sh"

echo.
echo ==== Deploy finished. Press any key to close. ====
pause >nul
