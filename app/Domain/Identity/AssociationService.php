<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Identity;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Imports\ImportException;
use ConciliaRH\Domain\Jobs\JobQueue;
use ConciliaRH\Domain\Periods\PeriodService;
use ConciliaRH\Domain\Settings\SettingsService;
use ConciliaRH\Domain\ValidationException;
use PDOException;

/**
 * Asociación registro ↔ empleado (FASE 4, docs/IDENTITY_MATCHING.md §9).
 *
 * - La asociación AUTOMÁTICA la decide el motor (NORMALIZAR_REGISTROS) sólo con evidencia fuerte.
 * - Aquí se resuelven los pendientes: ASOCIAR (manual), RECHAZAR SUGERENCIA, MARCAR NO IDENTIFICADO.
 * - Concurrencia: el registro se bloquea (FOR UPDATE) y se exige la `asociacion_version` que el usuario vio;
 *   el motor re-asocia sólo pendientes y con la misma verificación de versión (gana la decisión manual).
 * - Confirmar la cuenta/CLABE copia HMAC y cifrado DESDE el registro dentro de la BD (fn_confirmar_cuenta):
 *   PHP nunca maneja el número en claro. Luego se re-asocian los pendientes que comparten esa cuenta.
 * - Todo queda en asociaciones_historial + auditoría (sin cuentas completas).
 */
final class AssociationService
{
    public const PENDING = ['SUGERENCIA', 'AMBIGUO', 'CONFLICTO', 'NO_IDENTIFICADO'];
    public const TYPES = ['rrhh' => 'RRHH', 'tesoreria' => 'TESORERIA'];
    public const FILTERS = ['pendientes', 'CONFLICTO', 'SUGERENCIA', 'AMBIGUO', 'NO_IDENTIFICADO', 'ASOCIADO_MANUAL', 'NO_IDENTIFICADO_CONFIRMADO'];

    public function __construct(
        private readonly Database $db,
        private readonly AuditLogger $audit,
        private readonly JobQueue $jobs,
        private readonly SettingsService $settings,
        private readonly EmployeeService $employees,
    ) {
    }

    /** Acción masiva en curso: cada fila del historial queda ligada a ella (trazabilidad registro por registro). */
    private ?int $accionMasivaId = null;

    /**
     * Ejecuta $fn con las decisiones ligadas a la acción masiva $id (en la misma transacción del llamador).
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function withBulkAction(int $id, callable $fn): mixed
    {
        $prev = $this->accionMasivaId;
        $this->accionMasivaId = $id;
        try {
            return $fn();
        } finally {
            $this->accionMasivaId = $prev;
        }
    }

    public static function table(string $tipo): string
    {
        return match ($tipo) {
            'RRHH' => 'registros_rrhh',
            'TESORERIA' => 'registros_tesoreria',
            default => throw HttpException::notFound(),
        };
    }

    /**
     * @param array{estado:string,origen:string,banco:string,periodo:?int,lote:?int,archivo:?int,q:string} $f
     * @return array{rows:list<array<string,mixed>>,total:int,page:int}
     */
    public function paginate(array $f, int $page, int $perPage): array
    {
        $parts = [];
        $params = [];
        foreach (['RRHH', 'TESORERIA'] as $tipo) {
            if ($f['origen'] !== '' && $f['origen'] !== $tipo) {
                continue;
            }
            $t = self::table($tipo);
            $w = ['true'];
            $p = strtolower($tipo);
            if ($f['estado'] === 'pendientes' || $f['estado'] === '') {
                $w[] = "r.asociacion_estado IN ('SUGERENCIA', 'AMBIGUO', 'CONFLICTO', 'NO_IDENTIFICADO')";
            } else {
                $w[] = "r.asociacion_estado = :{$p}_estado";
                $params["{$p}_estado"] = $f['estado'];
            }
            if ($tipo === 'TESORERIA' && $f['banco'] !== '') {
                if (in_array($f['banco'], ['SANTANDER', 'BBVA', 'INTERBANCARIA'], true)) {
                    $w[] = "r.banco = :banco";
                    $params['banco'] = $f['banco'];
                } elseif ($f['banco'] === 'PENSION') {
                    $w[] = "r.categoria = 'PENSION'";
                }
            } elseif ($tipo === 'RRHH' && $f['banco'] !== '') {
                continue;
            }
            foreach (['periodo' => 'periodo_id', 'lote' => 'lote_id', 'archivo' => 'archivo_id'] as $k => $col) {
                if ($f[$k] !== null) {
                    $w[] = "r.$col = :{$p}_$k";
                    $params["{$p}_$k"] = $f[$k];
                }
            }
            if ($f['q'] !== '') {
                $key = EmployeeService::nameKey($f['q']);
                if (mb_strlen($key) >= 3) {
                    $w[] = "r.nombre_normalizado LIKE :{$p}_q ESCAPE '!'";
                    $params["{$p}_q"] = '%' . EmployeeService::likeEscape($key) . '%';
                }
            }
            $extra = $tipo === 'TESORERIA' ? 'r.banco, r.categoria, r.importe, r.cuenta_ultimos4' : "r.grupo AS banco, NULL::text AS categoria, r.neto AS importe, NULL::text AS cuenta_ultimos4";
            $parts[] = "SELECT '$tipo' AS registro_tipo, r.id, r.archivo_id, r.hoja_nombre, r.fila_origen, r.numero_original, r.nombre_original,
                               r.asociacion_estado, r.asociacion_metodo, r.asociacion_confianza, r.asociacion_evidencia, r.empleado_id,
                               r.periodo_id, r.lote_id, $extra, pe.descripcion AS periodo, l.numero AS lote_numero, a.nombre_original AS archivo
                          FROM $t r JOIN importaciones_normalizadas n ON n.id = r.importacion_id AND n.estado = 'VIGENTE'
                          JOIN periodos_nomina pe ON pe.id = r.periodo_id JOIN lotes_importacion l ON l.id = r.lote_id
                          JOIN archivos_importados a ON a.id = r.archivo_id
                         WHERE " . implode(' AND ', $w);
        }
        if ($parts === []) {
            return ['rows' => [], 'total' => 0, 'page' => 1];
        }
        $union = implode(' UNION ALL ', $parts);
        $total = (int) $this->db->fetchValue("SELECT count(*) FROM ($union) x", $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $rows = $this->db->fetchAll(
            "SELECT * FROM ($union) x
              ORDER BY CASE asociacion_estado WHEN 'CONFLICTO' THEN 0 WHEN 'SUGERENCIA' THEN 1 WHEN 'AMBIGUO' THEN 2 ELSE 3 END,
                       periodo_id DESC, archivo_id, fila_origen LIMIT :lim OFFSET :off",
            $params + ['lim' => $perPage, 'off' => ($page - 1) * $perPage]
        );
        foreach ($rows as &$r) {
            $r['asociacion_evidencia'] = self::json($r['asociacion_evidencia']);
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page];
    }

    /** Conteo por estado (importaciones vigentes). @return array<string,int> */
    public function counts(): array
    {
        $out = [];
        foreach ($this->db->fetchAll(
            "SELECT asociacion_estado AS e, count(*) AS n FROM (
                SELECT r.asociacion_estado FROM registros_rrhh r JOIN importaciones_normalizadas n ON n.id = r.importacion_id AND n.estado = 'VIGENTE'
                UNION ALL
                SELECT r.asociacion_estado FROM registros_tesoreria r JOIN importaciones_normalizadas n ON n.id = r.importacion_id AND n.estado = 'VIGENTE'
             ) x GROUP BY 1") as $r) {
            $out[(string) $r['e']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Detalle de un registro con su origen, candidatos, similares, historial e incidencias.
     * @return array<string,mixed>
     */
    public function find(CurrentUser $user, string $tipo, int $id): array
    {
        $t = self::table($tipo);
        $cols = $tipo === 'TESORERIA'
            ? 'r.banco, r.categoria, r.importe, r.importe_estado, r.cuenta_ultimos4, r.tipo_cuenta, r.banco_receptor, r.concepto, r.apellido_paterno,
               r.apellido_materno, r.nombres, (r.cuenta_ultimos4 IS NOT NULL) AS tiene_cuenta'
            : 'r.grupo, r.neto AS importe, r.percepciones, r.deducciones, r.curp_original, r.curp, r.curp_valida, r.area, r.puesto, r.cargo,
               false AS tiene_cuenta';
        $r = $this->db->fetchOne(
            "SELECT r.id, r.importacion_id, r.archivo_id, r.analisis_id, r.hoja_id, r.hoja_indice, r.hoja_nombre, r.fila_origen, r.oculta,
                    r.numero_original, r.numero_empleado, r.numero_valido, r.nombre_original, r.nombre_normalizado, r.rfc_original, r.rfc,
                    r.rfc_valido, r.empleado_id, r.asociacion_estado, r.asociacion_metodo, r.asociacion_confianza, r.asociacion_evidencia,
                    r.asociacion_version, r.rechazados, r.asociado_por, r.asociado_at, $cols,
                    n.estado AS importacion_estado, pe.descripcion AS periodo, pe.estado AS periodo_estado, l.numero AS lote_numero,
                    l.nombre AS lote_nombre, l.id AS lote_id, l.estado AS lote_estado, a.nombre_original AS archivo, p.codigo AS perfil,
                    u.username AS asociado_por_usuario
               FROM $t r JOIN importaciones_normalizadas n ON n.id = r.importacion_id
               JOIN periodos_nomina pe ON pe.id = r.periodo_id JOIN lotes_importacion l ON l.id = r.lote_id
               JOIN archivos_importados a ON a.id = r.archivo_id JOIN perfiles_importacion p ON p.id = r.perfil_id
               LEFT JOIN usuarios u ON u.id = r.asociado_por
              WHERE r.id = :id",
            ['id' => $id]
        );
        if ($r === null) {
            throw HttpException::notFound();
        }
        $r['registro_tipo'] = $tipo;
        $r['asociacion_evidencia'] = self::json($r['asociacion_evidencia']);
        $r['rechazados'] = self::pgArray($r['rechazados']);
        $r = $this->employees->mask($user, $r);
        $ev = $r['asociacion_evidencia'];
        $candIds = array_map('intval', $ev['candidatos'] ?? []);
        $simIds = array_map(static fn ($s) => (int) ($s['empleado_id'] ?? 0), $ev['similares'] ?? []);
        $strongIds = array_map(static fn ($s) => (int) ($s[1] ?? 0), $ev['fuertes'] ?? []);
        $people = $this->employees->brief($user, array_merge($candIds, $simIds, $strongIds, $r['empleado_id'] ? [(int) $r['empleado_id']] : []));
        $r['candidatos'] = array_values(array_filter(array_map(static fn ($i) => $people[$i] ?? null, $candIds)));
        $r['similares'] = array_values(array_filter(array_map(static fn ($s) => isset($people[(int) $s['empleado_id']])
            ? $people[(int) $s['empleado_id']] + ['parecido' => $s['parecido']] : null, $ev['similares'] ?? [])));
        $r['fuertes'] = array_map(static fn ($s) => ['metodo' => $s[0], 'empleado' => $people[(int) $s[1]] ?? null], $ev['fuertes'] ?? []);
        $r['empleado'] = $r['empleado_id'] ? ($people[(int) $r['empleado_id']] ?? null) : null;
        $r['historial'] = $this->db->fetchAll(
            'SELECT h.*, u.username FROM asociaciones_historial h LEFT JOIN usuarios u ON u.id = h.usuario_id
              WHERE h.registro_tipo = :t AND h.registro_id = :id ORDER BY h.id DESC LIMIT 50',
            ['t' => $tipo, 'id' => $id]
        );
        $r['incidencias'] = $this->db->fetchAll(
            'SELECT * FROM incidencias_identidad WHERE registro_tipo = :t AND registro_id = :id ORDER BY id DESC',
            ['t' => $tipo, 'id' => $id]
        );
        return $r;
    }

    /**
     * Asociación manual (también resuelve CONFLICTO/AMBIGUO/SUGERENCIA). En pensiones se asocia al TRABAJADOR.
     * Opcionalmente confirma la cuenta/CLABE del registro como medio de pago conocido (cuentas.confirmar).
     */
    public function associate(CurrentUser $actor, string $tipo, int $id, int $empleadoId, int $version, string $motivo, bool $confirmarCuenta): void
    {
        $motivo = PeriodService::requiredReason($motivo);
        if ($confirmarCuenta && (!$actor->can('cuentas.confirmar') || $tipo !== 'TESORERIA')) {
            throw HttpException::forbidden();
        }
        $reassociate = [];
        $this->db->transaction(function () use ($actor, $tipo, $id, $empleadoId, $version, $motivo, $confirmarCuenta, &$reassociate): void {
            // Mismo orden de candados que el worker, la revisión masiva y el corte de una rotación HMAC: primero el candado
            // consultivo global (lo tomaría fn_confirmar_cuenta), después las filas. Nunca al revés (interbloqueo).
            if ($confirmarCuenta) {
                $this->db->fetchValue('SELECT pg_advisory_xact_lock(4850181374600825153)');
            }
            // Un empleado fusionado es un alias: la decisión se registra para su identidad canónica (misma persona)
            $empleadoId = (int) ($this->db->fetchValue('SELECT coalesce(fusionado_en_id, id) FROM empleados WHERE id = :e', ['e' => $empleadoId]) ?? $empleadoId);
            // También corrige una asociación AUTOMÁTICA equivocada (p. ej. número de empleado mal capturado)
            $r = $this->lock($tipo, $id, $version, [...self::PENDING, 'ASOCIADO_AUTOMATICO', 'ASOCIADO_MANUAL', 'NO_IDENTIFICADO_CONFIRMADO']);
            $emp = $this->db->fetchOne('SELECT id, numero_empleado, fusionado_en_id FROM empleados WHERE id = :e FOR SHARE', ['e' => $empleadoId]);
            if ($emp === null) {
                throw new ValidationException(['empleado_id' => 'El empleado indicado no existe.']);
            }
            if ($emp['fusionado_en_id'] !== null) {
                throw new ValidationException(['empleado_id' => 'Ese empleado está fusionado: asocie al empleado canónico (#' . (int) $emp['fusionado_en_id'] . ').']);
            }
            if (in_array($r['asociacion_estado'], ['ASOCIADO_MANUAL', 'ASOCIADO_AUTOMATICO'], true) && (int) $r['empleado_id'] === $empleadoId
                && !$confirmarCuenta) {
                throw new ValidationException(['empleado_id' => 'El registro ya está asociado a ese empleado.']);
            }
            $t = self::table($tipo);
            $this->db->execute(
                "UPDATE $t SET empleado_id = :e, asociacion_estado = 'ASOCIADO_MANUAL', asociacion_metodo = 'MANUAL', asociacion_confianza = 'MANUAL',
                        asociado_por = :u, asociado_at = now(), asociacion_version = asociacion_version + 1 WHERE id = :id",
                ['e' => $empleadoId, 'u' => $actor->id, 'id' => $id]
            );
            $this->history($tipo, $id, 'ASOCIAR', $r['asociacion_estado'], 'ASOCIADO_MANUAL', $r['empleado_id'], $empleadoId, $actor, $motivo);
            $this->closeIncidents($actor, $tipo, $id, 'Asociación manual: ' . $motivo);
            $this->audit->record('asociaciones.asociar', $t, $id,
                ['estado' => $r['asociacion_estado'], 'empleado_id' => $r['empleado_id']],
                ['estado' => 'ASOCIADO_MANUAL', 'empleado_id' => $empleadoId],
                ['motivo' => $motivo, 'empleado_id' => (string) $empleadoId, 'numero_empleado' => $emp['numero_empleado'],
                 'resuelve_conflicto' => $r['asociacion_estado'] === 'CONFLICTO', 'corrige_automatica' => $r['asociacion_estado'] === 'ASOCIADO_AUTOMATICO',
                 'archivo_id' => $r['archivo_id'], 'fila' => $r['fila_origen']]);
            if ($confirmarCuenta) {
                if ($r['cuenta_ultimos4'] === null) {
                    throw new ValidationException(['confirmar_cuenta' => 'El registro no trae cuenta/CLABE que confirmar.']);
                }
                try {
                    $cuentaId = (int) $this->db->fetchValue('SELECT fn_confirmar_cuenta(:r, :u, :m)', ['r' => $id, 'u' => $actor->id, 'm' => $motivo]);
                } catch (PDOException $e) {
                    if (($e->errorInfo[0] ?? '') === '23505') {
                        throw new ValidationException(['confirmar_cuenta' => 'Esa cuenta/CLABE ya está confirmada para otra persona: revise el conflicto.']);
                    }
                    throw $e;
                }
                $this->history($tipo, $id, 'CONFIRMAR_CUENTA', 'ASOCIADO_MANUAL', 'ASOCIADO_MANUAL', $empleadoId, $empleadoId, $actor, $motivo);
                $this->audit->record('cuentas.confirmar', 'cuentas_bancarias', $cuentaId, null,
                    ['empleado_id' => $empleadoId, 'ultimos4' => $r['cuenta_ultimos4'], 'tipo' => $r['tipo_cuenta']],
                    ['motivo' => $motivo, 'registro_tesoreria_id' => $id, 'empleado_id' => (string) $empleadoId]);
                $reassociate = array_map('intval', array_column(
                    $this->db->fetchAll('SELECT fn_importaciones_con_cuenta AS id FROM fn_importaciones_con_cuenta(:r)', ['r' => $id]), 'id'));
            }
        });
        foreach ($reassociate as $impId) {
            $this->enqueueReassociation($actor, $impId);
        }
    }

    /** Rechaza el candidato sugerido: no se volverá a proponer para este registro (ni al re-normalizar el mismo archivo). */
    public function reject(CurrentUser $actor, string $tipo, int $id, int $empleadoId, int $version, string $motivo): void
    {
        $motivo = PeriodService::requiredReason($motivo);
        $impId = $this->db->transaction(function () use ($actor, $tipo, $id, $empleadoId, $version, $motivo): int {
            $r = $this->lock($tipo, $id, $version, ['SUGERENCIA', 'AMBIGUO']);
            $cands = array_map('intval', self::json($r['asociacion_evidencia'])['candidatos'] ?? []);
            if (!in_array($empleadoId, $cands, true)) {
                throw new ValidationException(['empleado_id' => 'Ese empleado no es un candidato de este registro.']);
            }
            $rest = array_values(array_diff($cands, [$empleadoId]));
            $nuevo = $rest === [] ? 'NO_IDENTIFICADO' : $r['asociacion_estado'];
            $t = self::table($tipo);
            $this->db->execute(
                "UPDATE $t SET rechazados = array_append(rechazados, CAST(:e AS bigint)), asociacion_estado = :st,
                        asociacion_metodo = CASE WHEN :st2 = 'NO_IDENTIFICADO' THEN NULL ELSE asociacion_metodo END,
                        asociacion_confianza = CASE WHEN :st3 = 'NO_IDENTIFICADO' THEN 'NINGUNA' ELSE asociacion_confianza END,
                        asociacion_evidencia = jsonb_set(asociacion_evidencia, '{candidatos}', CAST(:c AS jsonb)),
                        asociacion_version = asociacion_version + 1
                  WHERE id = :id",
                ['e' => $empleadoId, 'st' => $nuevo, 'st2' => $nuevo, 'st3' => $nuevo, 'c' => json_encode($rest), 'id' => $id]
            );
            $this->history($tipo, $id, 'RECHAZAR_SUGERENCIA', $r['asociacion_estado'], $nuevo, $empleadoId, null, $actor, $motivo);
            $this->audit->record('asociaciones.rechazar_sugerencia', $t, $id, ['estado' => $r['asociacion_estado']], ['estado' => $nuevo],
                ['motivo' => $motivo, 'empleado_id' => (string) $empleadoId]);
            return (int) $r['importacion_id'];
        });
        $this->enqueueReassociation($actor, $impId);
    }

    /** Decisión explícita: el registro no corresponde a ningún empleado del maestro (queda cerrado). */
    public function markUnidentified(CurrentUser $actor, string $tipo, int $id, int $version, string $motivo): void
    {
        $motivo = PeriodService::requiredReason($motivo);
        $this->db->transaction(function () use ($actor, $tipo, $id, $version, $motivo): void {
            $r = $this->lock($tipo, $id, $version, self::PENDING);
            $t = self::table($tipo);
            $this->db->execute(
                "UPDATE $t SET empleado_id = NULL, asociacion_estado = 'NO_IDENTIFICADO_CONFIRMADO', asociacion_metodo = 'MANUAL',
                        asociacion_confianza = 'MANUAL', asociado_por = :u, asociado_at = now(), asociacion_version = asociacion_version + 1
                  WHERE id = :id",
                ['u' => $actor->id, 'id' => $id]
            );
            $this->history($tipo, $id, 'MARCAR_NO_IDENTIFICADO', $r['asociacion_estado'], 'NO_IDENTIFICADO_CONFIRMADO', null, null, $actor, $motivo);
            $this->closeIncidents($actor, $tipo, $id, 'Marcado como no identificado: ' . $motivo);
            $this->audit->record('asociaciones.no_identificado', $t, $id, ['estado' => $r['asociacion_estado']],
                ['estado' => 'NO_IDENTIFICADO_CONFIRMADO'], ['motivo' => $motivo]);
        });
    }

    /** Resuelve el número de empleado (texto exacto normalizado) a su id; para la asociación manual. */
    public function employeeIdByNumber(string $numero): int
    {
        $n = EmployeeService::normalizeId($numero);
        // Un número histórico (empleado fusionado) resuelve a su identidad canónica
        $id = preg_match('/^[0-9A-Z-]{1,20}$/', $n) ? $this->db->fetchValue('SELECT coalesce(fusionado_en_id, id) FROM empleados WHERE numero_empleado = :n', ['n' => $n]) : null;
        if ($id === null) {
            throw new ValidationException(['numero_empleado' => 'No existe un empleado con ese número.']);
        }
        return (int) $id;
    }

    // ------------------------------------------------------------------ normalización e incidencias

    /** Resumen de la importación normalizada vigente de un archivo (sin datos de personas). @return array<string,mixed>|null */
    public function normalization(int $archivoId): ?array
    {
        $n = $this->db->fetchOne(
            "SELECT n.*, p.codigo AS perfil, pr.estado AS proceso_estado FROM importaciones_normalizadas n
               JOIN perfiles_importacion p ON p.id = n.perfil_id LEFT JOIN procesos pr ON pr.id = n.proceso_id
              WHERE n.archivo_id = :a ORDER BY (n.estado = 'VIGENTE') DESC, n.numero DESC LIMIT 1",
            ['a' => $archivoId]
        );
        if ($n === null) {
            return null;
        }
        $n['metricas'] = self::json($n['metricas']);
        $n['incidencias'] = (int) $this->db->fetchValue(
            "SELECT count(*) FROM incidencias_identidad WHERE importacion_id = :i AND estado = 'ABIERTA'", ['i' => $n['id']]);
        return $n;
    }

    /** Último trabajo NORMALIZAR_REGISTROS del archivo (estado para la vista). @return array<string,mixed>|null */
    public function lastNormalizationJob(int $archivoId): ?array
    {
        return $this->db->fetchOne(
            "SELECT id, estado, error_codigo, error_mensaje, created_at, finalizado_at FROM procesos
              WHERE tipo = 'NORMALIZAR_REGISTROS' AND parametros->>'archivo_id' = :a ORDER BY id DESC LIMIT 1",
            ['a' => (string) $archivoId]
        );
    }

    /** Encola la normalización de un archivo analizado: completa (nueva importación) o sólo re-asociar pendientes. */
    public function requestNormalization(CurrentUser $actor, int $archivoId, bool $completa): int
    {
        return $this->db->transaction(function () use ($actor, $archivoId, $completa): int {
            $a = $this->db->fetchOne(
                'SELECT a.id, a.uuid, a.estado, l.estado AS lote_estado, p.estado AS periodo_estado FROM archivos_importados a
                   JOIN lotes_importacion l ON l.id = a.lote_id JOIN periodos_nomina p ON p.id = l.periodo_id WHERE a.id = :id FOR UPDATE OF a',
                ['id' => $archivoId]
            );
            if ($a === null) {
                throw HttpException::notFound();
            }
            if (!in_array($a['estado'], ['ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS'], true)) {
                throw new ImportException('ESTADO_INVALIDO', 'Sólo un archivo analizado puede normalizarse.');
            }
            // Un periodo cerrado queda congelado: ni se re-asocia ni se vuelve a importar
            if ($a['periodo_estado'] !== 'ABIERTO' || $a['lote_estado'] === 'ANULADO' || ($completa && $a['lote_estado'] !== 'ABIERTO')) {
                throw new ImportException('LOTE_NO_ABIERTO');
            }
            if ((bool) $this->db->fetchValue(
                "SELECT EXISTS (SELECT 1 FROM procesos WHERE tipo = 'NORMALIZAR_REGISTROS' AND parametros->>'archivo_id' = :a
                                  AND estado IN ('PENDIENTE', 'EN_EJECUCION'))", ['a' => (string) $archivoId])) {
                throw new ImportException('ESTADO_INVALIDO', 'Ya hay una normalización en curso para este archivo.');
            }
            $jobId = $this->jobs->enqueue($actor, 'NORMALIZAR_REGISTROS', ['archivo_id' => $archivoId, 'completa' => $completa], 70,
                'normalizar:w:' . $a['uuid'] . ':' . bin2hex(random_bytes(8)), $this->settings->int('normalizacion.timeout_segundos', 900));
            $this->audit->record('importacion.solicitar_normalizacion', 'archivos_importados', $archivoId, null,
                ['modo' => $completa ? 'COMPLETA' : 'REASOCIAR'], ['proceso_id' => $jobId]);
            return $jobId;
        });
    }

    /**
     * @param array{estado:string,tipo:string,severidad:string} $f
     * @return array{rows:list<array<string,mixed>>,total:int,page:int}
     */
    public function incidents(array $f, int $page, int $perPage): array
    {
        $w = ['true'];
        $p = [];
        if (in_array($f['estado'], ['ABIERTA', 'RESUELTA', 'DESCARTADA'], true)) {
            $w[] = 'i.estado = :estado';
            $p['estado'] = $f['estado'];
        }
        if (preg_match('/^[A-Z][A-Z0-9_]{2,59}$/', $f['tipo'])) {
            $w[] = 'i.tipo = :tipo';
            $p['tipo'] = $f['tipo'];
        }
        if (in_array($f['severidad'], ['INFO', 'ADVERTENCIA', 'ALTA'], true)) {
            $w[] = 'i.severidad = :sev';
            $p['sev'] = $f['severidad'];
        }
        $where = implode(' AND ', $w);
        $total = (int) $this->db->fetchValue("SELECT count(*) FROM incidencias_identidad i WHERE $where", $p);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $rows = $this->db->fetchAll(
            "SELECT i.*, n.archivo_id, e.numero_empleado FROM incidencias_identidad i
               LEFT JOIN importaciones_normalizadas n ON n.id = i.importacion_id LEFT JOIN empleados e ON e.id = i.empleado_id
              WHERE $where ORDER BY (i.estado = 'ABIERTA') DESC,
                    CASE i.severidad WHEN 'ALTA' THEN 0 WHEN 'ADVERTENCIA' THEN 1 ELSE 2 END, i.id DESC LIMIT :lim OFFSET :off",
            $p + ['lim' => $perPage, 'off' => ($page - 1) * $perPage]
        );
        foreach ($rows as &$r) {
            $r['detalle'] = self::json($r['detalle']);
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page];
    }

    /** @return list<array{tipo:string,n:int}> */
    public function incidentTypes(): array
    {
        return array_map(static fn ($r) => ['tipo' => (string) $r['tipo'], 'n' => (int) $r['n']], $this->db->fetchAll(
            "SELECT tipo, count(*) AS n FROM incidencias_identidad WHERE estado = 'ABIERTA' GROUP BY tipo ORDER BY 2 DESC"));
    }

    public function closeIncident(CurrentUser $actor, int $id, string $estado, string $motivo): void
    {
        $motivo = PeriodService::requiredReason($motivo);
        if (!in_array($estado, ['RESUELTA', 'DESCARTADA'], true)) {
            throw new ValidationException(['estado' => 'Estado inválido.']);
        }
        $this->db->transaction(function () use ($actor, $id, $estado, $motivo): void {
            $i = $this->db->fetchOne('SELECT id, estado, tipo FROM incidencias_identidad WHERE id = :id FOR UPDATE', ['id' => $id]);
            if ($i === null) {
                throw HttpException::notFound();
            }
            if ($i['estado'] !== 'ABIERTA') {
                throw new ValidationException(['estado' => 'La incidencia ya está cerrada.']);
            }
            $this->db->execute('UPDATE incidencias_identidad SET estado = :e, resuelto_por = :u, resuelto_at = now(), resolucion = :m WHERE id = :id',
                ['e' => $estado, 'u' => $actor->id, 'm' => $motivo, 'id' => $id]);
            $this->audit->record('incidencias.cerrar', 'incidencias_identidad', $id, ['estado' => 'ABIERTA'], ['estado' => $estado],
                ['tipo' => $i['tipo'], 'motivo' => $motivo]);
        });
    }

    // ------------------------------------------------------------------ internos

    /** @param list<string> $allowed @return array<string,mixed> */
    private function lock(string $tipo, int $id, int $version, array $allowed): array
    {
        $t = self::table($tipo);
        $extra = $tipo === 'TESORERIA' ? ', r.cuenta_ultimos4, r.tipo_cuenta' : ', NULL AS cuenta_ultimos4, NULL AS tipo_cuenta';
        $r = $this->db->fetchOne(
            "SELECT r.id, r.importacion_id, r.archivo_id, r.fila_origen, r.empleado_id, r.asociacion_estado, r.asociacion_version,
                    r.asociacion_evidencia, n.estado AS importacion_estado, pe.estado AS periodo_estado, l.estado AS lote_estado $extra
               FROM $t r JOIN importaciones_normalizadas n ON n.id = r.importacion_id
               JOIN periodos_nomina pe ON pe.id = r.periodo_id JOIN lotes_importacion l ON l.id = r.lote_id
              WHERE r.id = :id FOR UPDATE OF r",
            ['id' => $id]
        );
        if ($r === null) {
            throw HttpException::notFound();
        }
        if ($r['importacion_estado'] !== 'VIGENTE') {
            throw new ValidationException(['registro' => 'El registro pertenece a una importación sustituida o invalidada.']);
        }
        if ($r['periodo_estado'] !== 'ABIERTO' || $r['lote_estado'] === 'ANULADO') {
            throw new ValidationException(['registro' => 'El periodo está cerrado o el lote anulado: no se modifican asociaciones.']);
        }
        if ((int) $r['asociacion_version'] !== $version) {
            throw new ValidationException(['registro' => 'El registro cambió mientras lo revisaba (otra persona o el motor). Recargue la página.']);
        }
        if (!in_array($r['asociacion_estado'], $allowed, true)) {
            throw new ValidationException(['registro' => 'La acción no aplica al estado actual del registro.']);
        }
        return $r;
    }

    private function history(string $tipo, int $id, string $accion, ?string $antes, string $nuevo, mixed $empAntes, ?int $empNuevo,
                             CurrentUser $actor, string $motivo): void
    {
        $this->db->execute(
            'INSERT INTO asociaciones_historial (registro_tipo, registro_id, accion, estado_anterior, estado_nuevo, empleado_anterior,
                    empleado_nuevo, metodo, confianza, usuario_id, motivo, accion_masiva_id)
             VALUES (:t, :id, :a, :ea, :en, :pa, :pn, :m, :c, :u, :mo, :am)',
            ['t' => $tipo, 'id' => $id, 'a' => $accion, 'ea' => $antes, 'en' => $nuevo, 'pa' => $empAntes === null ? null : (int) $empAntes,
             'pn' => $empNuevo, 'm' => in_array($accion, ['ASOCIAR', 'CONFIRMAR_CUENTA', 'MARCAR_NO_IDENTIFICADO'], true) ? 'MANUAL' : null,
             'c' => in_array($accion, ['ASOCIAR', 'CONFIRMAR_CUENTA', 'MARCAR_NO_IDENTIFICADO'], true) ? 'MANUAL' : null,
             'u' => $actor->id, 'mo' => $motivo, 'am' => $this->accionMasivaId]
        );
    }

    private function closeIncidents(CurrentUser $actor, string $tipo, int $id, string $why): void
    {
        $this->db->execute(
            "UPDATE incidencias_identidad SET estado = 'RESUELTA', resuelto_por = :u, resuelto_at = now(), resolucion = :r
              WHERE registro_tipo = :t AND registro_id = :id AND estado = 'ABIERTA'",
            ['u' => $actor->id, 'r' => mb_substr($why, 0, 1000), 't' => $tipo, 'id' => $id]
        );
    }

    /** Re-asociación de pendientes (sólo periodos abiertos; no se duplica si ya hay una normalización pendiente del archivo). */
    private function enqueueReassociation(CurrentUser $actor, int $importacionId): void
    {
        $a = $this->db->fetchOne(
            "SELECT a.id, a.uuid FROM importaciones_normalizadas n JOIN archivos_importados a ON a.id = n.archivo_id
               JOIN periodos_nomina pe ON pe.id = n.periodo_id JOIN lotes_importacion l ON l.id = n.lote_id
              WHERE n.id = :i AND n.estado = 'VIGENTE' AND a.estado IN ('ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS')
                AND pe.estado = 'ABIERTO' AND l.estado <> 'ANULADO'",
            ['i' => $importacionId]
        );
        if ($a === null || (bool) $this->db->fetchValue(
            "SELECT EXISTS (SELECT 1 FROM procesos WHERE tipo = 'NORMALIZAR_REGISTROS' AND parametros->>'archivo_id' = :a AND estado = 'PENDIENTE')",
            ['a' => (string) $a['id']])) {
            return;
        }
        $this->jobs->enqueue($actor, 'NORMALIZAR_REGISTROS', ['archivo_id' => (int) $a['id'], 'completa' => false], 70,
            'normalizar:r:' . $a['uuid'] . ':' . bin2hex(random_bytes(8)), $this->settings->int('normalizacion.timeout_segundos', 900));
    }

    /** @return array<string,mixed> */
    public static function json(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }
        $d = is_string($v) ? json_decode($v, true) : null;
        return is_array($d) ? $d : [];
    }

    /** @return list<int> */
    public static function pgArray(mixed $v): array
    {
        if (is_array($v)) {
            return array_map('intval', $v);
        }
        $s = trim((string) $v, '{}');
        return $s === '' ? [] : array_map('intval', explode(',', $s));
    }
}
