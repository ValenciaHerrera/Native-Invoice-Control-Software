<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/lib/QueueTest.php';
exit(QueueTest::process(require dirname(__DIR__) . '/config/queue.php'));
