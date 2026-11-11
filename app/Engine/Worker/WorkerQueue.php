<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Worker;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\Logger;
use ConciliaRH\Engine\Support\Json;
use InvalidArgumentException;

final class WorkerQueue
{
    public const BACKOFF_BASE = 15;
    public const BACKOFF_MAX = 900;
    public const STAGES = ['ANALIZANDO', 'IMPORTANDO', 'NORMALIZANDO', 'CONCILIANDO', 'VALIDANDO', 'EXPORTANDO', 'FINALIZANDO'];
    private const PHP_REPORT_TYPES = "'GENERAR_REPORTE_PROGRAMADO', 'LIMPIAR_REPORTES_EXPIRADOS'";

    public function __construct(private readonly Database $db, private readonly string $workerId)
    {
    }

    public static function backoff(int $attempt): int
    {
        return min(self::BACKOFF_MAX, self::BACKOFF_BASE * (2 ** max(0, $attempt - 1)));
    }

    /** @param list<string> $types */
    public function claim(array $types, ?int $jobId = null): ?Job
    {
        $row = $this->db->transaction(fn (Database $db) => $db->fetchOne(
            "UPDATE procesos p
                SET estado = 'EN_EJECUCION', intentos = p.intentos + 1, bloqueado_por = :worker, token_bloqueo = gen_random_uuid(),
                    bloqueado_at = now(), heartbeat_at = now(), iniciado_at = coalesce(p.iniciado_at, now()), etapa = NULL,
                    progreso = 0, mensaje = 'En ejecución', error_codigo = NULL, error_mensaje = NULL, error_detalle = NULL
              WHERE p.id = (SELECT id FROM procesos
                             WHERE estado = 'PENDIENTE' AND disponible_desde <= now() AND intentos < max_intentos
                               AND tipo = ANY(CAST(:tipos AS text[]))
                               AND (CAST(:job AS bigint) IS NULL OR id = CAST(:job AS bigint))
                             ORDER BY prioridad, id FOR UPDATE SKIP LOCKED LIMIT 1)
          RETURNING p.id, p.tipo, p.contract_version, p.parametros, p.intentos, p.max_intentos, p.timeout_segundos,
                    p.token_bloqueo::text AS token",
            ['worker' => $this->workerId, 'tipos' => '{' . implode(',', $types) . '}', 'job' => $jobId]));
        if ($row === null) {
            return null;
        }
        $params = is_string($row['parametros']) ? (Json::decode($row['parametros']) ?? []) : [];
        $job = new Job((int) $row['id'], (string) $row['tipo'], (int) $row['contract_version'], is_array($params) ? $params : [],
            (int) $row['intentos'], (int) $row['max_intentos'], (int) $row['timeout_segundos'], (string) $row['token']);
        $this->event($job->id, 'INFO', "Tomado por el procesador (intento {$job->attempts} de {$job->maxAttempts})");
        return $job;
    }

    public function heartbeat(Job $job): bool
    {
        return $this->db->fetchOne(
            "UPDATE procesos SET heartbeat_at = now() WHERE id = :id AND token_bloqueo = CAST(:t AS uuid) AND estado = 'EN_EJECUCION' RETURNING id",
            ['id' => $job->id, 't' => $job->token]) !== null;
    }

    public function progress(Job $job, float $pct, ?string $stage = null, ?string $message = null): bool
    {
        if ($stage !== null && !in_array($stage, self::STAGES, true)) {
            throw new InvalidArgumentException('Etapa no válida: ' . $stage);
        }
        $pct = round(max(0.0, min(100.0, $pct)), 2);
        return $this->db->fetchOne(
            "UPDATE procesos SET progreso = :p, etapa = coalesce(:e, etapa), mensaje = coalesce(:m, mensaje), heartbeat_at = now()
              WHERE id = :id AND token_bloqueo = CAST(:t AS uuid) AND estado = 'EN_EJECUCION' RETURNING id",
            ['p' => (string) $pct, 'e' => $stage, 'm' => $message, 'id' => $job->id, 't' => $job->token]) !== null;
    }

    /** @param array<string,mixed> $result */
    public function complete(Job $job, array $result, string $state = 'COMPLETADO'): bool
    {
        if (!in_array($state, ['COMPLETADO', 'COMPLETADO_CON_INCIDENCIAS'], true)) {
            throw new InvalidArgumentException('Estado final inválido');
        }
        $ok = $this->db->fetchOne(
            "UPDATE procesos SET estado = :s, progreso = 100, etapa = 'FINALIZANDO', resultado = CAST(:r AS jsonb), mensaje = 'Terminado',
                    finalizado_at = now(), bloqueado_por = NULL, token_bloqueo = NULL, error_codigo = NULL, error_mensaje = NULL, error_detalle = NULL
              WHERE id = :id AND token_bloqueo = CAST(:t AS uuid) AND estado = 'EN_EJECUCION' RETURNING id",
            ['s' => $state, 'r' => Json::encode($result), 'id' => $job->id, 't' => $job->token]) !== null;
        if ($ok) {
            $this->event($job->id, 'INFO', 'Terminado: ' . $state);
        }
        return $ok;
    }

    public function fail(Job $job, string $code, string $message, bool $retriable, ?string $detail = null): ?string
    {
        $detail = $detail === null || $detail === '' ? null : substr(Logger::maskText($detail), -4000);
        $retry = $retriable && $job->attempts < $job->maxAttempts;
        $state = $retry ? 'PENDIENTE' : 'ERROR';
        $row = $this->db->fetchOne(
            "UPDATE procesos
                SET estado = :s, disponible_desde = now() + make_interval(secs => :b), finalizado_at = CASE WHEN :s2 = 'ERROR' THEN now() END,
                    bloqueado_por = NULL, token_bloqueo = NULL, error_codigo = :c, error_mensaje = :m, error_detalle = :d,
                    mensaje = CASE WHEN :s3 = 'ERROR' THEN 'Terminado con error' ELSE 'Reintento programado' END
              WHERE id = :id AND token_bloqueo = CAST(:t AS uuid) AND estado = 'EN_EJECUCION' RETURNING id",
            ['s' => $state, 's2' => $state, 's3' => $state, 'b' => $retry ? self::backoff($job->attempts) : 0, 'c' => substr($code, 0, 60),
             'm' => mb_substr($message, 0, 500), 'd' => $detail, 'id' => $job->id, 't' => $job->token]);
        if ($row === null) {
            return null;
        }
        $this->event($job->id, $state === 'ERROR' ? 'ERROR' : 'WARNING',
            "$code: $message" . ($retry ? ' — reintento programado' : ' — sin más reintentos'));
        return $state;
    }

    /** @return list<array<string,mixed>> */
    public function reap(int $heartbeatTimeout): array
    {
        return $this->db->transaction(function (Database $db) use ($heartbeatTimeout): array {
            $rows = $db->fetchAll(
                "WITH stale AS (
                    SELECT id, CASE WHEN bloqueado_at < now() - make_interval(secs => timeout_segundos)
                                    THEN 'TIEMPO_EXCEDIDO' ELSE 'WORKER_ABANDONADO' END AS motivo
                      FROM procesos
                     WHERE estado = 'EN_EJECUCION'
                       AND ((heartbeat_at < now() - make_interval(secs => :hb) AND tipo NOT IN (" . self::PHP_REPORT_TYPES . "))
                            OR bloqueado_at < now() - make_interval(secs => timeout_segundos))
                     FOR UPDATE SKIP LOCKED)
                 UPDATE procesos p
                    SET estado = CASE WHEN p.intentos >= p.max_intentos THEN 'ERROR' ELSE 'PENDIENTE' END,
                        finalizado_at = CASE WHEN p.intentos >= p.max_intentos THEN now() END,
                        disponible_desde = now() + make_interval(secs => LEAST(:mx, :base * power(2, GREATEST(p.intentos - 1, 0)))::int),
                        bloqueado_por = NULL, token_bloqueo = NULL, error_codigo = s.motivo,
                        error_mensaje = CASE WHEN s.motivo = 'TIEMPO_EXCEDIDO' THEN 'El trabajo excedió el tiempo máximo permitido.'
                                             ELSE 'El proceso que atendía el trabajo dejó de responder.' END,
                        mensaje = CASE WHEN p.intentos >= p.max_intentos THEN 'Sin intentos restantes' ELSE 'Reintento programado' END
                   FROM stale s
                  WHERE p.id = s.id
              RETURNING p.id, p.estado, p.error_codigo, p.intentos, p.max_intentos",
                ['hb' => $heartbeatTimeout, 'mx' => self::BACKOFF_MAX, 'base' => self::BACKOFF_BASE]);
            foreach ($rows as $r) {
                $this->event((int) $r['id'], $r['estado'] === 'PENDIENTE' ? 'WARNING' : 'ERROR',
                    "Recuperado por el reaper ({$r['error_codigo']}); nuevo estado {$r['estado']} tras {$r['intentos']} de {$r['max_intentos']} intentos");
            }
            return $rows;
        });
    }

    /** @param array<string,mixed>|null $data */
    public function event(int $jobId, string $level, string $message, ?array $data = null): void
    {
        $this->db->execute('INSERT INTO proceso_eventos (proceso_id, nivel, mensaje, datos) VALUES (:p, :n, :m, CAST(:d AS jsonb))',
            ['p' => $jobId, 'n' => $level, 'm' => mb_substr(Logger::maskText($message), 0, 1000), 'd' => $data === null ? null : Json::encode($data)]);
    }
}
