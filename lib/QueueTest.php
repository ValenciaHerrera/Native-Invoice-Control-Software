<?php
declare(strict_types=1);
require_once __DIR__ . '/CorporateIdentity.php';

final class QueueTest
{
    public static function directory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0770, true) && !is_dir($path)) {
            throw new RuntimeException("No se puede crear el directorio: $path");
        }
    }

    public static function data(array $data): array
    {
        $id = $data['id'] ?? null;
        $name = $data['nombre'] ?? null;
        if ((!is_int($id) && !is_string($id)) ||
            !preg_match('/^[1-9][0-9]{0,8}$/D', (string) $id)) {
            throw new InvalidArgumentException('El ID de proveedor debe ser un entero entre 1 y 999999999.');
        }
        if (!is_string($name)) {
            throw new InvalidArgumentException('El nombre debe ser texto.');
        }
        $name = trim($name);
        if (!preg_match('/^[^\p{C}]{1,120}$/uD', $name)) {
            throw new InvalidArgumentException('El nombre debe tener entre 1 y 120 caracteres, sin caracteres de control.');
        }
        return ['id' => (int) $id, 'nombre' => $name];
    }

    public static function event(array $event): array
    {
        if (($event['version'] ?? null) !== 1 || ($event['tipo'] ?? null) !== 'update_proveedor' ||
            !is_string($event['id'] ?? null) || !preg_match('/^req_[a-f0-9]{32}$/D', $event['id']) ||
            !is_string($event['fecha'] ?? null) || !is_array($event['datos'] ?? null)) {
            throw new InvalidArgumentException('Estructura de solicitud inválida (version, tipo, id, fecha o datos).');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $event['fecha'], new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $event['fecha']) {
            throw new InvalidArgumentException('La fecha debe ser UTC en formato YYYY-MM-DDTHH:MM:SSZ.');
        }
        return ['version' => 1, 'id' => $event['id'], 'tipo' => $event['tipo'],
            'usuario' => CorporateIdentity::eventUsername($event['usuario'] ?? null),
            'fecha' => $event['fecha'], 'datos' => self::data($event['datos'])];
    }

    public static function encode(array $event): string
    {
        return json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }

    public static function publish(array $config, array $data, ?array $server = null): string
    {
        $user = CorporateIdentity::resolve($server ?? $_SERVER, $config)['usuario'];
        $event = self::event(['version' => 1, 'id' => 'req_' . bin2hex(random_bytes(16)),
            'tipo' => 'update_proveedor', 'usuario' => $user,
            'fecha' => gmdate('Y-m-d\TH:i:s\Z'), 'datos' => $data]);
        self::directory($config['pending']);
        $final = $config['pending'] . '/' . $event['id'] . '.json';
        // Same directory/volume; .tmp is never selected by the worker.
        $temporary = $final . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = @fopen($temporary, 'xb');
        if (!$handle) {
            throw new RuntimeException('No se puede crear el JSON temporal en ' . $config['pending']);
        }
        try {
            $json = self::encode($event);
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('No se pudo escribir completamente el JSON temporal.');
            }
            fclose($handle);
            $handle = null;
            if (file_exists($final) || !@rename($temporary, $final)) {
                throw new RuntimeException('No se pudo publicar el JSON pendiente.');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
        return $event['id'];
    }

    public static function database(string $path): PDO
    {
        if (str_starts_with($path, '\\\\') || str_starts_with($path, '//')) {
            throw new RuntimeException('SQLite debe estar en disco local del equipo procesador, no en una ruta UNC.');
        }
        self::directory(dirname($path));
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('PRAGMA journal_mode=DELETE');
        $pdo->exec('PRAGMA synchronous=FULL');
        $pdo->exec('CREATE TABLE IF NOT EXISTS proveedores (id INTEGER PRIMARY KEY, nombre TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS solicitudes (
            id TEXT PRIMARY KEY, proveedor_id INTEGER NOT NULL, nombre TEXT NOT NULL,
            usuario TEXT NOT NULL, fecha TEXT NOT NULL, payload TEXT NOT NULL, confirmado_en TEXT NOT NULL)');
        // Preserve existing test history without inventing authors for older requests.
        $columns = $pdo->query('PRAGMA table_info(solicitudes)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('usuario', $columns, true)) {
            $pdo->exec('ALTER TABLE solicitudes ADD COLUMN usuario TEXT');
        }
        return $pdo;
    }

    private static function persist(PDO $pdo, array $event): void
    {
        $payload = self::encode($event);
        $pdo->beginTransaction();
        try {
            $lookup = $pdo->prepare('SELECT payload FROM solicitudes WHERE id = ?');
            $lookup->execute([$event['id']]);
            $existing = $lookup->fetchColumn();
            if ($existing !== false) {
                if ($existing !== $payload) {
                    throw new InvalidArgumentException('El ID ya fue confirmado con datos distintos.');
                }
                // Replay: do not overwrite a newer provider update.
            } else {
                $pdo->prepare('INSERT INTO solicitudes (id, proveedor_id, nombre, usuario, fecha, payload, confirmado_en)
                    VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$event['id'], $event['datos']['id'],
                        $event['datos']['nombre'], $event['usuario'], $event['fecha'], $payload, gmdate('Y-m-d\TH:i:s\Z')]);
                // Isolated test DB: create the provider if it does not exist yet.
                $pdo->prepare('INSERT INTO proveedores (id, nombre) VALUES (?, ?)
                    ON CONFLICT(id) DO UPDATE SET nombre = excluded.nombre')
                    ->execute([$event['datos']['id'], $event['datos']['nombre']]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private static function move(string $source, string $target): void
    {
        // Never overwrite archived evidence, even on platforms that allow it.
        if (file_exists($target) || !@rename($source, $target)) {
            throw new RuntimeException("No se puede mover $source a $target; el origen se conserva para reintentar.");
        }
    }

    private static function log(array $config, string $file, Throwable $error): void
    {
        $line = gmdate('c') . ' | ' . basename($file) . ' | ' . get_class($error) . ': ' . $error->getMessage() . "\n";
        fwrite(STDERR, $line);
        try {
            self::directory($config['errors']);
            if (@file_put_contents($config['errors'] . '/worker.log', $line, FILE_APPEND | LOCK_EX) === false) {
                fwrite(STDERR, "Tampoco se pudo escribir worker.log; conserve la salida del proceso.\n");
            }
        } catch (Throwable $loggingError) {
            fwrite(STDERR, 'No se pudo registrar el error: ' . $loggingError->getMessage() . "\n");
        }
    }

    public static function process(array $config): int
    {
        $lock = null;
        try {
            self::directory($config['pending']);
            $lock = @fopen($config['pending'] . '/worker.lock', 'c');
            if (!$lock) {
                throw new RuntimeException('No se puede abrir el bloqueo del procesador.');
            }
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                fwrite(STDERR, "Otro procesador tiene el bloqueo. No se procesó ningún archivo.\n");
                return 2;
            }
            // Lock is held until all commits and moves finish; OS releases it on exit/crash.
            self::directory($config['processed']);
            self::directory($config['errors']);
            $pdo = self::database($config['database']);
            $failed = false;
            $files = glob($config['pending'] . '/*.json');
            if ($files === false) {
                throw new RuntimeException('No se puede enumerar la carpeta de pendientes.');
            }
            foreach ($files as $file) {
                try {
                    $raw = @file_get_contents($file);
                    if ($raw === false) {
                        throw new RuntimeException('No se puede leer el JSON pendiente.');
                    }
                    $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                    if (!is_array($decoded)) {
                        throw new InvalidArgumentException('El JSON debe contener un objeto de solicitud.');
                    }
                    $event = self::event($decoded);
                    self::persist($pdo, $event);
                    self::move($file, $config['processed'] . '/' . basename($file));
                    fwrite(STDOUT, 'Confirmado y archivado: ' . $event['id'] . "\n");
                } catch (Throwable $error) {
                    $failed = true;
                    self::log($config, $file, $error);
                    if ($error instanceof JsonException || $error instanceof InvalidArgumentException) {
                        // Invalid inputs require review; transient I/O/DB errors stay pending.
                        try {
                            self::move($file, $config['errors'] . '/' . basename($file));
                        } catch (Throwable $moveError) {
                            self::log($config, $file, $moveError);
                        }
                    }
                }
            }
            return $failed ? 1 : 0;
        } catch (Throwable $error) {
            self::log($config, 'worker', $error);
            return 1;
        } finally {
            if (is_resource($lock)) {
                fclose($lock);
            }
        }
    }
}
