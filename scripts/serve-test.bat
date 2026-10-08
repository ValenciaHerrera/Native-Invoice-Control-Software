@echo off
setlocal
set "PROJECT_ROOT=%~dp0.."
set "INVOICE_PHP=%PROJECT_ROOT%\php-8.5.11\php.exe"
"%INVOICE_PHP%" -n -S 127.0.0.1:35 -t "%PROJECT_ROOT%" "%~dp0test-router.php"
exit /b %ERRORLEVEL%
