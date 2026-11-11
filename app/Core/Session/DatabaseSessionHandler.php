<?php

declare(strict_types=1);

namespace ConciliaRH\Core\Session;

use ConciliaRH\Core\Database;
use SessionHandlerInterface;
use SessionIdInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Sesiones en la tabla `sesiones`.
 * - Se guarda SHA-256 del ID, nunca el ID real (una fuga de la tabla no permite secuestrar sesiones).
 * - Una sesión revocada o inactiva no se lee ni se reactiva.
 * - Con session.use_strict_mode, validateId() rechaza IDs desconocidos (anti fijación).
 */
final class DatabaseSessionHandler implements SessionHandlerInterface, SessionIdInterface, SessionUpdateTimestampHandlerInterface
{
    public function __construct(
        private readonly Database $db,
        private readonly int $idleMinutes,
        private readonly ?string $ip,
        private readonly string $userAgent,
    ) {
    }

    public static function hashId(string $id): string
    {
        return hash('sha256', $id);
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function create_sid(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function validateId(string $id): bool
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $id)) {
            return false;
        }
        return (bool) $this->db->fetchValue(
            "SELECT 1 FROM sesiones WHERE id = :id AND revocada_at IS NULL
               AND ultima_actividad_at > now() - make_interval(mins => :mins)",
            ['id' => self::hashId($id), 'mins' => $this->idleMinutes]
        );
    }

    public function read(string $id): string|false
    {
        $data = $this->db->fetchValue(
            "SELECT datos FROM sesiones WHERE id = :id AND revocada_at IS NULL
               AND ultima_actividad_at > now() - make_interval(mins => :mins)",
            ['id' => self::hashId($id), 'mins' => $this->idleMinutes]
        );
        return is_string($data) ? $data : '';
    }

    public function write(string $id, string $data): bool
    {
        $this->db->execute(
            // El usuario de la sesión NO se escribe aquí: sólo fn_sesion_vincular lo fija, al autenticar.
            "INSERT INTO sesiones (id, datos, ip, user_agent)
             VALUES (:id, :datos, CAST(:ip AS inet), :ua)
             ON CONFLICT (id) DO UPDATE
                SET datos = EXCLUDED.datos, ultima_actividad_at = now()
              WHERE sesiones.revocada_at IS NULL",
            [
                'id' => self::hashId($id),
                'datos' => $data,
                'ip' => $this->ip,
                'ua' => $this->userAgent,
            ]
        );
        return true;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $this->db->execute(
            'UPDATE sesiones SET ultima_actividad_at = now() WHERE id = :id AND revocada_at IS NULL',
            ['id' => self::hashId($id)]
        );
        return true;
    }

    public function destroy(string $id): bool
    {
        $this->db->execute('DELETE FROM sesiones WHERE id = :id', ['id' => self::hashId($id)]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->db->execute(
            "DELETE FROM sesiones
              WHERE (revocada_at IS NULL AND ultima_actividad_at < now() - make_interval(secs => :secs))
                 OR (revocada_at IS NOT NULL AND revocada_at < now() - interval '30 days')",
            ['secs' => $max_lifetime * 2]
        );
    }

    /** Extrae el ID de usuario autenticado de los datos serializados (formato php_serialize). */
    public static function userIdFrom(string $data): ?int
    {
        if ($data === '') {
            return null;
        }
        $decoded = @unserialize($data, ['allowed_classes' => false]);
        $uid = is_array($decoded) ? ($decoded['_auth']['user_id'] ?? null) : null;
        return is_int($uid) ? $uid : null;
    }
}
