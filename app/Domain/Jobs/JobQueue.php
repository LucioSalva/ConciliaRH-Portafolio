<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Jobs;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Settings\SettingsService;
use ConciliaRH\Domain\ValidationException;

final class JobQueue
{
    public const CHANNEL = 'conciliarh_procesos';

    public function __construct(
        private readonly Database $db,
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
    ) {
    }

    /** @param array<string,mixed> $params */
    public function enqueue(
        CurrentUser $actor,
        string $type,
        array $params = [],
        int $priority = 100,
        ?string $idempotencyKey = null,
        int $timeoutSeconds = 1800,
    ): int {
        $params = JobTypes::validate($type, $params);
        if ($priority < 0 || $priority > 1000) {
            throw new ValidationException(['prioridad' => 'Prioridad fuera de rango.']);
        }
        if ($idempotencyKey !== null && !preg_match('/^[A-Za-z0-9:._-]{8,200}$/', $idempotencyKey)) {
            throw new ValidationException(['clave' => 'Clave de idempotencia inválida.']);
        }
        $maxAttempts = $this->settings->int('procesos.max_intentos_predeterminado', 3);

        return $this->db->transaction(function () use ($actor, $type, $params, $priority, $idempotencyKey, $timeoutSeconds, $maxAttempts): int {
            $id = $this->db->fetchValue(
                'INSERT INTO procesos (tipo, contract_version, parametros, clave_idempotencia, prioridad, max_intentos, timeout_segundos, creado_por)
                 VALUES (:t, :cv, CAST(:p AS jsonb), :k, :prio, :max, :timeout, :by)
                 ON CONFLICT (clave_idempotencia) WHERE clave_idempotencia IS NOT NULL DO NOTHING
                 RETURNING id',
                [
                    't' => $type, 'cv' => JobTypes::CONTRACT_VERSION,
                    'p' => json_encode((object) $params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'k' => $idempotencyKey, 'prio' => $priority, 'max' => $maxAttempts,
                    'timeout' => $timeoutSeconds, 'by' => $actor->id,
                ]
            );
            if ($id === null) {
                $prev = $this->db->fetchOne('SELECT id, tipo, creado_por FROM procesos WHERE clave_idempotencia = :k', ['k' => $idempotencyKey]);
                if ($prev === null || $prev['tipo'] !== $type || (int) $prev['creado_por'] !== $actor->id) {
                    throw new ValidationException(['clave' => 'La clave de idempotencia ya pertenece a otro trabajo.']);
                }
                return (int) $prev['id'];
            }
            $id = (int) $id;
            $this->db->execute('SELECT pg_notify(:ch, :payload)', ['ch' => self::CHANNEL, 'payload' => (string) $id]);
            $this->audit->record('procesos.crear', 'procesos', $id, null, ['tipo' => $type, 'parametros' => $params, 'prioridad' => $priority]);
            return $id;
        });
    }

    public function cancel(CurrentUser $actor, int $id): void
    {
        $this->db->transaction(function () use ($actor, $id): void {
            $n = $this->db->execute(
                "UPDATE procesos SET estado = 'CANCELADO', cancelado_por = :by, finalizado_at = now(),
                        mensaje = 'Cancelado por el usuario'
                  WHERE id = :id AND estado = 'PENDIENTE'",
                ['by' => $actor->id, 'id' => $id]
            );
            if ($n === 0) {
                throw new ValidationException(['estado' => 'Sólo pueden cancelarse trabajos pendientes.']);
            }
            $this->audit->record('procesos.cancelar', 'procesos', $id, ['estado' => 'PENDIENTE'], ['estado' => 'CANCELADO']);
        });
    }

    /** @return array<string,mixed> */
    public function find(int $id, bool $withTechnicalDetail): array
    {
        $row = $this->db->fetchOne(
            'SELECT p.id, p.tipo, p.estado, p.etapa, p.progreso, p.mensaje, p.prioridad, p.intentos, p.max_intentos,
                    p.parametros, p.resultado, p.error_codigo, p.error_mensaje, p.error_detalle, p.bloqueado_por,
                    p.created_at, p.iniciado_at, p.finalizado_at, p.heartbeat_at, p.disponible_desde,
                    u.username AS creado_por_username
               FROM procesos p LEFT JOIN usuarios u ON u.id = p.creado_por WHERE p.id = :id',
            ['id' => $id]
        );
        if ($row === null) {
            throw HttpException::notFound();
        }
        if (!$withTechnicalDetail) {
            unset($row['error_detalle'], $row['bloqueado_por']);
        }
        return self::decodeJson($row);
    }

    /** @return list<array<string,mixed>> */
    public function events(int $id): array
    {
        return $this->db->fetchAll(
            'SELECT nivel, mensaje, creado_at FROM proceso_eventos WHERE proceso_id = :id ORDER BY id DESC LIMIT 100',
            ['id' => $id]
        );
    }

    /**
     * @param array{estado?:string,tipo?:string} $f
     * @return array{rows:list<array<string,mixed>>,total:int,page:int}
     */
    public function paginate(array $f, int $page, int $perPage): array
    {
        $where = ['TRUE'];
        $p = [];
        $states = ['PENDIENTE', 'EN_EJECUCION', 'COMPLETADO', 'COMPLETADO_CON_INCIDENCIAS', 'ERROR', 'CANCELADO'];
        if (in_array($f['estado'] ?? '', $states, true)) {
            $where[] = 'p.estado = :estado';
            $p['estado'] = $f['estado'];
        }
        if (in_array($f['tipo'] ?? '', JobTypes::all(), true)) {
            $where[] = 'p.tipo = :tipo';
            $p['tipo'] = $f['tipo'];
        }
        $sql = implode(' AND ', $where);
        $total = (int) $this->db->fetchValue("SELECT count(*) FROM procesos p WHERE $sql", $p);
        $page = min($page, max(1, (int) ceil($total / $perPage)));
        return [
            'total' => $total,
            'page' => $page,
            'rows' => $this->db->fetchAll(
                "SELECT p.id, p.tipo, p.estado, p.etapa, p.progreso, p.intentos, p.max_intentos, p.created_at,
                        p.finalizado_at, u.username AS creado_por_username
                   FROM procesos p LEFT JOIN usuarios u ON u.id = p.creado_por
                  WHERE $sql ORDER BY p.id DESC LIMIT :limit OFFSET :offset",
                [...$p, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage]
            ),
        ];
    }

    /** @return array{pendientes:int,en_ejecucion:int,workers_activos:int,ultimo_latido:?string} */
    public function health(int $heartbeatTimeout): array
    {
        $r = $this->db->fetchOne(
            "SELECT (SELECT count(*) FROM procesos WHERE estado = 'PENDIENTE') AS pendientes,
                    (SELECT count(*) FROM procesos WHERE estado = 'EN_EJECUCION') AS en_ejecucion,
                    (SELECT count(*) FROM workers WHERE detenido_at IS NULL
                        AND ultimo_latido_at > now() - make_interval(secs => :t)) AS workers_activos,
                    (SELECT max(ultimo_latido_at) FROM workers) AS ultimo_latido",
            ['t' => $heartbeatTimeout]
        ) ?? [];
        return [
            'pendientes' => (int) ($r['pendientes'] ?? 0),
            'en_ejecucion' => (int) ($r['en_ejecucion'] ?? 0),
            'workers_activos' => (int) ($r['workers_activos'] ?? 0),
            'ultimo_latido' => $r['ultimo_latido'] ?? null,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function decodeJson(array $row): array
    {
        foreach (['parametros', 'resultado'] as $k) {
            if (isset($row[$k]) && is_string($row[$k])) {
                $row[$k] = json_decode($row[$k], true);
            }
        }
        return $row;
    }
}
