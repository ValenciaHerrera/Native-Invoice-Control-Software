<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/QueueTest.php';
session_start(); // CSRF/flash only; this is not corporate identity or authentication.
header('Cache-Control: no-store');
$config = require dirname(__DIR__) . '/config/queue.php';
$user = null;
$identityError = '';
$identityWarning = '';
try {
    $identity = CorporateIdentity::resolve($_SERVER, $config);
    $user = $identity['usuario'];
    $identityWarning = $identity['warning'];
} catch (CorporateIdentityUnavailable $exception) {
    http_response_code(403);
    $identityError = $exception->getMessage();
}
$_SESSION['queue_csrf'] ??= bin2hex(random_bytes(32));
$error = '';
$values = ['id' => '', 'nombre' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($user === null) {
            throw new CorporateIdentityUnavailable($identityError);
        }
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['queue_csrf'], $_POST['csrf'])) {
            http_response_code(403);
            throw new InvalidArgumentException('El formulario expiró. Recarga la página antes de enviarlo.');
        }
        $values = ['id' => $_POST['id'] ?? '', 'nombre' => $_POST['nombre'] ?? ''];
        $id = QueueTest::publish($config, QueueTest::data($values));
        $_SESSION['queue_flash'] = $id;
        $_SESSION['queue_csrf'] = bin2hex(random_bytes(32));
        header('Location: /views/queue-test.php', true, 303);
        exit;
    } catch (CorporateIdentityUnavailable $exception) {
        http_response_code(403);
        $identityError = $exception->getMessage();
    } catch (InvalidArgumentException $exception) {
        if (http_response_code() !== 403) {
            http_response_code(422);
        }
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        http_response_code(503);
        error_log('Queue test: ' . $exception->getMessage());
        $error = 'No se pudo generar la solicitud pendiente. Solicita revisar permisos y espacio de la carpeta de entrada.';
    }
}
$pending = $_SESSION['queue_flash'] ?? null;
unset($_SESSION['queue_flash']);
function escape(mixed $value): string {
    return htmlspecialchars(is_string($value) ? $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Prueba de solicitudes pendientes</title>
    <link rel="stylesheet" href="../Bootstrap-style.css">
</head>
<body class="bg-light">
<main class="container py-5" style="max-width: 760px">
    <a href="../index.html">Volver a Reports</a>
    <section class="card border-0 shadow-sm mt-3 p-4">
        <h1 class="h3">Prueba de cambio de proveedor</h1>
        <p>Envía el ID y el nuevo nombre. La solicitud queda pendiente hasta que se ejecute el procesador.</p>
        <p class="text-secondary small">Si el proveedor no existe, el procesador lo crea en la base de prueba.</p>
        <?php if ($identityError): ?>
            <div class="alert alert-danger" role="alert"><?= escape($identityError) ?></div>
        <?php elseif ($identityWarning): ?>
            <div class="alert alert-warning" role="alert"><?= escape($identityWarning) ?></div>
        <?php else: ?>
            <p>Usuario corporativo verificado por el servidor: <strong><?= escape($user) ?></strong></p>
        <?php endif; ?>
        <?php if ($pending): ?>
            <div class="alert alert-success" role="status">Solicitud pendiente: <code><?= escape($pending) ?></code>. El JSON se generó; aún no se confirma su guardado en SQLite.</div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert"><?= escape($error) ?></div>
        <?php endif; ?>
        <form method="post" action="/views/queue-test.php">
            <fieldset <?= $user === null ? 'disabled' : '' ?>>
            <input type="hidden" name="csrf" value="<?= escape($_SESSION['queue_csrf']) ?>">
            <div class="mb-3">
                <label class="form-label" for="provider-id">ID de proveedor</label>
                <input class="form-control" id="provider-id" name="id" type="number" min="1" max="999999999" step="1" required value="<?= escape((string) (is_scalar($values['id']) ? $values['id'] : '')) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label" for="provider-name">Nuevo nombre</label>
                <input class="form-control" id="provider-name" name="nombre" maxlength="120" required value="<?= escape($values['nombre']) ?>">
            </div>
            <button class="btn btn-primary" type="submit">Generar solicitud pendiente</button>
            </fieldset>
        </form>
    </section>
</main>
</body>
</html>
