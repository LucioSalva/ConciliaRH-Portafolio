<?php

declare(strict_types=1);

namespace ConciliaRH\Core\Session;

use RuntimeException;
use SessionHandlerInterface;

/** Sesión nativa de PHP con almacenamiento en PostgreSQL y cookies endurecidas. */
final class NativeSessionStore implements SessionStore
{
    public function __construct(
        private readonly SessionHandlerInterface $handler,
        private readonly string $name,
        private readonly bool $secure,
        private readonly int $idleMinutes,
    ) {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.serialize_handler', 'php_serialize');
        ini_set('session.gc_maxlifetime', (string) ($this->idleMinutes * 60));
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        ini_set('session.cache_limiter', 'nocache');
        session_name($this->name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_set_save_handler($this->handler, true);
        if (!session_start()) {
            throw new RuntimeException('No fue posible iniciar la sesión.');
        }
    }

    public function id(): string
    {
        return (string) session_id();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function invalidate(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }
}
