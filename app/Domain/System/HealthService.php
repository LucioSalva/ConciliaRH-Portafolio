<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\System;

use ConciliaRH\Core\Database;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Imports\StorageAuditor;
use ConciliaRH\Domain\Security\Throttle;

final class HealthService
{
    public const EXTENSIONES = ['pdo_pgsql', 'sodium', 'openssl', 'mbstring', 'bcmath', 'zip', 'fileinfo', 'intl', 'xmlreader', 'dom', 'iconv'];

    public function __construct(
        private readonly Database $db,
        private readonly AuditLogger $audit,
        private readonly StorageAuditor $storage,
        private readonly string $storagePath,
        private readonly string $basePath,
        private readonly string $version,
        private readonly int $workerTimeout,
    ) {
    }

    /** @return array<string,array<string,mixed>> */
    public function checks(): array
    {
        return [
            'aplicacion' => $this->app(),
            'base_datos' => $this->database(),
            'migraciones' => $this->migrations(),
            'workers' => $this->workers(),
            'cola' => $this->queue(),
            'scheduler' => $this->lastRun('SCHEDULER', 15, 'El scheduler no ha corrido en los últimos 15 minutos: revise el timer de systemd/cron.'),
            'almacenamiento' => $this->storageCheck(),
            'espacio' => $this->disk(),
            'reportes' => $this->reports(),
            'respaldo' => $this->lastRun('RESPALDO', 26 * 60, 'No hay un respaldo registrado en las últimas 26 horas.'),
        ];
    }

    public static function overall(array $checks): string
    {
        $orden = ['OK' => 0, 'SIN_DATOS' => 0, 'ADVERTENCIA' => 1, 'ERROR' => 2];
        $peor = 'OK';
        foreach ($checks as $c) {
            if (($orden[$c['estado']] ?? 0) > $orden[$peor]) {
                $peor = $c['estado'];
            }
        }
        return $peor;
    }

    /** @return list<array<string,mixed>> procesos con error en 24 h y trabajos atascados (sólo tipo, código y fechas) */
    public function recentErrors(): array
    {
        return $this->db->fetchAll(
            "SELECT id, tipo, estado, error_codigo, finalizado_at, created_at, intentos FROM procesos
              WHERE (estado = 'ERROR' AND finalizado_at > now() - interval '24 hours')
                 OR (estado = 'EN_EJECUCION' AND (coalesce(heartbeat_at, bloqueado_at) < now() - interval '10 minutes' OR iniciado_at < now() - make_interval(secs => timeout_segundos)))
              ORDER BY coalesce(finalizado_at, created_at) DESC LIMIT 20");
    }

    /** @return list<array<string,mixed>> */
    public function workerList(): array
    {
        return $this->db->fetchAll(
            'SELECT id, hostname, version, iniciado_at, ultimo_latido_at, detenido_at, proceso_actual_id,
                    (detenido_at IS NULL AND ultimo_latido_at > now() - make_interval(secs => :t)) AS vivo
               FROM workers ORDER BY (detenido_at IS NULL) DESC, ultimo_latido_at DESC LIMIT 20', ['t' => $this->workerTimeout]);
    }

    public function runStorageCheck(CurrentUser $u): array
    {
        Throttle::check($this->db, $u->id, ['sistema.revisar_almacenamiento'], 1, 300, 'La revisión de almacenamiento se ejecutó hace menos de 5 minutos.');
        $inicio = date(DATE_ATOM);
        $r = $this->storage->audit(false);
        $d = ['revisados' => $r['revisados'], 'huerfanos' => count($r['huerfanos']), 'staging' => count($r['staging']), 'faltantes' => count($r['faltantes'])];
        $res = $d['huerfanos'] + $d['staging'] + $d['faltantes'] === 0 ? 'OK' : 'ADVERTENCIA';
        $this->db->fetchValue("SELECT fn_sistema_ejecucion('ALMACENAMIENTO', 'WEB', CAST(:i AS timestamptz), :r, CAST(:d AS jsonb))",
            ['i' => $inicio, 'r' => $res, 'd' => json_encode($d)]);
        $this->audit->record('sistema.revisar_almacenamiento', 'sistema', 'almacenamiento', null, null, $d + ['resultado' => $res]);
        return $d + ['resultado' => $res];
    }

    /** @return array<string,mixed> */
    private function app(): array
    {
        $faltan = array_values(array_filter(self::EXTENSIONES, static fn ($e) => !extension_loaded($e)));
        $build = \ConciliaRH\Core\BuildInfo::read($this->basePath, $this->version);
        return ['estado' => $faltan === [] ? 'OK' : 'ERROR', 'version' => $this->version, 'php' => PHP_VERSION, 'build' => $build,
                'detalle' => $faltan === [] ? 'Extensiones requeridas presentes.' : 'Faltan extensiones de PHP: ' . implode(', ', $faltan) . '.',
                'extensiones' => array_map(static fn ($e) => ['nombre' => $e, 'ok' => extension_loaded($e)], self::EXTENSIONES)];
    }

    /** @return array<string,mixed> */
    private function database(): array
    {
        try {
            $v = (string) $this->db->fetchValue("SELECT current_setting('server_version')");
            $major = (int) $v;
            return ['estado' => $major >= 14 ? 'OK' : 'ADVERTENCIA', 'version' => $v,
                    'detalle' => $major >= 14 ? 'PostgreSQL responde.' : 'PostgreSQL menor a 14 no está soportado.'];
        } catch (\Throwable) {
            return ['estado' => 'ERROR', 'detalle' => 'La base de datos no responde.'];
        }
    }

    /** @return array<string,mixed> */
    private function migrations(): array
    {
        $aplicadas = array_column($this->db->fetchAll('SELECT version FROM schema_migraciones ORDER BY version'), 'version');
        $archivos = array_map(static fn ($f) => substr(basename($f), 0, 4), glob($this->basePath . '/database/migrations/*.up.sql') ?: []);
        sort($archivos);
        $pend = array_values(array_diff($archivos, $aplicadas));
        $last = $this->db->fetchOne('SELECT version, nombre, aplicada_at FROM schema_migraciones ORDER BY version DESC LIMIT 1');
        return ['estado' => $pend === [] ? 'OK' : 'ERROR', 'aplicadas' => count($aplicadas), 'ultima' => $last,
                'detalle' => $pend === [] ? 'Todas las migraciones del código están aplicadas.' : 'Migraciones pendientes: ' . implode(', ', $pend) . '. Ejecute bin/migrate.php up.'];
    }

    /** @return array<string,mixed> */
    private function workers(): array
    {
        $vivos = (int) $this->db->fetchValue('SELECT count(*) FROM workers WHERE detenido_at IS NULL AND ultimo_latido_at > now() - make_interval(secs => :t)',
            ['t' => $this->workerTimeout]);
        $ultimo = $this->db->fetchValue('SELECT max(ultimo_latido_at) FROM workers');
        return ['estado' => $vivos > 0 ? 'OK' : ($ultimo === null ? 'SIN_DATOS' : 'ERROR'), 'vivos' => $vivos, 'ultimo_latido' => $ultimo,
                'detalle' => $vivos > 0 ? "$vivos procesador(es) con latido reciente." : 'Ningún procesador en segundo plano responde: los análisis y conciliaciones no avanzarán.'];
    }

    /** @return array<string,mixed> */
    private function queue(): array
    {
        $q = $this->db->fetchOne(
            "SELECT count(*) FILTER (WHERE estado = 'PENDIENTE') AS pendientes, count(*) FILTER (WHERE estado = 'EN_EJECUCION') AS en_ejecucion,
                    count(*) FILTER (WHERE estado = 'ERROR' AND finalizado_at > now() - interval '24 hours') AS errores_24h,
                    count(*) FILTER (WHERE (estado = 'EN_EJECUCION' AND (coalesce(heartbeat_at, bloqueado_at) < now() - interval '10 minutes' OR iniciado_at < now() - make_interval(secs => timeout_segundos)))) AS atascados,
                    min(created_at) FILTER (WHERE estado = 'PENDIENTE') AS mas_antiguo
               FROM procesos");
        $viejo = $q['mas_antiguo'] !== null && strtotime((string) $q['mas_antiguo']) < time() - 1800;
        $estado = (int) $q['atascados'] > 0 ? 'ERROR' : (($viejo || (int) $q['errores_24h'] > 0) ? 'ADVERTENCIA' : 'OK');
        return ['estado' => $estado] + array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, $q) + ['detalle' => match (true) {
            (int) $q['atascados'] > 0 => $q['atascados'] . ' trabajo(s) atascados: sin latido reciente o más allá de su tiempo máximo. El reaper del worker los recupera; si persisten, cancélelos en Procesos.',
            $viejo => 'Hay trabajos esperando más de 30 minutos.',
            (int) $q['errores_24h'] > 0 => $q['errores_24h'] . ' trabajo(s) terminaron con error en las últimas 24 h.',
            default => 'La cola avanza con normalidad.',
        }];
    }

    /** @return array<string,mixed> */
    private function lastRun(string $tipo, int $maxMinutos, string $mensajeViejo): array
    {
        $r = $this->db->fetchOne('SELECT inicio_at, fin_at, resultado, origen, detalle FROM sistema_ejecuciones WHERE tipo = :t ORDER BY id DESC LIMIT 1', ['t' => $tipo]);
        if ($r === null) {
            return ['estado' => 'SIN_DATOS', 'detalle' => 'Aún no hay ejecuciones registradas.'];
        }
        $viejo = strtotime((string) $r['fin_at']) < time() - $maxMinutos * 60;
        $estado = $r['resultado'] === 'ERROR' ? 'ERROR' : ($viejo || $r['resultado'] === 'ADVERTENCIA' ? 'ADVERTENCIA' : 'OK');
        return ['estado' => $estado, 'ultima' => $r['fin_at'], 'resultado' => $r['resultado'], 'origen' => $r['origen'],
                'conteos' => json_decode((string) $r['detalle'], true) ?: [],
                'detalle' => $viejo ? $mensajeViejo : ($r['resultado'] === 'OK' ? 'Última ejecución correcta.' : 'La última ejecución reportó hallazgos.')];
    }

    /** @return array<string,mixed> */
    private function storageCheck(): array
    {
        $r = $this->lastRun('ALMACENAMIENTO', 26 * 60, 'No se ha revisado el almacenamiento en las últimas 26 horas.');
        if (($r['conteos'] ?? []) !== [] && $r['resultado'] === 'ADVERTENCIA') {
            $c = $r['conteos'];
            $r['detalle'] = sprintf('Huérfanos: %d · staging vencido: %d · originales ausentes o alterados: %d.', $c['huerfanos'] ?? 0, $c['staging'] ?? 0, $c['faltantes'] ?? 0);
            $r['estado'] = ($c['faltantes'] ?? 0) > 0 ? 'ERROR' : 'ADVERTENCIA';
        }
        return $r;
    }

    /** @return array<string,mixed> */
    private function disk(): array
    {
        $free = @disk_free_space($this->storagePath);
        $total = @disk_total_space($this->storagePath);
        if ($free === false || $total === false || $total <= 0) {
            return ['estado' => 'SIN_DATOS', 'detalle' => 'No se pudo medir el espacio del almacenamiento.'];
        }
        $pct = round(100 * $free / $total, 1);
        return ['estado' => $pct < 5 ? 'ERROR' : ($pct < 15 ? 'ADVERTENCIA' : 'OK'), 'libre' => (int) $free, 'total' => (int) $total, 'pct_libre' => $pct,
                'detalle' => sprintf('%s libres de %s (%s %%).', fmt_bytes($free), fmt_bytes($total), $pct)];
    }

    /** @return array<string,mixed> */
    private function reports(): array
    {
        $r = $this->db->fetchOne(
            "SELECT count(*) FILTER (WHERE estado = 'ERROR' AND updated_at > now() - interval '7 days') AS fallidos,
                    count(*) FILTER (WHERE estado = 'ALTERADO' AND updated_at > now() - interval '30 days') AS alterados,
                    count(*) FILTER (WHERE estado = 'ALTERADO' AND updated_at <= now() - interval '30 days') AS alterados_antes,
                    count(*) FILTER (WHERE estado = 'DISPONIBLE' AND NOT cifrado AND fn_reporte_requiere_cifrado(tipo, sensible)) AS sin_cifrar
               FROM reportes_generados");
        $estado = (int) $r['alterados'] > 0 ? 'ERROR' : ((int) $r['fallidos'] > 0 || (int) $r['sin_cifrar'] > 0 ? 'ADVERTENCIA' : 'OK');
        return ['estado' => $estado] + array_map('intval', $r) + ['detalle' => match (true) {
            (int) $r['alterados'] > 0 => $r['alterados'] . ' reporte(s) alterados en los últimos 30 días: revise la auditoría (reportes.integridad_fallida).',
            (int) $r['sin_cifrar'] > 0 => $r['sin_cifrar'] . ' reporte(s) previos a la FASE 8 siguen en claro: cífrelos con bin/rotate-report-key.php.',
            (int) $r['fallidos'] > 0 => $r['fallidos'] . ' reporte(s) programados fallaron en los últimos 7 días.',
            (int) $r['alterados_antes'] > 0 => 'Sin incidentes recientes (' . $r['alterados_antes'] . ' reporte(s) alterados hace más de 30 días, ya registrados en la auditoría).',
            default => 'Sin reportes fallidos ni alterados.',
        }];
    }
}
