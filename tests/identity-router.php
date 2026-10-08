<?php
declare(strict_types=1);
// TEST DOUBLE ONLY: launched explicitly by the suite, never by serve-test.bat.
// The test author is fixed in the server process environment, never read from HTTP.
if (PHP_SAPI !== 'cli-server' || !getenv('INVOICE_TEST_FIXTURE_USER')) {
    http_response_code(500);
    exit('Fixture no configurado');
}
$_SERVER['SERVER_SOFTWARE'] = 'Microsoft-IIS/10.0';
$_SERVER['AUTH_TYPE'] = 'Negotiate';
$_SERVER['AUTH_USER'] = getenv('INVOICE_TEST_FIXTURE_USER');
$_SERVER['REMOTE_USER'] = $_SERVER['AUTH_USER'];
$_SERVER['LOGON_USER'] = $_SERVER['AUTH_USER'];
return require dirname(__DIR__) . '/scripts/test-router.php';
