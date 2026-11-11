<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Notifications;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Domain\Auth\CurrentUser;

/**
 * Bandeja personal de notificaciones internas (docs/NOTIFICATIONS.md). NO es auditoría: se marcan leídas y se borran.
 * Cada consulta filtra por el usuario Y la BD lo exige con RLS (usuario_id = fn_actor_actual()): un id ajeno es 404.
 * Las notificaciones las crea la BD (disparadores y fn_notificar*); este servicio sólo lee, marca y borra.
 */
final class NotificationService
{
    public const TIPOS = [
        'CONCILIACION_TERMINADA' => ['bi-clipboard2-check', 'Cotejos'], 'CONCILIACION_ERROR' => ['bi-x-octagon', 'Cotejos'],
        'REPORTE_LISTO' => ['bi-file-earmark-check', 'Reportes'], 'REPORTE_FALLIDO' => ['bi-file-earmark-x', 'Reportes'],
        'IMPORTACION_REVISION' => ['bi-cloud-upload', 'Archivos'], 'ASOCIACIONES_PENDIENTES' => ['bi-link-45deg', 'Identificación'],
        'FUSION_PENDIENTE_APROBACION' => ['bi-people', 'Fusiones'], 'FUSION_DECIDIDA' => ['bi-intersect', 'Fusiones'],
        'SISTEMA' => ['bi-cpu', 'Sistema'],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public function unread(CurrentUser $u): int
    {
        return (int) $this->db->fetchValue('SELECT count(*) FROM notificaciones WHERE usuario_id = :u AND leida_at IS NULL', ['u' => $u->id]);
    }

    /** Resumen para la campana del encabezado (sondeo moderado). @return array{no_leidas:int,ultimas:list<array<string,mixed>>} */
    public function summary(CurrentUser $u, int $limit = 6): array
    {
        return ['no_leidas' => $this->unread($u), 'ultimas' => array_map([self::class, 'present'], $this->db->fetchAll(
            'SELECT id, tipo, prioridad, titulo, mensaje, url, leida_at, created_at FROM notificaciones WHERE usuario_id = :u
              ORDER BY (leida_at IS NULL) DESC, created_at DESC LIMIT ' . max(1, min(20, $limit)), ['u' => $u->id]))];
    }

    /** @param array{estado:string,tipo:string} $f @return array{rows:list<array<string,mixed>>,total:int,page:int} */
    public function paginate(CurrentUser $u, array $f, int $page, int $per): array
    {
        $where = ['usuario_id = :u'];
        $p = ['u' => $u->id];
        if ($f['estado'] === 'NO_LEIDA') {
            $where[] = 'leida_at IS NULL';
        } elseif ($f['estado'] === 'LEIDA') {
            $where[] = 'leida_at IS NOT NULL';
        }
        if (isset(self::TIPOS[$f['tipo']])) {
            $where[] = 'tipo = :t';
            $p['t'] = $f['tipo'];
        }
        $w = implode(' AND ', $where);
        $total = (int) $this->db->fetchValue("SELECT count(*) FROM notificaciones WHERE $w", $p);
        $page = min(max(1, $page), max(1, (int) ceil($total / $per)));
        $rows = $this->db->fetchAll("SELECT id, tipo, prioridad, titulo, mensaje, url, leida_at, created_at FROM notificaciones WHERE $w
                                      ORDER BY created_at DESC, id DESC LIMIT :l OFFSET :o", $p + ['l' => $per, 'o' => ($page - 1) * $per]);
        return ['rows' => array_map([self::class, 'present'], $rows), 'total' => $total, 'page' => $page];
    }

    /** Marca leída y devuelve la URL interna a la que lleva (o null). */
    public function markRead(CurrentUser $u, int $id): ?string
    {
        $row = $this->db->fetchOne('UPDATE notificaciones SET leida_at = coalesce(leida_at, now()) WHERE id = :id AND usuario_id = :u RETURNING url',
            ['id' => $id, 'u' => $u->id]) ?? throw HttpException::notFound();
        return self::safeUrl($row['url']);
    }

    public function markAllRead(CurrentUser $u): int
    {
        return $this->db->execute('UPDATE notificaciones SET leida_at = now() WHERE usuario_id = :u AND leida_at IS NULL', ['u' => $u->id]);
    }

    public function delete(CurrentUser $u, int $id): void
    {
        if ($this->db->execute('DELETE FROM notificaciones WHERE id = :id AND usuario_id = :u', ['id' => $id, 'u' => $u->id]) !== 1) {
            throw HttpException::notFound();
        }
    }

    public function deleteRead(CurrentUser $u): int
    {
        return $this->db->execute('DELETE FROM notificaciones WHERE usuario_id = :u AND leida_at IS NOT NULL', ['u' => $u->id]);
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function present(array $r): array
    {
        [$icon, $area] = self::TIPOS[$r['tipo']] ?? ['bi-bell', 'Aviso'];
        return ['id' => (int) $r['id'], 'tipo' => $r['tipo'], 'prioridad' => $r['prioridad'], 'titulo' => $r['titulo'], 'mensaje' => $r['mensaje'],
                'url' => self::safeUrl($r['url']), 'estado' => $r['leida_at'] === null ? 'NO_LEIDA' : 'LEIDA', 'icono' => $icon, 'area' => $area,
                'created_at' => $r['created_at']];
    }

    /** Sólo rutas internas (la BD ya lo exige; defensa en profundidad antes de usarla como destino de una redirección). */
    public static function safeUrl(?string $url): ?string
    {
        return $url !== null && preg_match('#^/[A-Za-z0-9/_?=&\#.%-]{0,199}$#', $url) === 1 && !str_starts_with($url, '//') ? $url : null;
    }
}
