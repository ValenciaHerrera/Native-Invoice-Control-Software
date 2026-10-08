<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/QueueTest.php';
putenv('INVOICE_TEST_ALLOW_ANONYMOUS'); // Test strict defaults regardless of caller environment.

$root = dirname(__DIR__);
$base = $root . '/runtime/tests/' . bin2hex(random_bytes(8));
$config = ['corporate_auth' => 'iis-windows', 'pending' => "$base/queue/pending", 'processed' => "$base/queue/processed",
    'errors' => "$base/queue/errors", 'database' => "$base/data/test.sqlite"];
function fixtureServer(string $user = 'EXAMPLE\\autor-a'): array {
    return ['SERVER_SOFTWARE' => 'Microsoft-IIS/10.0', 'AUTH_TYPE' => 'Negotiate',
        'AUTH_USER' => $user, 'USERNAME' => 'SERVICE_ACCOUNT'];
}
function publishFixture(array $config, array $data): string {
    return QueueTest::publish($config, $data, fixtureServer());
}
function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: $message\n";
}
function rejects(callable $action, string $message): void {
    try {
        $action();
    } catch (Throwable) {
        check(true, $message);
        return;
    }
    check(false, $message);
}
function rowCount(PDO $pdo): int {
    return (int) $pdo->query('SELECT COUNT(*) FROM solicitudes')->fetchColumn();
}

foreach (['EXAMPLE\\autor-a', 'EXAMPLE\\autor-b', 'autor-c@example.invalid'] as $user) {
    check(CorporateIdentity::fromServer(fixtureServer($user), 'iis-windows') === $user, 'identidad calificada del servidor conservada');
}
foreach ([[], ['USERNAME' => 'KENJI', 'USER' => 'KENJI', 'HTTP_AUTH_USER' => 'EXAMPLE\\intruso'],
    array_replace(fixtureServer(), ['AUTH_TYPE' => 'Basic']), array_replace(fixtureServer(), ['SERVER_SOFTWARE' => 'PHP/8.5.11']),
    array_replace(fixtureServer(), ['AUTH_USER' => 'KENJI']), array_replace(fixtureServer(), ['AUTH_USER' => null]),
    array_replace(fixtureServer(), ['LOGON_USER' => 'EXAMPLE\\otra-cuenta'])] as $unverified) {
    rejects(fn() => QueueTest::publish($config, ['id' => 1, 'nombre' => 'A'], $unverified), 'sin identidad corporativa verificable no publica');
}
$disabled = $config;
$disabled['corporate_auth'] = 'disabled';
rejects(fn() => QueueTest::publish($disabled, ['id' => 1, 'nombre' => 'A'], fixtureServer()), 'contrato IIS deshabilitado por defecto bloquea publicación');
check(!is_dir($config['pending']), 'rechazos de identidad no crean archivos ni cola');
check((require $root . '/config/queue.php')['test_allow_anonymous'] === false, 'fallback de pruebas desactivado por defecto');
putenv('INVOICE_TEST_ALLOW_ANONYMOUS=true');
check((require $root . '/config/queue.php')['test_allow_anonymous'] === false, 'fallback requiere opt-in exacto 1');
putenv('INVOICE_TEST_ALLOW_ANONYMOUS');
$anonymousConfig = $config;
$anonymousConfig['test_allow_anonymous'] = true;
$anonymousIdentity = CorporateIdentity::resolve([], $anonymousConfig);
check($anonymousIdentity['usuario'] === 'anonymous' && $anonymousIdentity['warning'] !== '', 'opción de pruebas permite anonymous con warning');
check(CorporateIdentity::resolve(fixtureServer(), $anonymousConfig)['usuario'] === 'EXAMPLE\\autor-a' &&
    CorporateIdentity::resolve(fixtureServer(), $anonymousConfig)['warning'] === '', 'identidad verificada tiene prioridad incluso con fallback activado');
rejects(fn() => CorporateIdentity::fromServer(fixtureServer('anonymous'), 'iis-windows'), 'anonymous no se presenta como identidad corporativa verificada');

$id = publishFixture($config, ['id' => '1', 'nombre' => 'Proveedor Á']);
$source = $config['pending'] . "/$id.json";
$raw = file_get_contents($source);
check(json_decode($raw, true, 32, JSON_THROW_ON_ERROR)['usuario'] === 'EXAMPLE\\autor-a' && glob($config['pending'] . '/*.tmp') === [], 'JSON completo publicado con autor del servidor, sin temporales');
foreach ([['id' => '0', 'nombre' => 'A'], ['id' => '1e2', 'nombre' => 'A'], ['id' => [], 'nombre' => 'A'],
    ['id' => '1', 'nombre' => ' '], ['id' => '1', 'nombre' => []], ['id' => '1', 'nombre' => "A\nB"],
    ['id' => '1', 'nombre' => str_repeat('Á', 121)]] as $invalid) {
    rejects(fn() => publishFixture($config, $invalid), 'rechazo de datos inválidos');
}
check(QueueTest::process($config) === 0, 'procesamiento válido');
$pdo = QueueTest::database($config['database']);
check(rowCount($pdo) === 1 && $pdo->query('SELECT nombre FROM proveedores WHERE id=1')->fetchColumn() === 'Proveedor Á', 'inserción SQLite y actualización de proveedor');
check(!file_exists($source) && is_file($config['processed'] . "/$id.json"), 'movimiento a procesados');
check(QueueTest::process($config) === 0 && rowCount($pdo) === 1, 'reejecución sin duplicados');

// Replay after a newer event: the older request must not revert the provider.
$newId = publishFixture($config, ['id' => 1, 'nombre' => 'Nombre más reciente']);
check(QueueTest::process($config) === 0, 'segunda solicitud confirmada');
unlink($config['processed'] . "/$id.json");
file_put_contents($source, $raw);
check(QueueTest::process($config) === 0 && rowCount($pdo) === 2 &&
    $pdo->query('SELECT nombre FROM proveedores WHERE id=1')->fetchColumn() === 'Nombre más reciente', 'replay no duplica ni revierte cambios posteriores');

$retryId = publishFixture($config, ['id' => 2, 'nombre' => 'Reintento']);
$retrySource = $config['pending'] . "/$retryId.json";
$retryTarget = $config['processed'] . "/$retryId.json";
file_put_contents($retryTarget, 'archivo bloqueante');
check(QueueTest::process($config) === 1 && rowCount($pdo) === 3 && is_file($retrySource), 'commit confirmado con fallo de movimiento conserva pendiente');
unlink($retryTarget);
check(QueueTest::process($config) === 0 && rowCount($pdo) === 3 && !file_exists($retrySource), 'recuperación de movimiento sin duplicados');

file_put_contents($config['pending'] . '/invalid.json', '{');
$bad = json_decode($raw, true);
$bad['datos']['id'] = 0;
file_put_contents($config['pending'] . '/invalid-data.json', QueueTest::encode($bad));
$bad['datos']['id'] = 1;
$bad['datos']['nombre'] = 'ID reutilizado';
file_put_contents($config['pending'] . '/conflict.json', QueueTest::encode($bad));
unset($bad['usuario']);
file_put_contents($config['pending'] . '/missing-user.json', QueueTest::encode($bad));
check(QueueTest::process($config) === 1 && rowCount($pdo) === 3 && is_file($config['errors'] . '/invalid.json') &&
    is_file($config['errors'] . '/invalid-data.json') && is_file($config['errors'] . '/conflict.json') &&
    is_file($config['errors'] . '/missing-user.json') &&
    str_contains(file_get_contents($config['errors'] . '/worker.log'), 'datos distintos'), 'JSON inválido, revalidación y conflicto de ID conservados con error útil');

file_put_contents("$base/blocked", 'no es un directorio');
$blocked = $config;
$blocked['pending'] = "$base/blocked/pending";
rejects(fn() => publishFixture($blocked, ['id' => 1, 'nombre' => 'A']), 'error de escritura no genera éxito');
$persistId = publishFixture($config, ['id' => 3, 'nombre' => 'Persistencia']);
$broken = $config;
$broken['database'] = "$base/broken.sqlite";
file_put_contents($broken['database'], 'no es SQLite');
check(QueueTest::process($broken) === 1 && is_file($config['pending'] . "/$persistId.json") && rowCount($pdo) === 3, 'fallo de BD conserva pendiente');
// Force an error after the request insert, during the provider write.
$pdo->exec("CREATE TRIGGER reject_provider BEFORE INSERT ON proveedores WHEN NEW.id=3 BEGIN SELECT RAISE(ABORT, 'fallo de persistencia de prueba'); END");
check(QueueTest::process($config) === 1 && rowCount($pdo) === 3 && is_file($config['pending'] . "/$persistId.json"), 'transacción revierte inserción si falla el cambio del proveedor');
$pdo->exec('DROP TRIGGER reject_provider');
check(QueueTest::process($config) === 0 && rowCount($pdo) === 4, 'recuperación de persistencia');

putenv('INVOICE_QUEUE_DIR=' . "$base/queue");
putenv('INVOICE_TEST_DB=' . $config['database']);
putenv('INVOICE_CORPORATE_AUTH=iis-windows');
$command = [PHP_BINARY, '-n', '-d', 'extension_dir=' . $root . '/php-8.5.11/ext', '-d', 'extension=pdo_sqlite', $root . '/scripts/process-queue.php'];
function runWorker(array $command): int {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, sys_get_temp_dir());
    fclose($pipes[0]);
    echo stream_get_contents($pipes[1]);
    echo stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($process);
}
$lock = fopen($config['pending'] . '/worker.lock', 'c');
flock($lock, LOCK_EX);
$lockId = publishFixture($config, ['id' => 4, 'nombre' => 'Bloqueo']);
check(runWorker($command) === 2 && is_file($config['pending'] . "/$lockId.json") && rowCount($pdo) === 4, 'segundo proceso real impedido por bloqueo');
fclose($lock);
check(runWorker($command) === 0 && rowCount($pdo) === 5, 'bloqueo liberado al terminar');

// Real HTTP submission, with no database extension in the web process.
// Authentication is a server-side TEST DOUBLE; this does not prove AD/IIS authentication.
putenv('INVOICE_TEST_ALLOW_ANONYMOUS=1');
putenv('INVOICE_TEST_FIXTURE_USER=EXAMPLE\\autor-a');
$port = random_int(20000, 45000);
$server = proc_open([PHP_BINARY, '-n', '-S', "127.0.0.1:$port", '-t', $root, $root . '/tests/identity-router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', "$base/http-out.log", 'a'], 2 => ['file', "$base/http-error.log", 'a']], $pipes);
fclose($pipes[0]);
function request(int $port, string $path, string $cookie = '', ?array $data = null, string $extraHeaders = ''): array {
    $headers = "Connection: close\r\n" . ($cookie ? "Cookie: $cookie\r\n" : '') . $extraHeaders;
    if ($data !== null) {
        $headers .= "Content-Type: application/x-www-form-urlencoded\r\n";
    }
    $context = stream_context_create(['http' => ['method' => $data === null ? 'GET' : 'POST',
        'header' => $headers, 'content' => $data === null ? '' : http_build_query($data),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 5]]);
    $body = file_get_contents("http://127.0.0.1:$port$path", false, $context);
    return ['body' => $body, 'headers' => http_get_last_response_headers()];
}
try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port);
        if ($socket) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    check($ready, 'servidor HTTP de prueba disponible');
    $get = request($port, '/views/queue-test.php');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $get['body'], $token);
    preg_match('/Set-Cookie: ([^;]+)/i', implode("\n", $get['headers']), $cookie);
    $bad = request($port, '/views/queue-test.php', $cookie[1], ['csrf' => $token[1], 'id' => 0, 'nombre' => 'Inválido']);
    check(str_contains($bad['headers'][0], '422') && glob($config['pending'] . '/*.json') === [], 'HTTP rechaza campos inválidos sin generar JSON');
    $csrfBad = request($port, '/views/queue-test.php', $cookie[1], ['id' => 1, 'nombre' => 'A']);
    check(str_contains($csrfBad['headers'][0], '403'), 'HTTP rechaza envío sin token del formulario');
    $post = request($port, '/views/queue-test.php', $cookie[1], ['csrf' => $token[1], 'id' => 5, 'nombre' => 'Formulario', 'usuario' => 'EXAMPLE\\intruso'], "Auth-User: EXAMPLE\\intruso\r\nX-Remote-User: EXAMPLE\\intruso\r\n");
    check(str_contains($post['headers'][0], '303') && count(glob($config['pending'] . '/*.json')) === 1 && rowCount($pdo) === 5, 'HTTP válido genera JSON pendiente sin acceder a SQLite');
    $firstAuthorFile = glob($config['pending'] . '/*.json')[0];
    $firstAuthorEvent = json_decode(file_get_contents($firstAuthorFile), true, 32, JSON_THROW_ON_ERROR);
    check($firstAuthorEvent['usuario'] === 'EXAMPLE\\autor-a', 'autor A proviene del servidor; POST y cabeceras de usuario ignorados');
    $flash = request($port, '/views/queue-test.php', $cookie[1]);
    check(str_contains($flash['body'], 'Solicitud pendiente:') && str_contains($flash['body'], 'aún no se confirma'), 'formulario informa estado pendiente');
    $refresh = request($port, '/views/queue-test.php', $cookie[1]);
    check(count(glob($config['pending'] . '/*.json')) === 1 && !str_contains($refresh['body'], 'Solicitud pendiente:'), 'refresh no reenvía la solicitud');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $refresh['body'], $newToken);
    rename($config['pending'], "$base/pending-saved");
    file_put_contents($config['pending'], 'bloqueo de escritura');
    try {
        $writeFailure = request($port, '/views/queue-test.php', $cookie[1], ['csrf' => $newToken[1], 'id' => 6, 'nombre' => 'Error']);
        check(str_contains($writeFailure['headers'][0], '503') && str_contains($writeFailure['body'], 'No se pudo generar'), 'HTTP no anuncia éxito si falla la escritura');
    } finally {
        unlink($config['pending']);
        rename("$base/pending-saved", $config['pending']);
    }
    check(str_contains(request($port, '/runtime/queue/pending/worker.lock')['headers'][0], '404'), 'servidor no expone archivos internos');
} finally {
    proc_terminate($server);
    proc_close($server);
}

// A second independent server-side authenticated identity, with its own browser session.
putenv('INVOICE_TEST_FIXTURE_USER=EXAMPLE\\autor-b');
$secondPort = random_int(45001, 55000);
$secondServer = proc_open([PHP_BINARY, '-n', '-S', "127.0.0.1:$secondPort", '-t', $root, $root . '/tests/identity-router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', "$base/http-second-out.log", 'a'], 2 => ['file', "$base/http-second-error.log", 'a']], $pipes);
fclose($pipes[0]);
try {
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $secondPort);
        if ($socket) { fclose($socket); break; }
        usleep(100000);
    }
    $secondGet = request($secondPort, '/views/queue-test.php');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $secondGet['body'], $secondToken);
    preg_match('/Set-Cookie: ([^;]+)/i', implode("\n", $secondGet['headers']), $secondCookie);
    $secondPost = request($secondPort, '/views/queue-test.php', $secondCookie[1], ['csrf' => $secondToken[1], 'id' => 6, 'nombre' => 'Segundo autor', 'usuario' => 'EXAMPLE\\autor-a']);
    check(str_contains($secondPost['headers'][0], '303') && count(glob($config['pending'] . '/*.json')) === 2, 'dos sesiones HTTP generan solicitudes independientes');
    $authors = [];
    foreach (glob($config['pending'] . '/*.json') as $file) {
        $event = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        $authors[$event['datos']['id']] = $event['usuario'];
    }
    check($authors[5] === 'EXAMPLE\\autor-a' && $authors[6] === 'EXAMPLE\\autor-b', 'dos autores de fixtures de servidor correctos en JSON');
} finally {
    proc_terminate($secondServer);
    proc_close($secondServer);
}

// Production local router has no authentication and must reject all claimed identities.
putenv('INVOICE_TEST_ALLOW_ANONYMOUS');
$anonymousPort = random_int(55001, 60000);
$anonymousServer = proc_open([PHP_BINARY, '-n', '-S', "127.0.0.1:$anonymousPort", '-t', $root, $root . '/scripts/test-router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', "$base/http-anonymous-out.log", 'a'], 2 => ['file', "$base/http-anonymous-error.log", 'a']], $pipes);
fclose($pipes[0]);
try {
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $anonymousPort);
        if ($socket) { fclose($socket); break; }
        usleep(100000);
    }
    $anonymousGet = request($anonymousPort, '/views/queue-test.php');
    $anonymousPost = request($anonymousPort, '/views/queue-test.php', '', ['id' => 7, 'nombre' => 'Intruso', 'usuario' => 'KENJI'], "Auth-User: EXAMPLE\\intruso\r\nRemote-User: EXAMPLE\\intruso\r\nAuthorization: Basic S0VOSkk6cGFzcw==\r\n");
    check(str_contains($anonymousGet['headers'][0], '403') && str_contains($anonymousGet['body'], 'Windows Authentication') &&
        str_contains($anonymousPost['headers'][0], '403') && count(glob($config['pending'] . '/*.json')) === 2,
        'router real rechaza GET/POST sin identidad, incluso con cabeceras y usuario falsificados');
} finally {
    proc_terminate($anonymousServer);
    proc_close($anonymousServer);
}

if (PHP_OS_FAMILY === 'Windows') {
    check(runWorker(['cmd.exe', '/d', '/c', $root . '\scripts\process-queue.bat']) === 0 && rowCount($pdo) === 7 && glob($config['pending'] . '/*.json') === [], '.bat real procesa las solicitudes HTTP');
    check(runWorker(['cmd.exe', '/d', '/c', $root . '\scripts\process-queue.bat']) === 0 && rowCount($pdo) === 7, '.bat real reejecutado sin duplicados');
    $storedAuthors = $pdo->query('SELECT proveedor_id, usuario FROM solicitudes WHERE proveedor_id IN (5,6)')->fetchAll(PDO::FETCH_KEY_PAIR);
    check($storedAuthors[5] === 'EXAMPLE\\autor-a' && $storedAuthors[6] === 'EXAMPLE\\autor-b', 'SQLite conserva los dos autores correctos');
    foreach (glob($config['processed'] . '/*.json') as $file) {
        $event = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        check(isset($event['usuario']), 'archivo procesado conserva usuario');
    }
}

$testAnonymousPort = random_int(20000, 45000);
putenv('INVOICE_TEST_ALLOW_ANONYMOUS=1');
$testAnonymousServer = proc_open([PHP_BINARY, '-n', '-S', "127.0.0.1:$testAnonymousPort", '-t', $root, $root . '/scripts/test-router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', "$base/http-test-anonymous-out.log", 'a'], 2 => ['file', "$base/http-test-anonymous-error.log", 'a']], $pipes);
fclose($pipes[0]);
putenv('INVOICE_TEST_ALLOW_ANONYMOUS');
try {
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $testAnonymousPort);
        if ($socket) { fclose($socket); break; }
        usleep(100000);
    }
    $testGet = request($testAnonymousPort, '/views/queue-test.php');
    check(str_contains($testGet['headers'][0], '200') && str_contains($testGet['body'], 'alert-warning') &&
        str_contains($testGet['body'], 'como anonymous') && !str_contains($testGet['body'], 'fieldset disabled') &&
        !str_contains($testGet['body'], 'Usuario corporativo verificado'), 'HTTP modo de pruebas muestra warning y habilita formulario sin afirmar identidad');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $testGet['body'], $testToken);
    preg_match('/Set-Cookie: ([^;]+)/i', implode("\n", $testGet['headers']), $testCookie);
    $beforeAnonymous = rowCount($pdo);
    $testPost = request($testAnonymousPort, '/views/queue-test.php', $testCookie[1],
        ['csrf' => $testToken[1], 'id' => 8, 'nombre' => 'Prueba anónima', 'usuario' => 'EXAMPLE\\intruso'],
        "Auth-User: EXAMPLE\\intruso\r\nX-Remote-User: EXAMPLE\\intruso\r\n");
    $anonymousFiles = glob($config['pending'] . '/*.json');
    check(str_contains($testPost['headers'][0], '303') && count($anonymousFiles) === 1 &&
        rowCount($pdo) === $beforeAnonymous, 'HTTP anónimo genera únicamente JSON pendiente');
    $anonymousEvent = json_decode(file_get_contents($anonymousFiles[0]), true, 32, JSON_THROW_ON_ERROR);
    check($anonymousEvent['usuario'] === 'anonymous', 'POST y cabeceras falsificados no sustituyen anonymous');
    $testFlash = request($testAnonymousPort, '/views/queue-test.php', $testCookie[1]);
    check(str_contains($testFlash['body'], 'Solicitud pendiente:') && str_contains($testFlash['body'], 'alert-warning'), 'confirmación pendiente conserva warning de identidad');
    check(QueueTest::process($config) === 0 && rowCount($pdo) === $beforeAnonymous + 1 &&
        $pdo->query('SELECT usuario FROM solicitudes WHERE proveedor_id=8')->fetchColumn() === 'anonymous' &&
        json_decode(file_get_contents($config['processed'] . '/' . $anonymousEvent['id'] . '.json'), true)['usuario'] === 'anonymous',
        'procesador con fallback desactivado conserva anonymous en SQLite y JSON procesado');
    check(QueueTest::process($config) === 0 && rowCount($pdo) === $beforeAnonymous + 1, 'reejecución de solicitud anónima sin duplicados');
    rejects(fn() => QueueTest::publish($config, ['id' => 9, 'nombre' => 'Bloqueado'], []), 'desactivar fallback vuelve a bloquear nuevas solicitudes sin identidad');
    check(glob($config['pending'] . '/*.json') === [], 'sin fallback no queda JSON pendiente tras rechazo');
} finally {
    proc_terminate($testAnonymousServer);
    proc_close($testAnonymousServer);
}

$legacyPath = "$base/data/legacy.sqlite";
$legacy = new PDO('sqlite:' . $legacyPath);
$legacy->exec('CREATE TABLE solicitudes (id TEXT PRIMARY KEY, proveedor_id INTEGER NOT NULL, nombre TEXT NOT NULL, fecha TEXT NOT NULL, payload TEXT NOT NULL, confirmado_en TEXT NOT NULL)');
$legacy->exec("INSERT INTO solicitudes VALUES ('legacy', 1, 'Anterior', '2026-10-08', '{}', '2026-10-08')");
$legacy = null;
$migrated = QueueTest::database($legacyPath);
check(rowCount($migrated) === 1 && $migrated->query("SELECT usuario FROM solicitudes WHERE id='legacy'")->fetchColumn() === null, 'migración conserva historial y no inventa identidad para solicitudes antiguas');
echo "Pruebas completas. Evidencia aislada: $base\n";
