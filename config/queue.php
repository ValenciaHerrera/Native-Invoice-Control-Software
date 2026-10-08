<?php
declare(strict_types=1);

// Override on both the web host and the designated worker host as needed.
$root = dirname(__DIR__);
$queue = getenv('INVOICE_QUEUE_DIR') ?: $root . '/runtime/queue';
return [
    // Enable ONLY after TI verifies IIS integrated authentication and server variables.
    'corporate_auth' => getenv('INVOICE_CORPORATE_AUTH') ?: 'disabled',
    // TEST ONLY. Exact opt-in; leave unset in corporate deployments.
    'test_allow_anonymous' => getenv('INVOICE_TEST_ALLOW_ANONYMOUS') === '1',
    'pending' => $queue . '/pending',
    'processed' => $queue . '/processed',
    'errors' => $queue . '/errors',
    // For LAN use, set an absolute path on the worker's LOCAL disk.
    'database' => getenv('INVOICE_TEST_DB') ?: $root . '/runtime/data/queue-test.sqlite',
];
