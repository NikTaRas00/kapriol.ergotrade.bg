@echo off
REM ==========================================================================
REM  Deploy.cmd - double-click to push the site to kapriol.ergotrade.bg
REM
REM  This just launches deploy\ftp-deploy.ps1 (code-only FTP upload). It will:
REM    1. show you the exact list of files it will upload,
REM    2. ask you to confirm (y/N),
REM    3. ask for the FTP password (never stored),
REM    4. upload, then wait so you can read the result.
REM
REM  It NEVER touches host data (products/images/video/private) - safe to run.
REM  To preview without uploading anything, use Deploy-Preview.cmd instead.
REM ==========================================================================
setlocal
cd /d "%~dp0"

echo ==========================================================
echo    kapriol.bg  -  DEPLOY to the live website
echo ==========================================================
echo.

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0deploy\ftp-deploy.ps1"

echo.
echo ----------------------------------------------------------
echo  Finished. You can close this window.
pause
