<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Security;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\HttpException;

/**
 * Límite de frecuencia por usuario para acciones sensibles, contado sobre la AUDITORÍA (la misma fuente que ya registra
 * cada acción: no hay contadores paralelos que se puedan desincronizar). docs/SECURITY.md §4h.
 */
final class Throttle
{
    /** @param list<string> $acciones */
    public static function check(Database $db, int $userId, array $acciones, int $max, int $segundos, string $mensaje): void
    {
        $n = (int) $db->fetchValue(
            'SELECT count(*) FROM auditoria WHERE usuario_id = :u AND accion = ANY(CAST(:a AS text[])) AND creado_at > now() - make_interval(secs => :s)',
            ['u' => $userId, 'a' => '{' . implode(',', $acciones) . '}', 's' => $segundos]);
        if ($n >= $max) {
            throw new HttpException(429, 'DEMASIADAS_SOLICITUDES', $mensaje);
        }
    }
}
