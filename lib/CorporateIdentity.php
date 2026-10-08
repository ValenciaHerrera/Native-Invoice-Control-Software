<?php
declare(strict_types=1);

final class CorporateIdentityUnavailable extends RuntimeException {}

final class CorporateIdentity
{
    public const CONFIGURATION_HELP = 'No se puede verificar tu identidad corporativa. TI debe configurar IIS con Windows Authentication, deshabilitar acceso anónimo, entregar AUTH_USER y AUTH_TYPE a PHP y habilitar INVOICE_CORPORATE_AUTH=iis-windows en el servidor.';

    public static function username(mixed $value): string
    {
        // Preserve domain/UPN to distinguish identically named accounts in different domains.
        if (!is_string($value) || strlen($value) > 256 ||
            !preg_match('/^(?:[^\\\\\s\p{C}@]+\\\\[^\\\\\s\p{C}@]+|[^\\\\\s\p{C}@]+@[^\\\\\s\p{C}@]+)$/uD', $value)) {
            throw new InvalidArgumentException('El usuario debe ser una identidad corporativa calificada (DOMINIO\\usuario o usuario@dominio).');
        }
        return $value;
    }

    public static function fromServer(array $server, string $mode): string
    {
        // This is an explicit deployment contract, not an implementation of authentication.
        // Never use process environment users, PHP_AUTH_USER, POST, cookies or HTTP_* headers.
        if ($mode !== 'iis-windows' ||
            !is_string($server['SERVER_SOFTWARE'] ?? null) ||
            !preg_match('/^Microsoft-IIS\//i', $server['SERVER_SOFTWARE']) ||
            !is_string($server['AUTH_TYPE'] ?? null) ||
            !in_array(strtolower($server['AUTH_TYPE']), ['negotiate', 'ntlm', 'kerberos'], true)) {
            throw new CorporateIdentityUnavailable(self::CONFIGURATION_HELP);
        }
        try {
            $user = self::username($server['AUTH_USER'] ?? null);
            // Reject conflicting server identities rather than guessing which is the visitor.
            foreach (['REMOTE_USER', 'LOGON_USER'] as $key) {
                if (isset($server[$key]) && $server[$key] !== '' &&
                    (!is_string($server[$key]) || strcasecmp($server[$key], $user) !== 0)) {
                    throw new InvalidArgumentException('Las identidades del servidor no coinciden.');
                }
            }
            return $user;
        } catch (InvalidArgumentException $error) {
            throw new CorporateIdentityUnavailable(self::CONFIGURATION_HELP, 0, $error);
        }
    }

    public static function resolve(array $server, array $config): array
    {
        try {
            return ['usuario' => self::fromServer($server, $config['corporate_auth'] ?? 'disabled'),
                'warning' => ''];
        } catch (CorporateIdentityUnavailable $error) {
            if (($config['test_allow_anonymous'] ?? false) !== true) {
                throw $error;
            }
            return ['usuario' => 'anonymous', 'warning' => $error->getMessage()
                . ' Modo exclusivo de pruebas: se permite enviar como anonymous, sin identidad verificada.'];
        }
    }

    public static function eventUsername(mixed $value): string
    {
        // A worker preserves the recorded author; it never authenticates as the batch account.
        return $value === 'anonymous' ? 'anonymous' : self::username($value);
    }
}
