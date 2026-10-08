@echo off
setlocal
set "PROJECT_ROOT=%~dp0.."
set "INVOICE_PHP=%PROJECT_ROOT%\php-8.5.11\php.exe"
if not exist "%INVOICE_PHP%" (
    echo No se encuentra PHP: "%INVOICE_PHP%" 1>&2
    exit /b 1
)
"%INVOICE_PHP%" -n -d "extension_dir=%PROJECT_ROOT%\php-8.5.11\ext" -d extension=pdo_sqlite "%~dp0process-queue.php"
exit /b %ERRORLEVEL%
