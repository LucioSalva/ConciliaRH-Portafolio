<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Reports;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Settings\SettingsService;
use ConciliaRH\Domain\ValidationException;
use DateTimeImmutable;
use Throwable;

final class ReportJobRunner
{
    public const SCHEDULER_LOCK = 4850181374600825302;
    private const BACKOFF_BASE = 15;
    private const BACKOFF_MAX = 900;
    private const MIME = ['xlsx' => 'xlsx', 'csv' => 'csv', 'pdf' => 'pdf'];

    public function __construct(
        private readonly Database $db,
        private readonly ReportService $reports,
        private readonly ReportRenderer $renderer,
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
        private readonly string $storagePath,
        private readonly string $workerId,
        private readonly string $timezone,
        private readonly bool $development,
        private readonly ?ReportCipher $cipher = null,
    ) {
    }

    /** Encola lo vencido (idempotente: un scheduler a la vez y una ejecución por hora programada). @return array<string,int|string> */
    public function schedule(?int $max = null): array
    {
        if (!(bool) $this->db->fetchValue('SELECT pg_try_advisory_lock(:k)', ['k' => self::SCHEDULER_LOCK])) {
            return ['omitido' => 'OTRO_SCHEDULER_EN_CURSO'];
        }
        try {
            $out = ['encolados' => 0, 'omitidos_en_curso' => 0, 'abandonados' => $this->sweepAbandoned(), 'limpieza' => 0];
            $max ??= $this->settings->int('reportes.scheduler_max_por_ciclo', 200);
            for ($i = 0; $i < $max; $i++) {
                $done = $this->db->transaction(function () use (&$out): bool {
                    $p = $this->db->fetchOne(
                        'SELECT id, frecuencia, hora, dia_semana, dia_mes, proxima_ejecucion_at FROM reportes_programados
                          WHERE activo AND proxima_ejecucion_at <= now() ORDER BY proxima_ejecucion_at, id LIMIT 1 FOR UPDATE SKIP LOCKED');
                    if ($p === null) {
                        return false;
                    }
                    $gid = $this->db->fetchValue("SELECT fn_reporte_encolar(:id, CAST(:t AS timestamptz), 'PROGRAMADO')",
                        ['id' => (int) $p['id'], 't' => $p['proxima_ejecucion_at']]);
                    $gid === null ? $out['omitidos_en_curso']++ : $out['encolados']++;
                    $due = new DateTimeImmutable((string) $p['proxima_ejecucion_at']);
                    $now = new DateTimeImmutable('now');
                    $next = ScheduleCalculator::next((string) $p['frecuencia'], (int) $p['hora'], $p['dia_semana'] !== null ? (int) $p['dia_semana'] : null,
                        $p['dia_mes'] !== null ? (int) $p['dia_mes'] : null, $due > $now ? $due : $now, $this->timezone);
                    $this->db->execute('UPDATE reportes_programados SET proxima_ejecucion_at = :n WHERE id = :id',
                        ['n' => $next?->format(DATE_ATOM), 'id' => (int) $p['id']]);
                    return true;
                });
                if (!$done) {
                    break;
                }
            }
            $out['limpieza'] = $this->db->fetchValue('SELECT fn_reportes_encolar_limpieza()') === null ? 0 : 1;
            return $out;
        } finally {
            $this->db->fetchValue('SELECT pg_advisory_unlock(:k)', ['k' => self::SCHEDULER_LOCK]);
        }
    }

    private function sweepAbandoned(): int
    {
        $rows = $this->db->fetchAll(
            "UPDATE reportes_generados g SET estado = 'ERROR', error_codigo = 'TRABAJO_ABANDONADO',
                    error_mensaje = 'La generación se interrumpió y agotó sus reintentos.'
               FROM procesos p WHERE p.id = g.proceso_id AND g.estado IN ('PENDIENTE', 'GENERANDO')
                AND p.estado IN ('ERROR', 'CANCELADO', 'COMPLETADO', 'COMPLETADO_CON_INCIDENCIAS')
             RETURNING g.programacion_id");
        foreach ($rows as $r) {
            $this->db->execute("UPDATE reportes_programados SET ultimo_estado = 'ERROR', errores_consecutivos = errores_consecutivos + 1 WHERE id = :p",
                ['p' => (int) $r['programacion_id']]);
        }
        return count($rows);
    }

    /** Atiende hasta $maxJobs trabajos PHP pendientes. @return array<string,int> */
    public function runPending(int $maxJobs = 50): array
    {
        $out = ['generados' => 0, 'errores' => 0, 'limpiezas' => 0];
        for ($i = 0; $i < $maxJobs; $i++) {
            $job = $this->claim();
            if ($job === null) {
                break;
            }
            try {
                if ($job['tipo'] === 'GENERAR_REPORTE_PROGRAMADO') {
                    $this->generate($job) ? $out['generados']++ : $out['errores']++;
                } else {
                    $this->complete($job, $this->cleanup());
                    $out['limpiezas']++;
                }
            } catch (Throwable $e) {
                $out['errores']++;
                $this->fail($job, 'ERROR_INTERNO', 'Ocurrió un error inesperado al procesar el trabajo.', true, $e::class);
            }
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public function claim(): ?array
    {
        $row = $this->db->fetchOne(
            "UPDATE procesos p SET estado = 'EN_EJECUCION', intentos = p.intentos + 1, bloqueado_por = :w, token_bloqueo = gen_random_uuid(),
                    bloqueado_at = now(), heartbeat_at = now(), iniciado_at = coalesce(p.iniciado_at, now()), etapa = 'EXPORTANDO', progreso = 0,
                    mensaje = 'En ejecución', error_codigo = NULL, error_mensaje = NULL, error_detalle = NULL
              WHERE p.id = (SELECT id FROM procesos WHERE estado = 'PENDIENTE' AND disponible_desde <= now() AND intentos < max_intentos
                              AND tipo IN ('GENERAR_REPORTE_PROGRAMADO', 'LIMPIAR_REPORTES_EXPIRADOS')
                             ORDER BY prioridad, id FOR UPDATE SKIP LOCKED LIMIT 1)
             RETURNING p.id, p.tipo, p.parametros, p.intentos, p.max_intentos, p.token_bloqueo::text AS token", ['w' => $this->workerId]);
        if ($row === null) {
            return null;
        }
        $row['parametros'] = json_decode((string) $row['parametros'], true) ?: [];
        return $row;
    }

    /** @param array<string,mixed> $job */
    private function generate(array $job): bool
    {
        $gid = (int) ($job['parametros']['reporte_generado_id'] ?? 0);
        $g = $this->db->fetchOne("UPDATE reportes_generados SET estado = 'GENERANDO', error_codigo = NULL, error_mensaje = NULL
                                   WHERE id = :id AND estado IN ('PENDIENTE', 'GENERANDO') RETURNING *", ['id' => $gid]);
        if ($g === null) {
            $this->complete($job, ['reporte_generado_id' => $gid, 'omitido' => 'ESTADO_CAMBIO']);
            return true;
        }
        $p = $this->db->fetchOne('SELECT * FROM reportes_programados WHERE id = :id', ['id' => (int) $g['programacion_id']]);
        $user = $this->owner((int) $g['propietario_id']);
        $this->heartbeat($job);
        if ($user === null || !$user->can('reportes.programar') || !$user->can('reportes.exportar')
            || ((bool) $g['sensible'] && !ReportService::canExportIds($user))
            || ($g['tipo'] === 'incidencias' && !$user->can('incidencias.ver'))) {
            return $this->finalError($job, $g, 'PERMISO_REVOCADO', 'El dueño de la programación ya no tiene permiso para este reporte; la programación se pausó.', true);
        }
        $c = $this->conciliation($p);
        if ($c === null) {
            $this->db->transaction(function () use ($job, $g, $p): void {
                $this->db->execute("UPDATE reportes_generados SET estado = 'SIN_DATOS', error_codigo = 'SIN_CONCILIACION',
                                           error_mensaje = 'No hay una conciliación terminada para el alcance programado.' WHERE id = :id", ['id' => (int) $g['id']]);
                $this->db->execute("UPDATE reportes_programados SET ultima_ejecucion_at = now(), ultimo_estado = 'SIN_DATOS' WHERE id = :id", ['id' => (int) $p['id']]);
                $this->complete($job, ['reporte_generado_id' => (int) $g['id'], 'estado' => 'SIN_DATOS']);
            });
            return true;
        }
        $usado = (int) $this->db->fetchValue("SELECT coalesce(sum(bytes), 0) FROM reportes_generados WHERE propietario_id = :u AND estado = 'DISPONIBLE'",
            ['u' => (int) $g['propietario_id']]);
        if ($usado > $this->settings->int('reportes.max_mb_por_usuario', 500) * 1048576) {
            return $this->finalError($job, $g, 'CUOTA_ALMACENAMIENTO', 'El dueño alcanzó su espacio máximo de reportes generados; espere a que expiren.', false);
        }
        $tmp = null;
        $final = null;
        try {
            $data = $this->reports->build($user, (string) $g['tipo'], (int) $c['id'], ['sensible' => (bool) $g['sensible'], 'otra' => null]);
            $this->heartbeat($job);
            $requeridos = array_values(array_filter(['conciliaciones.ver', 'reportes.exportar',
                $user->can('incidencias.ver') && in_array($g['tipo'], ['resumen', 'incidencias'], true) ? 'incidencias.ver' : null,
                $g['tipo'] === 'pendientes' ? 'asociaciones.ver' : null,
                (bool) $g['sensible'] ? 'reportes.exportar_sensible' : null, (bool) $g['sensible'] ? 'empleados.ver_sensible' : null]));
            $ext = self::MIME[strtolower((string) $g['formato'])];
            $fecha = (new DateTimeImmutable('now', new \DateTimeZone($this->timezone)))->format('d/m/Y H:i');
            $tmp = $this->renderer->reserve($ext);
            $tmp = match ($ext) {
                'xlsx' => $this->renderer->xlsx($data, $user->username . ' (programado)', $fecha),
                'csv' => $this->renderer->csv($data, $user->username . ' (programado)', $fecha),
                'pdf' => $this->renderer->pdf($data, $user->username . ' (programado)', $fecha),
            };
            $this->heartbeat($job);
            $cifrar = ReportService::requiresEncryption((string) $g['tipo'], (bool) $g['sensible']);
            $llave = null;
            if ($cifrar) {
                if ($this->cipher === null) {
                    return $this->finalError($job, $g, 'CIFRADO_NO_CONFIGURADO', 'Falta la llave de cifrado de reportes (REPORT_ENC_KEYS); no se guardó nada.', false);
                }
                $enc = $tmp . '.enc';
                $llave = $this->cipher->encryptFile($tmp, $enc, 'g' . (int) $g['id']);
                @unlink($tmp);
                $tmp = $enc;
            }
            $rel = sprintf('generados/%s/%s.%s%s', date('Y/m'), bin2hex(random_bytes(16)), $ext, $cifrar ? '.enc' : '');
            $final = rtrim($this->storagePath, '/') . '/reportes/' . $rel;
            if (!is_dir(dirname($final)) && !@mkdir(dirname($final), 0750, true) && !is_dir(dirname($final))) {
                throw new \RuntimeException('No se pudo crear el directorio de reportes.');
            }
            if (!@rename($tmp, $final)) {
                throw new \RuntimeException('No se pudo mover el reporte a su ubicación final.');
            }
            $tmp = null;
            @chmod($final, 0640);
            if (($fh = @fopen($final, 'rb')) !== false) {                  // durable antes de registrarlo (fsync también del agregado en claro)
                if (function_exists('fsync')) {
                    @fsync($fh);
                }
                fclose($fh);
            }
            $sha = (string) hash_file('sha256', $final);
            $bytes = (int) filesize($final);
            $ok = $this->db->transaction(function () use ($job, $g, $p, $c, $data, $rel, $sha, $bytes, $requeridos, $llave): bool {
                if ($this->db->fetchOne("SELECT id FROM procesos WHERE id = :id AND token_bloqueo = CAST(:t AS uuid) AND estado = 'EN_EJECUCION' FOR UPDATE",
                        ['id' => (int) $job['id'], 't' => $job['token']]) === null) {
                    return false;
                }
                $n = $this->db->execute(
                    "UPDATE reportes_generados SET estado = 'DISPONIBLE', conciliacion_id = :c, conciliacion_numero = :n, periodo_id = :pe, lote_id = :l,
                            ruta = :r, sha256 = :s, bytes = :b, filas = :f, huella_contenido = :h, generado_at = now(), permisos_requeridos = CAST(:pr AS text[]),
                            expira_at = now() + make_interval(days => :d), error_codigo = NULL, error_mensaje = NULL,
                            cifrado = :cif, cifrado_llave = :ll, cifrado_at = CASE WHEN :cif2 THEN now() END
                      WHERE id = :id AND estado IN ('PENDIENTE', 'GENERANDO') AND ruta IS NULL",
                    ['cif' => $llave !== null ? 't' : 'f', 'll' => $llave, 'cif2' => $llave !== null ? 't' : 'f', 'c' => (int) $c['id'], 'n' => (int) $c['numero'], 'pe' => (int) $c['periodo_id'], 'l' => (int) $c['lote_id'], 'r' => $rel, 's' => $sha,
                     'b' => $bytes, 'f' => (int) $data['filas'], 'h' => $data['huella'], 'd' => (int) $p['retencion_dias'],
                     'pr' => '{' . implode(',', $requeridos) . '}', 'id' => (int) $g['id']]);
                if ($n !== 1) {
                    return false;
                }
                $this->db->execute("UPDATE reportes_programados SET ultima_ejecucion_at = now(), ultimo_estado = 'DISPONIBLE', errores_consecutivos = 0
                                     WHERE id = :id", ['id' => (int) $p['id']]);
                $this->audit->record('reportes.generar', 'reportes_generados', (int) $g['id'], null, null, [
                    'programacion_id' => (int) $p['id'], 'propietario_id' => (int) $g['propietario_id'], 'tipo' => $g['tipo'], 'formato' => $g['formato'],
                    'sensible' => (bool) $g['sensible'], 'conciliacion_id' => (int) $c['id'], 'filas' => (int) $data['filas'], 'sha256' => $sha,
                    'bytes' => $bytes, 'origen' => $g['origen'], 'cifrado' => $llave !== null, 'llave' => $llave]);
                $this->complete($job, ['reporte_generado_id' => (int) $g['id'], 'estado' => 'DISPONIBLE', 'bytes' => $bytes]);
                return true;
            });
            if ($ok) {
                $final = null;                                   // se conserva; si no, el finally lo borra (otro consumidor ganó)
            }
            return $ok;
        } catch (HttpException) {
            return $this->finalError($job, $g, 'PERMISO_REVOCADO', 'El dueño de la programación no tiene permiso para este reporte; la programación se pausó.', true);
        } catch (ValidationException $e) {
            return $this->finalError($job, $g, 'REPORTE_INVALIDO', mb_substr(implode(' ', $e->errors()), 0, 250), false);
        } catch (Throwable $e) {
            return $this->retryOrFail($job, $g, 'ERROR_GENERACION', 'No se pudo generar el reporte; se reintentará si quedan intentos.', $e::class);
        } finally {
            foreach ([$tmp, $final] as $f) {
                if ($f !== null && is_file($f)) {
                    @unlink($f);                                     // nunca queda un archivo corrupto o huérfano
                }
            }
        }
    }

    /** Borra los archivos expirados (conserva la fila y su huella); salta los que se están descargando. @return array<string,int> */
    public function cleanup(int $limit = 1000): array
    {
        $out = ['expirados' => 0, 'en_uso' => 0, 'sin_archivo' => 0];
        $svc = new ScheduledReportService($this->db, $this->audit, $this->settings, $this->storagePath, $this->timezone, $this->cipher);
        foreach ($this->db->fetchAll("SELECT id, ruta FROM reportes_generados WHERE ruta IS NOT NULL AND archivo_eliminado_at IS NULL
                                        AND expira_at < now() ORDER BY expira_at LIMIT :n", ['n' => $limit]) as $r) {
            $this->db->transaction(function () use ($r, $svc, &$out): void {
                if (!(bool) $this->db->fetchValue('SELECT pg_try_advisory_xact_lock(:k)', ['k' => ScheduledReportService::fileLock((int) $r['id'])])) {
                    $out['en_uso']++;                              // una descarga en curso: se reintenta en la próxima limpieza
                    return;
                }
                $path = $svc->resolve((string) $r['ruta']);
                if (is_file($path)) {
                    @unlink($path);
                } else {
                    $out['sin_archivo']++;
                }
                $this->db->execute("UPDATE reportes_generados SET estado = 'EXPIRADO', archivo_eliminado_at = now() WHERE id = :id", ['id' => (int) $r['id']]);
                $out['expirados']++;
            });
        }
        if ($out['expirados'] + $out['en_uso'] > 0) {
            $this->audit->record('reportes.expirar', 'reportes_generados', null, null, null, $out);
        }
        return $out;
    }

    private function owner(int $id): ?CurrentUser
    {
        $j = $this->db->fetchValue('SELECT fn_servicio_usuario(:id)', ['id' => $id]);
        $u = is_string($j) ? json_decode($j, true) : null;
        if (!is_array($u) || !$u['activo'] || ($u['es_desarrollo'] && !$this->development)) {
            return null;
        }
        return new CurrentUser((int) $u['id'], (string) $u['username'], (string) $u['nombre'], false,
            array_values(array_map('strval', $u['roles'] ?? [])), array_values(array_map('strval', $u['permisos'] ?? [])));
    }

    /** @param array<string,mixed> $p @return array<string,mixed>|null */
    private function conciliation(array $p): ?array
    {
        $fin = "('COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS', 'REVISION_MANUAL', 'CERRADA')";
        if ($p['alcance'] === 'LOTE') {
            return $this->db->fetchOne("SELECT id, numero, periodo_id, lote_id FROM conciliaciones WHERE lote_id = :l AND estado IN $fin
                                         ORDER BY numero DESC LIMIT 1", ['l' => (int) $p['lote_id']]);
        }
        return $this->db->fetchOne("SELECT c.id, c.numero, c.periodo_id, c.lote_id FROM conciliaciones c
                                      JOIN periodos_nomina pe ON pe.id = c.periodo_id JOIN lotes_importacion l ON l.id = c.lote_id AND l.estado <> 'ANULADO'
                                     WHERE c.estado IN $fin ORDER BY pe.anio DESC, pe.mes DESC, pe.quincena DESC, c.lote_oficial DESC, c.numero DESC LIMIT 1");
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $g */
    private function finalError(array $job, array $g, string $code, string $msg, bool $pause): bool
    {
        $this->db->transaction(function () use ($job, $g, $code, $msg, $pause): void {
            if ($this->db->execute("UPDATE reportes_generados SET estado = 'ERROR', error_codigo = :c, error_mensaje = :m
                                     WHERE id = :id AND estado IN ('PENDIENTE', 'GENERANDO')",
                    ['c' => $code, 'm' => $msg, 'id' => (int) $g['id']]) !== 1) {
                return;
            }
            $this->db->execute("UPDATE reportes_programados SET ultima_ejecucion_at = now(), ultimo_estado = 'ERROR',
                                       errores_consecutivos = errores_consecutivos + 1,
                                       activo = CASE WHEN :p THEN false ELSE activo END,
                                       proxima_ejecucion_at = CASE WHEN :p2 THEN NULL ELSE proxima_ejecucion_at END,
                                       pausa_motivo = CASE WHEN :p3 THEN :m ELSE pausa_motivo END WHERE id = :id",
                ['p' => $pause ? 'true' : 'false', 'p2' => $pause ? 'true' : 'false', 'p3' => $pause ? 'true' : 'false', 'm' => $msg,
                 'id' => (int) $g['programacion_id']]);
            $this->audit->record('reportes.generar_error', 'reportes_generados', (int) $g['id'], null, null,
                ['programacion_id' => (int) $g['programacion_id'], 'codigo' => $code, 'pausada' => $pause]);
        });
        $this->fail($job, $code, $msg, false, null);
        return false;
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $g */
    private function retryOrFail(array $job, array $g, string $code, string $msg, string $detail): bool
    {
        $willRetry = (int) $job['intentos'] < (int) $job['max_intentos'];
        if (!$willRetry) {
            $pausar = (int) $this->db->fetchValue('SELECT errores_consecutivos + 1 FROM reportes_programados WHERE id = :id', ['id' => (int) $g['programacion_id']])
                >= $this->settings->int('reportes.errores_para_pausar', 3);
            $this->finalError($job, $g, $code, $pausar ? 'La generación falló varias veces seguidas; la programación se pausó.' : $msg, $pausar);
            return false;
        }
        $this->db->execute("UPDATE reportes_generados SET estado = 'PENDIENTE', error_codigo = :c, error_mensaje = :m
                             WHERE id = :id AND estado IN ('PENDIENTE', 'GENERANDO')", ['c' => $code, 'm' => $msg, 'id' => (int) $g['id']]);
        $this->fail($job, $code, $msg, true, $detail);
        return false;
    }

    /** @param array<string,mixed> $job */
    private function heartbeat(array $job): void
    {
        $this->db->execute("UPDATE procesos SET heartbeat_at = now() WHERE id = :id AND token_bloqueo = CAST(:t AS uuid) AND estado = 'EN_EJECUCION'",
            ['id' => (int) $job['id'], 't' => $job['token']]);
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $result */
    private function complete(array $job, array $result): void
    {
        $this->db->execute(
            "UPDATE procesos SET estado = 'COMPLETADO', progreso = 100, etapa = 'FINALIZANDO', resultado = CAST(:r AS jsonb), mensaje = 'Terminado',
                    finalizado_at = now(), bloqueado_por = NULL, token_bloqueo = NULL, error_codigo = NULL, error_mensaje = NULL, error_detalle = NULL
              WHERE id = :id AND token_bloqueo = CAST(:t AS uuid) AND estado = 'EN_EJECUCION'",
            ['r' => json_encode($result, JSON_THROW_ON_ERROR), 'id' => (int) $job['id'], 't' => $job['token']]);
    }

    /** @param array<string,mixed> $job */
    private function fail(array $job, string $code, string $msg, bool $retriable, ?string $detail): void
    {
        $retry = $retriable && (int) $job['intentos'] < (int) $job['max_intentos'];
        $this->db->execute(
            "UPDATE procesos SET estado = :e, disponible_desde = now() + make_interval(secs => :b),
                    finalizado_at = CASE WHEN :e2 = 'ERROR' THEN now() END, bloqueado_por = NULL, token_bloqueo = NULL,
                    error_codigo = :c, error_mensaje = :m, error_detalle = :d,
                    mensaje = CASE WHEN :e3 = 'ERROR' THEN 'Terminado con error' ELSE 'Reintento programado' END
              WHERE id = :id AND token_bloqueo = CAST(:t AS uuid) AND estado = 'EN_EJECUCION'",
            ['e' => $retry ? 'PENDIENTE' : 'ERROR', 'e2' => $retry ? 'PENDIENTE' : 'ERROR', 'e3' => $retry ? 'PENDIENTE' : 'ERROR',
             'b' => $retry ? min(self::BACKOFF_MAX, self::BACKOFF_BASE * (2 ** max(0, (int) $job['intentos'] - 1))) : 0,
             'c' => mb_substr($code, 0, 60), 'm' => mb_substr($msg, 0, 500), 'd' => $detail, 'id' => (int) $job['id'], 't' => $job['token']]);
    }
}
