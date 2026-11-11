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
use PDOException;
use RuntimeException;

/**
 * Programaciones y reportes generados, lado web (docs/SCHEDULED_REPORTS.md).
 *
 * - Cada programación es de su creador. Ver/editar/pausar las de otros exige reportes.administrar_programados.
 * - Entrada con lista blanca estricta de campos (nada de asignación masiva): dueño, versión, estado de ejecución y huellas
 *   nunca se aceptan del formulario; la BD vuelve a verificar actor, permisos y sensibilidad.
 * - La descarga evalúa los permisos de QUIEN descarga, verifica el SHA-256 copiando el archivo a un temporal (lo que se
 *   entrega es exactamente lo verificado) y, si no coincide, marca el reporte ALTERADO, lo audita y no lo entrega.
 */
final class ScheduledReportService
{
    public const FORMATOS = ['XLSX' => 'xlsx', 'CSV' => 'csv', 'PDF' => 'pdf'];
    public const RETENCIONES = [30, 60, 90];
    public const ESTADOS_GENERADO = ['PENDIENTE', 'GENERANDO', 'DISPONIBLE', 'ERROR', 'EXPIRADO', 'ALTERADO', 'SIN_DATOS'];
    private const CAMPOS = ['nombre', 'tipo', 'formato', 'frecuencia', 'hora', 'dia_semana', 'dia_mes', 'alcance', 'lote_id', 'sensible', 'retencion_dias'];

    public function __construct(
        private readonly Database $db,
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
        private readonly string $storagePath,
        private readonly string $timezone,
        private readonly ?ReportCipher $cipher = null,
    ) {
    }

    public static function tipos(): array
    {
        return array_filter(ReportService::TIPOS, static fn ($k) => $k !== 'comparativo', ARRAY_FILTER_USE_KEY);
    }

    public static function canAdmin(CurrentUser $u): bool
    {
        return $u->can('reportes.administrar_programados');
    }

    /** @return list<array<string,mixed>> */
    public function list(CurrentUser $u, bool $todos = false): array
    {
        $all = $todos && self::canAdmin($u);
        return $this->db->fetchAll(
            "SELECT p.*, u.username AS creador, l.numero AS lote_numero, pe.descripcion AS lote_periodo
               FROM reportes_programados p JOIN usuarios u ON u.id = p.creado_por
               LEFT JOIN lotes_importacion l ON l.id = p.lote_id LEFT JOIN periodos_nomina pe ON pe.id = l.periodo_id
              WHERE (CAST(:all AS boolean) OR p.creado_por = :u) ORDER BY p.activo DESC, p.id DESC LIMIT 500",
            ['all' => $all ? 'true' : 'false', 'u' => $u->id]);
    }

    /** @return array<string,mixed> — 404 si no existe o no es visible para el usuario (sin revelar su existencia) */
    public function find(CurrentUser $u, int $id): array
    {
        $p = $this->db->fetchOne("SELECT p.*, u.username AS creador FROM reportes_programados p JOIN usuarios u ON u.id = p.creado_por WHERE p.id = :id",
            ['id' => $id]);
        if ($p === null || ((int) $p['creado_por'] !== $u->id && !self::canAdmin($u))) {
            throw HttpException::notFound();
        }
        return $p;
    }

    /** @param array<string,mixed> $in @return array<string,mixed> valores saneados (lista blanca) */
    public function validate(CurrentUser $u, array $in): array
    {
        $in = array_intersect_key($in, array_flip(self::CAMPOS));
        $e = [];
        $v = [];
        $v['nombre'] = trim(preg_replace('/\s+/u', ' ', (string) ($in['nombre'] ?? '')) ?? '');
        if (mb_strlen($v['nombre']) < 3 || mb_strlen($v['nombre']) > 120) {
            $e['nombre'] = 'El nombre debe tener entre 3 y 120 caracteres.';
        }
        $v['tipo'] = (string) ($in['tipo'] ?? '');
        if (!isset(self::tipos()[$v['tipo']])) {
            $e['tipo'] = 'Tipo de reporte no válido.';
        }
        $v['formato'] = (string) ($in['formato'] ?? '');
        if (!isset(self::FORMATOS[$v['formato']])) {
            $e['formato'] = 'Formato no válido.';
        } elseif ($v['formato'] === 'PDF' && !(ReportService::TIPOS[$v['tipo']][2] ?? false)) {
            $e['formato'] = 'Este reporte no tiene versión PDF.';
        }
        $v['frecuencia'] = (string) ($in['frecuencia'] ?? '');
        if (!in_array($v['frecuencia'], ScheduleCalculator::FRECUENCIAS, true)) {
            $e['frecuencia'] = 'Frecuencia no válida.';
        }
        $int = static fn (mixed $x): ?int => is_scalar($x) && preg_match('/^\d{1,4}$/', (string) $x) ? (int) $x : null;
        $v['hora'] = $int($in['hora'] ?? 7) ?? -1;
        if ($v['hora'] < 0 || $v['hora'] > 23) {
            $e['hora'] = 'Hora entre 0 y 23.';
        }
        $v['dia_semana'] = $v['frecuencia'] === 'SEMANAL' ? $int($in['dia_semana'] ?? null) : null;
        if ($v['frecuencia'] === 'SEMANAL' && ($v['dia_semana'] === null || $v['dia_semana'] < 1 || $v['dia_semana'] > 7)) {
            $e['dia_semana'] = 'Elija el día de la semana.';
        }
        $v['dia_mes'] = $v['frecuencia'] === 'MENSUAL' ? $int($in['dia_mes'] ?? null) : null;
        if ($v['frecuencia'] === 'MENSUAL' && ($v['dia_mes'] === null || $v['dia_mes'] < 1 || $v['dia_mes'] > 28)) {
            $e['dia_mes'] = 'Día del mes entre 1 y 28.';
        }
        $v['alcance'] = ($in['alcance'] ?? 'RECIENTE') === 'LOTE' ? 'LOTE' : 'RECIENTE';
        $v['lote_id'] = null;
        if ($v['alcance'] === 'LOTE') {
            $v['lote_id'] = $int($in['lote_id'] ?? null);
            if ($v['lote_id'] === null || !$this->db->fetchValue("SELECT EXISTS (SELECT 1 FROM lotes_importacion WHERE id = :id AND estado <> 'ANULADO')",
                    ['id' => $v['lote_id']])) {
                $e['lote_id'] = 'Elija un lote existente.';
            }
        }
        $v['sensible'] = in_array((string) ($in['sensible'] ?? ''), ['1', 'on', 'true'], true);
        if ($v['sensible'] && !ReportService::canExportIds($u)) {
            $e['sensible'] = 'Su rol no puede programar reportes con RFC/CURP.';
        }
        if ($v['sensible'] && !in_array($v['tipo'], ['empleados', 'diferencias', 'no_pagados', 'pagados_mas', 'pagados_menos'], true)) {
            $v['sensible'] = false;                              // sólo los reportes de personas tienen identificadores
        }
        $v['retencion_dias'] = $int($in['retencion_dias'] ?? 30) ?? 0;
        if (!in_array($v['retencion_dias'], self::RETENCIONES, true)) {
            $e['retencion_dias'] = 'Retención de 30, 60 o 90 días.';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        return $v;
    }

    /** @param array<string,mixed> $in */
    public function create(CurrentUser $u, array $in): int
    {
        $v = $this->validate($u, $in);
        $max = $this->settings->int('reportes.programados_max_por_usuario', 20);
        if ((int) $this->db->fetchValue('SELECT count(*) FROM reportes_programados WHERE creado_por = :u AND activo', ['u' => $u->id]) >= $max) {
            throw new ValidationException(['nombre' => "Alcanzó el máximo de $max programaciones activas."]);
        }
        $next = $this->next($v);
        $id = (int) $this->quota(fn () => $this->db->fetchValue(
            'INSERT INTO reportes_programados (nombre, tipo, formato, frecuencia, hora, dia_semana, dia_mes, alcance, lote_id, sensible, retencion_dias,
                 creado_por, proxima_ejecucion_at)
             VALUES (:nombre, :tipo, :formato, :frecuencia, :hora, :dia_semana, :dia_mes, :alcance, :lote_id, :sensible, :retencion_dias, :u, :prox)
             RETURNING id', [...$this->bind($v), 'u' => $u->id, 'prox' => $next]));
        $this->audit->record('reportes.programacion_crear', 'reportes_programados', $id, null, $this->auditable($v), null);
        return $id;
    }

    /** @param array<string,mixed> $in */
    public function update(CurrentUser $u, int $id, array $in, int $version): void
    {
        $p = $this->find($u, $id);
        // La DEFINICIÓN es sólo del dueño (se genera con sus permisos); un administrador sólo pausa o reactiva (también en la BD)
        if ((int) $p['creado_por'] !== $u->id) {
            throw new ValidationException(['programacion' => 'Sólo el dueño modifica la definición; como administrador puede pausarla o reactivarla.']);
        }
        $v = $this->validate($u, $in);
        // Una programación sensible de otro usuario no se puede volver sensible por un administrador que no lo es (lo verifica también la BD)
        $n = (int) $this->quota(fn () => $this->db->execute(
            'UPDATE reportes_programados SET nombre = :nombre, tipo = :tipo, formato = :formato, frecuencia = :frecuencia, hora = :hora,
                    dia_semana = :dia_semana, dia_mes = :dia_mes, alcance = :alcance, lote_id = :lote_id, sensible = :sensible,
                    retencion_dias = :retencion_dias, actualizado_por = :u, proxima_ejecucion_at = CASE WHEN activo THEN CAST(:prox AS timestamptz) END
              WHERE id = :id AND version = :ver',
            [...$this->bind($v), 'u' => $u->id, 'prox' => $this->next($v), 'id' => $id, 'ver' => $version]));
        if ($n !== 1) {
            throw new ValidationException(['version' => 'REGISTRO_MODIFICADO: otra persona cambió la programación. Recargue e intente de nuevo.']);
        }
        $this->audit->record('reportes.programacion_editar', 'reportes_programados', $id,
            $this->auditable(array_intersect_key($p, array_flip(self::CAMPOS))), $this->auditable($v), null);
    }

    public function setActive(CurrentUser $u, int $id, bool $activo, string $motivo = ''): void
    {
        $p = $this->find($u, $id);
        if ((bool) $p['activo'] === $activo) {
            return;
        }
        $motivo = trim(mb_substr($motivo, 0, 300));
        $prox = $activo ? $this->next($p) : null;
        $this->quota(fn () => $this->db->execute('UPDATE reportes_programados SET activo = :a, pausa_motivo = :m, actualizado_por = :u, proxima_ejecucion_at = :prox
                             WHERE id = :id', ['a' => $activo ? 'true' : 'false', 'm' => $activo ? null : ($motivo !== '' ? $motivo : null),
                                               'u' => $u->id, 'prox' => $prox, 'id' => $id]));
        $this->audit->record($activo ? 'reportes.programacion_reactivar' : 'reportes.programacion_pausar', 'reportes_programados', $id,
            ['activo' => (bool) $p['activo']], ['activo' => $activo], $motivo !== '' ? ['motivo' => $motivo] : null);
    }

    /** «Generar ahora»: idempotente (una en curso por programación). @return int|null id del reporte encolado */
    public function generateNow(CurrentUser $u, int $id): ?int
    {
        $this->find($u, $id);
        try {
            $gid = $this->db->transaction(fn () => $this->db->fetchValue('SELECT fn_reporte_encolar(:id, now(), :o)', ['id' => $id, 'o' => 'MANUAL']));
        } catch (PDOException $e) {
            if (str_contains((string) ($e->errorInfo[2] ?? ''), 'LIMITE_GENERACION_MANUAL')) {
                throw new ValidationException(['programacion' => 'Alcanzó el máximo de generaciones manuales por hora. Intente más tarde.']);
            }
            if (in_array($e->errorInfo[0] ?? '', ['42501', '23514'], true)) {
                throw new ValidationException(['programacion' => 'No se puede generar: la programación está pausada o no tiene permiso.']);
            }
            throw $e;
        }
        if ($gid === null) {
            return null;
        }
        $this->audit->record('reportes.programacion_generar', 'reportes_generados', (int) $gid, null, null, ['programacion_id' => $id]);
        return (int) $gid;
    }

    /**
     * @param array<string,string> $f filtros (tipo, estado, formato, periodo, desde, hasta) — todos por lista blanca o formato
     * @return list<array<string,mixed>>
     */
    public function generated(CurrentUser $u, array $f, bool $todos = false, int $limit = 200): array
    {
        $w = ['(CAST(:all AS boolean) OR g.propietario_id = :u)'];
        $p = ['all' => $todos && self::canAdmin($u) ? 'true' : 'false', 'u' => $u->id];
        if (isset(ReportService::TIPOS[$f['tipo'] ?? ''])) {
            $w[] = 'g.tipo = :tipo';
            $p['tipo'] = $f['tipo'];
        }
        if (in_array($f['estado'] ?? '', self::ESTADOS_GENERADO, true)) {
            $w[] = 'g.estado = :estado';
            $p['estado'] = $f['estado'];
        }
        if (isset(self::FORMATOS[$f['formato'] ?? ''])) {
            $w[] = 'g.formato = :formato';
            $p['formato'] = $f['formato'];
        }
        if (ctype_digit($f['periodo'] ?? '') && strlen($f['periodo']) < 19) {
            $w[] = 'g.periodo_id = :periodo';
            $p['periodo'] = (int) $f['periodo'];
        }
        foreach (['desde' => '>=', 'hasta' => '<'] as $k => $op) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k] ?? '') && checkdate((int) substr($f[$k], 5, 2), (int) substr($f[$k], 8, 2), (int) substr($f[$k], 0, 4))) {
                $w[] = "g.created_at $op CAST(:$k AS date)" . ($k === 'hasta' ? " + 1" : '');
                $p[$k] = $f[$k];
            }
        }
        return $this->db->fetchAll(
            'SELECT g.id, g.programacion_id, g.tipo, g.formato, g.sensible, g.cifrado, g.origen, g.estado, g.programado_para, g.generado_at, g.expira_at,
                    g.filas, g.bytes, g.conciliacion_id, g.conciliacion_numero, g.error_codigo, g.error_mensaje, g.descargas, g.propietario_id,
                    p.nombre, pe.descripcion AS periodo, l.numero AS lote_numero, u.username AS propietario
               FROM reportes_generados g JOIN reportes_programados p ON p.id = g.programacion_id JOIN usuarios u ON u.id = g.propietario_id
               LEFT JOIN periodos_nomina pe ON pe.id = g.periodo_id LEFT JOIN lotes_importacion l ON l.id = g.lote_id
              WHERE ' . implode(' AND ', $w) . ' ORDER BY g.id DESC LIMIT ' . max(1, min(500, $limit)), $p);
    }

    /**
     * Descarga protegida. Devuelve [ruta temporal verificada, nombre de archivo, extensión, cifrado|null]; el llamador la transmite
     * (descifrando si corresponde) y la borra.
     * @return array{0:string,1:string,2:string,3:?array{contexto:string,bytes:int}}
     */
    public function download(CurrentUser $u, int $id): array
    {
        $g = $this->db->fetchOne('SELECT g.*, p.nombre FROM reportes_generados g JOIN reportes_programados p ON p.id = g.programacion_id WHERE g.id = :id',
            ['id' => $id]);
        // Dueño o administrador; y los MISMOS permisos que la exportación manual (compartir el id no concede nada)
        if ($g === null || ((int) $g['propietario_id'] !== $u->id && !self::canAdmin($u))) {
            throw HttpException::notFound();
        }
        if (!$u->can('reportes.exportar') || !$u->can('conciliaciones.ver')
            || ($g['tipo'] === 'pendientes' && !$u->can('asociaciones.ver')) || ($g['tipo'] === 'incidencias' && !$u->can('incidencias.ver'))
            || ((bool) $g['sensible'] && !ReportService::canExportIds($u))) {
            throw HttpException::forbidden();
        }
        // Quien descarga debe tener TODOS los permisos con los que se construyó el contenido (p. ej. incidencias del resumen)
        foreach (array_filter(explode(',', trim((string) ($g['permisos_requeridos'] ?? ''), '{}'))) as $perm) {
            if (!$u->can($perm)) {
                throw HttpException::forbidden();
            }
        }
        \ConciliaRH\Domain\Security\Throttle::check($this->db, $u->id, ['reportes.descargar_generado', 'reportes.integridad_fallida'], 30, 60,
            'Alcanzó el máximo de descargas por minuto. Intente de nuevo en un momento.');
        // Mientras se copia y verifica, la limpieza no puede borrar el archivo (candado compartido). Se entrega la COPIA verificada,
        // así que el candado se libera en cuanto termina la copia (nunca queda tomado en una conexión reutilizada).
        $this->db->fetchValue('SELECT pg_advisory_lock_shared(:k)', ['k' => self::fileLock($id)]);
        try {
            // Estado RELEÍDO bajo el candado: una limpieza concurrente no se confunde con un archivo alterado
            $g2 = $this->db->fetchOne('SELECT estado, ruta, sha256, cifrado, cifrado_llave, expira_at > now() AS vigente FROM reportes_generados WHERE id = :id',
                ['id' => $id]);
            if ($g2 === null || $g2['estado'] !== 'DISPONIBLE' || !$g2['vigente']) {
                throw new ValidationException(['reporte' => 'El reporte no está disponible (' . ($g2['vigente'] ?? true ? ($g2['estado'] ?? '—') : 'EXPIRADO') . ').']);
            }
            $path = $this->resolve((string) $g2['ruta']);
            $tmp = $this->verifiedCopy($path, (string) $g2['sha256']);
        } finally {
            $this->db->fetchValue('SELECT pg_advisory_unlock_shared(:k)', ['k' => self::fileLock($id)]);
        }
        $motivo = $tmp === null ? (is_file($path) ? 'HUELLA_NO_COINCIDE' : 'ARCHIVO_AUSENTE') : null;
        $plainBytes = null;
        if ($tmp !== null && $g2['cifrado']) {
            // Cifrado: se verifica TODO el archivo (cada etiqueta, el final, la llave y el contexto) ANTES de entregar un solo byte
            if ($this->cipher === null || !$this->cipher->hasKey((string) $g2['cifrado_llave'])) {
                @unlink($tmp);
                throw new HttpException(503, 'LLAVE_REPORTE_NO_DISPONIBLE', 'El reporte no se puede descifrar en este servidor (falta su llave). Avise al administrador.');
            }
            try {
                $plainBytes = $this->cipher->decrypt($tmp, 'g' . $id);
            } catch (ReportCipherException $e) {
                @unlink($tmp);
                if (in_array($e->codigo, ['LLAVE_NO_DISPONIBLE', 'LLAVE_INCORRECTA'], true)) {
                    // Configuración del servidor, no manipulación: no se entrega y el reporte NO se marca alterado
                    throw new HttpException(503, 'LLAVE_REPORTE_NO_DISPONIBLE', 'El reporte no se puede descifrar en este servidor (llave distinta). Avise al administrador.');
                }
                $tmp = null;
                $motivo = 'CIFRADO_INVALIDO_' . $e->codigo;
            }
        }
        if ($tmp === null) {
            $this->db->fetchValue('SELECT fn_reporte_marcar_alterado(:id)', ['id' => $id]);
            $this->audit->record('reportes.integridad_fallida', 'reportes_generados', $id, null, null,
                ['programacion_id' => (int) $g['programacion_id'], 'motivo' => $motivo]);
            throw new HttpException(409, 'REPORTE_ALTERADO', 'El archivo del reporte no coincide con su huella o su cifrado: no se entrega. Se registró la incidencia.');
        }
        try {
            $this->db->fetchValue('SELECT fn_reporte_descargado(:id)', ['id' => $id]);
            $this->audit->record('reportes.descargar_generado', 'reportes_generados', $id, null, null, [
                'programacion_id' => (int) $g['programacion_id'], 'tipo' => $g['tipo'], 'formato' => $g['formato'], 'sensible' => (bool) $g['sensible'],
                'sha256' => $g['sha256'], 'bytes' => (int) $g['bytes'], 'conciliacion_id' => $g['conciliacion_id'], 'cifrado' => (bool) $g2['cifrado']]);
        } catch (\Throwable $e) {
            @unlink($tmp);                                   // sin auditoría no hay descarga
            throw $e;
        }
        $ext = self::FORMATOS[$g['formato']];
        $name = sprintf('conciliarh-%s-g%d-c%d-v%d.%s', $g['tipo'], $id, (int) $g['conciliacion_id'], (int) $g['conciliacion_numero'], $ext);
        // Cifrado: la COPIA verificada es cifrado; el controlador la descifra al transmitir (el claro nunca toca el disco)
        return [$tmp, $name, $ext, $plainBytes === null ? null : ['contexto' => 'g' . $id, 'bytes' => $plainBytes]];
    }

    public static function fileLock(int $id): int
    {
        return crc32('conciliarh:reporte-generado:' . $id);
    }

    /** Ruta absoluta de un archivo generado: sólo el patrón del sistema, dentro de storage/reportes (nunca una ruta del usuario). */
    public function resolve(string $ruta): string
    {
        if (!preg_match('#^generados/\d{4}/\d{2}/[0-9a-f]{32}\.(xlsx|csv|pdf)(\.enc)?$#', $ruta)) {
            throw new RuntimeException('Ruta de reporte inválida.');
        }
        return rtrim($this->storagePath, '/') . '/reportes/' . $ruta;
    }

    /**
     * Copia a un temporal y verifica el SHA-256 DE LA COPIA (lo que se entrega es exactamente lo verificado).
     * null = el archivo guardado no existe o no coincide (alterado). Un error de E/S propio (disco lleno, temporal) no es
     * una alteración: es un error del servidor y no marca el reporte.
     */
    private function verifiedCopy(string $path, string $expected): ?string
    {
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $dir = rtrim($this->storagePath, '/') . '/trabajo/reportes';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('No existe el directorio de trabajo de reportes.');
        }
        $tmp = $dir . '/reporte-descarga-' . bin2hex(random_bytes(16));
        $in = @fopen($path, 'rb');
        if ($in === false) {
            return null;
        }
        $out = @fopen($tmp, 'xb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('No se pudo crear la copia temporal del reporte.');
        }
        try {
            while (!feof($in)) {
                $buf = fread($in, 65536);
                if ($buf === false) {
                    return null;
                }
                if ($buf !== '' && fwrite($out, $buf) !== strlen($buf)) {
                    throw new RuntimeException('No se pudo escribir la copia temporal del reporte.');
                }
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($tmp);
            throw $e;
        } finally {
            fclose($in);
        }
        fclose($out);
        if (!hash_equals($expected, (string) hash_file('sha256', $tmp))) {
            @unlink($tmp);
            return null;
        }
        return $tmp;
    }

    /** @param array<string,mixed> $v */
    private function next(array $v): ?string
    {
        $n = ScheduleCalculator::next((string) $v['frecuencia'], (int) $v['hora'], $v['dia_semana'] !== null ? (int) $v['dia_semana'] : null,
            $v['dia_mes'] !== null ? (int) $v['dia_mes'] : null, new DateTimeImmutable('now'), $this->timezone);
        return $n?->format(DATE_ATOM);
    }

    /** @param array<string,mixed> $v @return array<string,mixed> */
    private function bind(array $v): array
    {
        return ['nombre' => $v['nombre'], 'tipo' => $v['tipo'], 'formato' => $v['formato'], 'frecuencia' => $v['frecuencia'], 'hora' => $v['hora'],
                'dia_semana' => $v['dia_semana'], 'dia_mes' => $v['dia_mes'], 'alcance' => $v['alcance'], 'lote_id' => $v['lote_id'],
                'sensible' => $v['sensible'] ? 'true' : 'false', 'retencion_dias' => $v['retencion_dias']];
    }

    /** @param array<string,mixed> $v @return array<string,mixed> */
    private function auditable(array $v): array
    {
        return array_intersect_key($v, array_flip(self::CAMPOS));
    }

    /** La BD hace cumplir la cuota de programaciones activas (al crear y al reactivar): se traduce a un mensaje. */
    private function quota(callable $fn): mixed
    {
        try {
            return $this->db->transaction(static fn () => $fn());
        } catch (PDOException $e) {
            if (str_contains((string) ($e->errorInfo[2] ?? ''), 'CUOTA_PROGRAMACIONES')) {
                throw new ValidationException(['activo' => 'Alcanzó el máximo de programaciones activas. Pause alguna antes.']);
            }
            throw $e;
        }
    }
}
