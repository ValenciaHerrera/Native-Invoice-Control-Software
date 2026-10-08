<?php
declare(strict_types=1);
// Local test server: allow only existing public views/assets. Never expose runtime/DB/source.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/views/queue-test.php') {
    require dirname(__DIR__) . '/views/queue-test.php';
    return true;
}
if (in_array($path, ['/', '/index.html', '/views/board.html', '/Bootstrap-style.css', '/bootstrap-style.css'], true)) {
    return false;
}
http_response_code(404);
echo 'No encontrado';
return true;
