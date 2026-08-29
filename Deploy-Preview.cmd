@echo off
REM ==========================================================================
REM  Deploy-Preview.cmd - double-click for a DRY RUN (uploads NOTHING).
REM
REM  Shows the exact list of files a real deploy would upload, then stops.
REM  Use this first if you want to check what will go up. When you're happy,
REM  run Deploy.cmd to actually upload.
REM ==========================================================================
setlocal
cd /d "%~dp0"

echo ==========================================================
echo    kapriol.bg  -  DEPLOY PREVIEW (dry run, uploads nothing)
echo ==========================================================
echo.

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0deploy\ftp-deploy.ps1" -DryRun

echo.
echo ----------------------------------------------------------
echo  Preview only - nothing was uploaded. You can close this window.
pause
