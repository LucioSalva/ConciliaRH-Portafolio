<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Identity;

use ConciliaRH\Core\Config;
use ConciliaRH\Core\Database;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Periods\PeriodService;
use ConciliaRH\Domain\Settings\SettingsService;
use ConciliaRH\Domain\ValidationException;
use PDOException;

/**
 * Revisión masiva de asociaciones de Tesorería (FASE 6, docs/BENEFICIARIES.md §Revisión masiva).
 *
 * Flujo seguro: bandeja → selección → PREVISUALIZACIÓN (elegibilidad por registro, montos, personas, cuentas, advertencias;
 * firma HMAC de la selección exacta id:versión:candidato) → confirmación explícita → EJECUCIÓN todo-o-nada: se vuelve a
 * bloquear y validar cada registro (FOR UPDATE); si alguno cambió, nada se aplica (REGISTRO_MODIFICADO).
 * Cada decisión usa las mismas reglas que la individual (AssociationService) y queda ligada a la acción masiva.
 * Nunca se decide a ciegas: sólo SUGERENCIA con UN candidato se confirma; CONFLICTO/AMBIGUO no son masivos.
 */
final class BulkReviewService
{
    public const ACCIONES = ['CONFIRMAR_PROPUESTA', 'MARCAR_NO_IDENTIFICADO', 'IGNORAR_SUGERENCIA'];
    private const TTL = 900;

    public function __construct(
        private readonly Database $db,
        private readonly AuditLogger $audit,
        private readonly AssociationService $assoc,
        private readonly SettingsService $settings,
        private readonly Config $config,
    ) {
    }

    /**
     * @param array{categoria:string,estado:string,banco:string,grupo:string,periodo:?int,lote:?int,min:?string,max:?string,q:string,grupo_cuenta:string,confianza:string,orden:string} $f
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,importe_total:string}
     */
    public function search(array $f, int $page, int $perPage): array
    {
        [$where, $p] = $this->where($f);
        $agg = $this->db->fetchOne("SELECT count(*) AS n, coalesce(sum(r.importe), 0) AS s FROM registros_tesoreria r " . self::JOINS . " WHERE $where", $p);
        $total = (int) $agg['n'];
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $order = match ($f['orden']) {
            'importe' => 'r.importe DESC NULLS LAST, r.id',
            'nombre' => 'r.nombre_normalizado, r.id',
            'cuenta' => 'g.grupo_cuenta NULLS LAST, r.id',
            'periodo' => 'r.periodo_id DESC, r.archivo_id, r.fila_origen',
            default => "CASE r.asociacion_estado WHEN 'SUGERENCIA' THEN 0 WHEN 'AMBIGUO' THEN 1 WHEN 'CONFLICTO' THEN 2 ELSE 3 END, r.periodo_id DESC, r.id",
        };
        $rows = $this->db->fetchAll(
            "SELECT r.id, r.asociacion_version AS version, r.categoria, r.banco, r.nombre_original, r.cuenta_ultimos4, r.tipo_cuenta, r.importe,
                    r.asociacion_estado, r.asociacion_confianza, r.asociacion_evidencia, r.fila_origen, r.archivo_id, r.periodo_id, r.lote_id,
                    a.nombre_original AS archivo, pe.descripcion AS periodo, l.numero AS lote_numero, g.grupo_cuenta, g.cuenta_registrada
               FROM registros_tesoreria r " . self::JOINS . "
              WHERE $where ORDER BY $order LIMIT :lim OFFSET :off",
            $p + ['lim' => $perPage, 'off' => ($page - 1) * $perPage]);
        $this->decorate($rows);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'importe_total' => (string) $agg['s']];
    }

    /** Conteos para la bandeja y las alertas del tablero (dinámicos; ninguna cifra fija). @return array<string,int> */
    public function counts(): array
    {
        $r = $this->db->fetchOne(
            "SELECT count(*) FILTER (WHERE r.categoria = 'NOMINA' AND r.asociacion_estado IN ('SUGERENCIA', 'AMBIGUO', 'CONFLICTO', 'NO_IDENTIFICADO')) AS nomina,
                    count(*) FILTER (WHERE r.categoria = 'NOMINA' AND r.banco = 'INTERBANCARIA'
                                       AND r.asociacion_estado IN ('SUGERENCIA', 'AMBIGUO', 'CONFLICTO', 'NO_IDENTIFICADO')) AS nomina_interbancaria,
                    count(*) FILTER (WHERE r.categoria = 'PENSION' AND r.asociacion_estado IN ('SUGERENCIA', 'AMBIGUO', 'CONFLICTO', 'NO_IDENTIFICADO')) AS pension,
                    count(*) FILTER (WHERE r.asociacion_estado = 'SUGERENCIA') AS sugerencias
               FROM registros_tesoreria r JOIN importaciones_normalizadas n ON n.id = r.importacion_id AND n.estado = 'VIGENTE'
               JOIN periodos_nomina pe ON pe.id = r.periodo_id AND pe.estado = 'ABIERTO'
               JOIN lotes_importacion l ON l.id = r.lote_id AND l.estado <> 'ANULADO'");
        return array_map('intval', $r ?? []);
    }

    /**
     * Previsualización: evalúa cada registro seleccionado (sin modificar nada) y firma la selección elegible.
     * @param list<string> $items "id:version"
     * @param array{confirmar_cuenta:bool,registrar_beneficiario:bool} $op
     * @return array<string,mixed>
     */
    public function preview(CurrentUser $user, string $accion, array $items, array $op): array
    {
        $this->assertAction($user, $accion, $op);
        $sel = $this->parseItems($items);
        $eval = $this->evaluate($user, $accion, $sel, $op, false);
        $eval['token'] = $eval['elegibles'] === [] ? null : $this->sign($user, $accion, $eval['elegibles'], $op);
        $eval['accion'] = $accion;
        $eval['opciones'] = $op;
        return $eval;
    }

    /**
     * Ejecución todo-o-nada de una selección previsualizada y firmada.
     * @param list<string> $items "id:version:candidato"
     * @param array{confirmar_cuenta:bool,registrar_beneficiario:bool} $op
     */
    public function execute(CurrentUser $user, string $accion, array $items, array $op, string $token, string $motivo): int
    {
        $this->assertAction($user, $accion, $op);
        $motivo = PeriodService::requiredReason($motivo);
        $sel = [];
        foreach ($items as $it) {
            if (!preg_match('/^(\d{1,18}):(\d{1,9}):(\d{0,18})$/', $it, $m)) {
                throw new ValidationException(['seleccion' => 'Selección inválida.']);
            }
            $sel[(int) $m[1]] = ['version' => (int) $m[2], 'candidato' => $m[3] === '' ? null : (int) $m[3]];
        }
        ksort($sel);
        if (!hash_equals($this->sign($user, $accion, $sel, $op, (int) (explode('.', $token)[0] ?? 0)), $token)
            || (int) explode('.', $token)[0] < time()) {
            throw new ValidationException(['seleccion' => 'La previsualización expiró o la selección fue alterada: vuelva a previsualizar.']);
        }
        return $this->db->transaction(function () use ($user, $accion, $sel, $op, $motivo): int {
            // Mismo orden de candados que el worker (normalización) y fn_confirmar_cuenta/beneficiario: primero el candado
            // consultivo global y después las filas; así no hay interbloqueo entre la acción masiva y el motor.
            $this->db->fetchValue('SELECT pg_advisory_xact_lock(4850181374600825153)');
            // Fase 1: bloquear y revalidar TODO antes de escribir
            $eval = $this->evaluate($user, $accion, array_map(static fn ($x) => $x['version'], $sel), $op, true);
            $cambios = array_diff_key($sel, $eval['elegibles']);
            foreach ($eval['elegibles'] as $id => $e) {
                if ($e['candidato'] !== $sel[$id]['candidato']) {
                    $cambios[$id] = true;
                }
            }
            if ($cambios !== []) {
                throw new ValidationException(['seleccion' => 'REGISTRO_MODIFICADO: ' . count($cambios)
                    . ' registro(s) cambiaron desde la previsualización (otra persona o el motor). No se aplicó nada; vuelva a previsualizar.']);
            }
            $amId = (int) $this->db->fetchValue(
                'INSERT INTO acciones_masivas (accion, usuario_id, motivo, registros, importe_total, empleados, beneficiarios, cuentas, filtros, huella)
                 VALUES (:a, :u, :m, :n, :imp, :e, :b, :c, CAST(:f AS jsonb), :h) RETURNING id',
                ['a' => $accion, 'u' => $user->id, 'm' => $motivo, 'n' => count($sel), 'imp' => $eval['importe_total'], 'e' => $eval['empleados'],
                 'b' => $eval['beneficiarios'], 'c' => $eval['cuentas_nuevas'], 'f' => json_encode(['opciones' => $op]),
                 'h' => hash('sha256', $this->canonical($accion, $sel, $op))]);
            // Fase 2: aplicar con las reglas de la decisión individual (historial ligado a la acción masiva)
            $this->assoc->withBulkAction($amId, function () use ($user, $accion, $sel, $op, $motivo, $amId, $eval): void {
                foreach ($sel as $id => $s) {
                    $e = $eval['elegibles'][$id];
                    $benef = null;
                    try {
                        if ($accion === 'CONFIRMAR_PROPUESTA') {
                            $pension = $e['categoria'] === 'PENSION';
                            $viaBenef = $pension && $op['registrar_beneficiario'];
                            $this->assoc->associate($user, 'TESORERIA', $id, (int) $s['candidato'], $s['version'], $motivo,
                                $op['confirmar_cuenta'] && !$viaBenef && $e['cuenta_ultimos4'] !== null);
                            if ($viaBenef) {
                                $benef = (int) $this->db->fetchValue('SELECT fn_confirmar_beneficiario(:r, :u, :b, :m, :am)',
                                    ['r' => $id, 'u' => $user->id, 'b' => $e['beneficiario_candidato'], 'm' => $motivo, 'am' => $amId]);
                            }
                        } elseif ($accion === 'MARCAR_NO_IDENTIFICADO') {
                            $this->assoc->markUnidentified($user, 'TESORERIA', $id, $s['version'], $motivo);
                        } else {
                            $this->assoc->reject($user, 'TESORERIA', $id, (int) $s['candidato'], $s['version'], $motivo);
                        }
                    } catch (ValidationException $ex) {
                        throw new ValidationException(['seleccion' => "REGISTRO_MODIFICADO: el registro #$id no pudo aplicarse ("
                            . implode(' ', $ex->errors()) . '). No se aplicó nada.']);
                    } catch (PDOException $ex) {
                        if (($ex->errorInfo[0] ?? '') === '42501') {
                            throw new ValidationException(['seleccion' => "El registro #$id no puede confirmarse con sus permisos actuales. No se aplicó nada."]);
                        }
                        if (in_array($ex->errorInfo[0] ?? '', ['40P01', '40001', '55P03'], true)) {
                            throw new ValidationException(['seleccion' => 'Otra operación estaba modificando estos registros. No se aplicó nada; vuelva a previsualizar.']);
                        }
                        if (in_array($ex->errorInfo[0] ?? '', ['23505', '23514'], true)) {
                            throw new ValidationException(['seleccion' => "El registro #$id no pudo confirmarse: su cuenta/CLABE pertenece a otra persona "
                                . 'o el lote ya no admite cambios. No se aplicó nada.']);
                        }
                        throw $ex;
                    }
                    // Estado resultante y, si se confirmó en esta acción, la cuenta creada a partir de este registro
                    $after = $this->db->fetchOne(
                        "SELECT r.asociacion_estado, r.empleado_id, r.beneficiario_id,
                                (SELECT c.id FROM cuentas_bancarias c WHERE c.estado = 'ACTIVA' AND c.empleado_id = r.empleado_id
                                    AND c.cuenta_ultimos4 = r.cuenta_ultimos4 AND c.confirmado_por = :u AND c.confirmado_at = now()
                                    ORDER BY c.id DESC LIMIT 1) AS cuenta_id
                           FROM registros_tesoreria r WHERE r.id = :id", ['id' => $id, 'u' => $user->id]);
                    $this->db->execute(
                        'INSERT INTO acciones_masivas_registros (accion_masiva_id, registro_tipo, registro_id, version_anterior, estado_anterior, estado_nuevo,
                                empleado_anterior, empleado_nuevo, beneficiario_id, cuenta_id, importe)
                         VALUES (:am, \'TESORERIA\', :id, :v, :ea, :en, NULL, :emp, :b, :c, :imp)',
                        ['am' => $amId, 'id' => $id, 'v' => $s['version'], 'ea' => $e['estado'], 'en' => $after['asociacion_estado'],
                         'emp' => $after['empleado_id'], 'b' => $after['beneficiario_id'] ?? $benef, 'c' => $after['cuenta_id'], 'imp' => $e['importe']]);
                }
            });
            $this->audit->record('asociaciones.masivo', 'acciones_masivas', $amId, null,
                ['accion' => $accion, 'registros' => count($sel), 'importe_total' => $eval['importe_total']],
                ['motivo' => $motivo, 'empleados' => $eval['empleados'], 'beneficiarios' => $eval['beneficiarios'],
                 'cuentas_nuevas' => $eval['cuentas_nuevas'], 'opciones' => $op]);
            return $amId;
        });
    }

    /** @return array<string,mixed> */
    public function action(int $id): array
    {
        $a = $this->db->fetchOne('SELECT am.*, u.username FROM acciones_masivas am JOIN usuarios u ON u.id = am.usuario_id WHERE am.id = :id', ['id' => $id]);
        if ($a === null) {
            throw HttpException::notFound();
        }
        $a['registros_detalle'] = $this->db->fetchAll(
            'SELECT d.*, r.nombre_original, r.banco, r.categoria, e.numero_empleado, e.nombre_completo
               FROM acciones_masivas_registros d JOIN registros_tesoreria r ON r.id = d.registro_id
               LEFT JOIN empleados e ON e.id = d.empleado_nuevo
              WHERE d.accion_masiva_id = :id ORDER BY d.registro_id', ['id' => $id]);
        return $a;
    }

    /** @return list<array<string,mixed>> */
    public function recentActions(int $limit = 20): array
    {
        return $this->db->fetchAll('SELECT am.*, u.username FROM acciones_masivas am JOIN usuarios u ON u.id = am.usuario_id ORDER BY am.id DESC LIMIT :l',
            ['l' => $limit]);
    }

    // ------------------------------------------------------------------ internos

    private const JOINS = "JOIN importaciones_normalizadas n ON n.id = r.importacion_id AND n.estado = 'VIGENTE'
               JOIN periodos_nomina pe ON pe.id = r.periodo_id JOIN lotes_importacion l ON l.id = r.lote_id
               JOIN archivos_importados a ON a.id = r.archivo_id
               LEFT JOIN v_revision_grupos_cuenta g ON g.registro_id = r.id";

    /** @return array{0:string,1:array<string,mixed>} */
    private function where(array $f): array
    {
        $w = ["r.asociacion_estado IN ('SUGERENCIA', 'AMBIGUO', 'CONFLICTO', 'NO_IDENTIFICADO')", "pe.estado = 'ABIERTO'", "l.estado <> 'ANULADO'"];
        $p = [];
        if (in_array($f['estado'], AssociationService::PENDING, true)) {
            $w[] = 'r.asociacion_estado = :estado';
            $p['estado'] = $f['estado'];
        }
        if (in_array($f['categoria'], ['NOMINA', 'PENSION'], true)) {
            $w[] = 'r.categoria = :cat';
            $p['cat'] = $f['categoria'];
        }
        if (in_array($f['banco'], ['SANTANDER', 'BBVA', 'INTERBANCARIA'], true)) {
            $w[] = 'r.banco = :banco';
            $p['banco'] = $f['banco'];
        }
        if (in_array($f['confianza'], ['MEDIA', 'BAJA', 'NINGUNA'], true)) {
            $w[] = 'r.asociacion_confianza = :conf';
            $p['conf'] = $f['confianza'];
        }
        if (in_array($f['grupo'], ['POLICIA', 'BOMBEROS'], true)) {
            $w[] = "EXISTS (SELECT 1 FROM jsonb_array_elements_text(coalesce(r.asociacion_evidencia->'candidatos', '[]'::jsonb)) c(v)
                             JOIN empleados e ON e.id = c.v::bigint WHERE e.grupo = :grupo)";
            $p['grupo'] = $f['grupo'];
        }
        foreach (['periodo' => 'r.periodo_id', 'lote' => 'r.lote_id'] as $k => $col) {
            if ($f[$k] !== null) {
                $w[] = "$col = :$k";
                $p[$k] = $f[$k];
            }
        }
        foreach (['min' => '>=', 'max' => '<='] as $k => $op) {
            if ($f[$k] !== null) {
                $w[] = "r.importe $op CAST(:$k AS numeric)";
                $p[$k] = $f[$k];
            }
        }
        if ($f['grupo_cuenta'] !== '' && preg_match('/^[0-9a-f]{12}$/', $f['grupo_cuenta'])) {
            $w[] = 'g.grupo_cuenta = :gc';
            $p['gc'] = $f['grupo_cuenta'];
        }
        $key = EmployeeService::nameKey($f['q']);
        if (mb_strlen($key) >= 3) {
            foreach (array_slice(array_filter(explode(' ', $key)), 0, 5) as $i => $t) {
                $w[] = "r.nombre_normalizado LIKE :t$i ESCAPE '!'";
                $p["t$i"] = '%' . EmployeeService::likeEscape($t) . '%';
            }
        }
        return [implode(' AND ', $w), $p];
    }

    /** Candidatos, beneficiario candidato y tamaño del grupo de CLABE, en consultas agregadas (sin N+1). @param list<array<string,mixed>> $rows */
    private function decorate(array &$rows): void
    {
        $ids = [];
        $bens = [];
        $groups = [];
        foreach ($rows as &$r) {
            $r['asociacion_evidencia'] = AssociationService::json($r['asociacion_evidencia']);
            $r['candidatos'] = array_values(array_map('intval', (array) ($r['asociacion_evidencia']['candidatos'] ?? [])));
            $ids = [...$ids, ...$r['candidatos']];
            if (isset($r['asociacion_evidencia']['beneficiario_candidato'])) {
                $bens[] = (int) $r['asociacion_evidencia']['beneficiario_candidato'];
            }
            if ($r['grupo_cuenta'] !== null) {
                $groups[] = $r['grupo_cuenta'];
            }
        }
        unset($r);
        $emps = $ids === [] ? [] : array_column($this->db->fetchAll(
            'SELECT id, numero_empleado, nombre_completo, grupo FROM empleados WHERE id = ANY(CAST(:ids AS bigint[]))',
            ['ids' => '{' . implode(',', array_unique($ids)) . '}']), null, 'id');
        $benNames = $bens === [] ? [] : array_column($this->db->fetchAll(
            'SELECT id, nombre_original FROM beneficiarios_pension WHERE id = ANY(CAST(:ids AS bigint[]))',
            ['ids' => '{' . implode(',', array_unique($bens)) . '}']), 'nombre_original', 'id');
        $sizes = $groups === [] ? [] : array_column($this->db->fetchAll(
            "SELECT g.grupo_cuenta, count(*) AS n FROM v_revision_grupos_cuenta g JOIN registros_tesoreria r ON r.id = g.registro_id
               JOIN periodos_nomina pe ON pe.id = r.periodo_id AND pe.estado = 'ABIERTO' JOIN lotes_importacion l ON l.id = r.lote_id AND l.estado <> 'ANULADO'
              WHERE g.grupo_cuenta = ANY(CAST(:g AS text[])) GROUP BY 1",
            ['g' => '{' . implode(',', array_unique($groups)) . '}']), 'n', 'grupo_cuenta');
        foreach ($rows as &$r) {
            $r['candidatos_info'] = array_values(array_filter(array_map(static fn ($id) => $emps[$id] ?? null, $r['candidatos'])));
            $bc = $r['asociacion_evidencia']['beneficiario_candidato'] ?? null;
            $r['beneficiario_candidato_nombre'] = $bc !== null ? ($benNames[(int) $bc] ?? null) : null;
            $r['grupo_cuenta_n'] = $r['grupo_cuenta'] !== null ? (int) ($sizes[$r['grupo_cuenta']] ?? 1) : 0;
        }
    }

    /**
     * Elegibilidad registro por registro. $lock = true: FOR UPDATE (ejecución).
     * @param array<int,int> $sel id => versión
     * @return array<string,mixed>
     */
    private function evaluate(CurrentUser $user, string $accion, array $sel, array $op, bool $lock): array
    {
        $max = $this->settings->int('revision.max_registros_masivo', 500);
        if ($sel === []) {
            throw new ValidationException(['seleccion' => 'Seleccione al menos un registro.']);
        }
        if (count($sel) > $max) {
            throw new ValidationException(['seleccion' => "Máximo $max registros por acción masiva."]);
        }
        $rows = $this->db->fetchAll(
            "SELECT r.id, r.asociacion_version AS version, r.asociacion_estado AS estado, r.asociacion_confianza AS confianza, r.asociacion_evidencia,
                    r.rechazados::text AS rechazados,
                    r.categoria, r.importe, r.cuenta_ultimos4, n.estado AS imp_estado, pe.estado AS periodo_estado, l.estado AS lote_estado,
                    g.grupo_cuenta, g.cuenta_registrada
               FROM registros_tesoreria r JOIN importaciones_normalizadas n ON n.id = r.importacion_id
               JOIN periodos_nomina pe ON pe.id = r.periodo_id JOIN lotes_importacion l ON l.id = r.lote_id
               LEFT JOIN v_revision_grupos_cuenta g ON g.registro_id = r.id
              WHERE r.id = ANY(CAST(:ids AS bigint[])) ORDER BY r.id" . ($lock ? ' FOR UPDATE OF r' : ''),
            ['ids' => '{' . implode(',', array_keys($sel)) . '}']);
        $found = array_column($rows, null, 'id');
        $eleg = [];
        $rech = [];
        $porGrupo = [];
        foreach ($sel as $id => $version) {
            $r = $found[$id] ?? null;
            $why = null;
            $cands = [];
            if ($r === null) {
                $why = 'NO_EXISTE';
            } else {
                $ev = AssociationService::json($r['asociacion_evidencia']);
                $rechazados = array_map('intval', array_filter(explode(',', trim((string) $r['rechazados'], '{}')), 'strlen'));
                // Una sugerencia anterior a una fusión puede nombrar al alias: se evalúa sobre la identidad canónica
                $cands = array_values(array_unique(array_diff(array_map(fn ($c) => $this->canonicalEmployee((int) $c), (array) ($ev['candidatos'] ?? [])), $rechazados)));
                if ($r['imp_estado'] !== 'VIGENTE' || $r['periodo_estado'] !== 'ABIERTO' || $r['lote_estado'] === 'ANULADO') {
                    $why = 'NO_MODIFICABLE';
                } elseif ((int) $r['version'] !== $version) {
                    $why = 'REGISTRO_MODIFICADO';
                } elseif (in_array($r['estado'], ['CONFLICTO', 'AMBIGUO'], true)) {
                    $why = 'REQUIERE_DECISION_INDIVIDUAL';           // nunca en bloque, para ninguna acción
                } elseif ($accion === 'MARCAR_NO_IDENTIFICADO') {
                    $why = in_array($r['estado'], ['SUGERENCIA', 'NO_IDENTIFICADO'], true) ? null : 'ESTADO_NO_APLICA';
                } elseif ($r['estado'] !== 'SUGERENCIA') {
                    $why = 'ESTADO_NO_APLICA';
                } elseif (count($cands) !== 1) {
                    $why = 'CANDIDATOS_DISTINTOS';
                } elseif ($accion === 'CONFIRMAR_PROPUESTA' && !in_array($r['confianza'], ['MEDIA', 'ALTA'], true)) {
                    $why = 'CONFIANZA_BAJA_REQUIERE_REVISION_INDIVIDUAL';   // una sola señal débil: se decide uno por uno
                }
            }
            if ($why !== null) {
                $rech[$id] = $why;
                continue;
            }
            $ev = AssociationService::json($r['asociacion_evidencia']);
            $eleg[$id] = ['version' => (int) $r['version'], 'estado' => $r['estado'], 'categoria' => $r['categoria'],
                          'importe' => $r['importe'], 'cuenta_ultimos4' => $r['cuenta_ultimos4'], 'grupo_cuenta' => $r['grupo_cuenta'],
                          'cuenta_registrada' => (bool) $r['cuenta_registrada'],
                          'candidato' => $accion === 'MARCAR_NO_IDENTIFICADO' ? null : ($cands[0] ?? null),
                          'beneficiario_candidato' => isset($ev['beneficiario_candidato']) ? (int) $ev['beneficiario_candidato'] : null];
            if ($r['grupo_cuenta'] !== null && $accion === 'CONFIRMAR_PROPUESTA') {
                $porGrupo[$r['grupo_cuenta']][$cands[0]] = true;
            }
        }
        // La misma CLABE propuesta para personas distintas (en la selección O en cualquier otro pendiente del grupo, incluidos
        // conflictos y ambigüedades fuera de la página): nunca en bloque
        if ($porGrupo !== []) {
            foreach ($this->db->fetchAll(
                "SELECT g.grupo_cuenta, r.asociacion_estado, r.asociacion_evidencia->'candidatos' AS cands
                   FROM v_revision_grupos_cuenta g JOIN registros_tesoreria r ON r.id = g.registro_id
                   JOIN periodos_nomina pe ON pe.id = r.periodo_id AND pe.estado = 'ABIERTO' JOIN lotes_importacion l ON l.id = r.lote_id AND l.estado <> 'ANULADO'
                  WHERE g.grupo_cuenta = ANY(CAST(:g AS text[]))", ['g' => '{' . implode(',', array_keys($porGrupo)) . '}']) as $o) {
                $oc = array_map('intval', (array) (AssociationService::json($o['cands']) ?: []));
                if (in_array($o['asociacion_estado'], ['CONFLICTO', 'AMBIGUO'], true) || count($oc) > 1) {
                    $porGrupo[$o['grupo_cuenta']]['*'] = true;              // el grupo tiene una decisión pendiente no trivial
                }
                foreach ($oc as $cand) {
                    $porGrupo[$o['grupo_cuenta']][$cand] = true;
                }
            }
        }
        foreach ($eleg as $id => $e) {
            if ($e['grupo_cuenta'] !== null && count($porGrupo[$e['grupo_cuenta']] ?? []) > 1) {
                $rech[$id] = 'CLABE_CON_CANDIDATOS_DISTINTOS';
                unset($eleg[$id]);
            }
        }
        $importe = '0';
        $emps = [];
        $bens = [];
        $cuentas = [];
        $nuevosBenef = 0;
        $nuevosPorCuenta = [];
        foreach ($eleg as $e) {
            $importe = bcadd($importe, (string) ($e['importe'] ?? '0'), 2);
            if ($e['candidato'] !== null) {
                $emps[$e['candidato']] = true;
            }
            if ($accion === 'CONFIRMAR_PROPUESTA' && $e['categoria'] === 'PENSION' && $op['registrar_beneficiario']) {
                if ($e['beneficiario_candidato'] !== null) {
                    $bens[$e['beneficiario_candidato']] = true;
                } elseif ($e['grupo_cuenta'] !== null) {
                    $nuevosPorCuenta[$e['grupo_cuenta']] = true;         // misma CLABE ⇒ un solo beneficiario nuevo
                } else {
                    $nuevosBenef++;
                }
            }
            if ($accion === 'CONFIRMAR_PROPUESTA' && ($op['confirmar_cuenta'] || ($e['categoria'] === 'PENSION' && $op['registrar_beneficiario']))
                && $e['cuenta_ultimos4'] !== null && !$e['cuenta_registrada'] && $e['grupo_cuenta'] !== null) {
                $cuentas[$e['grupo_cuenta']] = true;
            }
        }
        $nuevosBenef += count($nuevosPorCuenta);
        $inc = $eleg === [] ? 0 : (int) $this->db->fetchValue(
            "SELECT count(*) FROM incidencias_identidad WHERE registro_tipo = 'TESORERIA' AND estado = 'ABIERTA' AND registro_id = ANY(CAST(:ids AS bigint[]))",
            ['ids' => '{' . implode(',', array_keys($eleg)) . '}']);
        $warn = [];
        if ($rech !== []) {
            $warn[] = count($rech) . ' registro(s) seleccionados NO se incluirán (ver motivos).';
        }
        $cats = array_unique(array_column($eleg, 'categoria'));
        if (count($cats) > 1) {
            $warn[] = 'La selección mezcla nómina y pensión.';
        }
        if ($accion === 'CONFIRMAR_PROPUESTA' && in_array('PENSION', $cats, true) && !$op['registrar_beneficiario']) {
            $warn[] = 'Las pensiones se asociarán al trabajador sin registrar al beneficiario ni aprender su CLABE.';
        }
        return ['elegibles' => $eleg, 'rechazados' => $rech, 'registros' => count($eleg), 'importe_total' => $importe,
                'empleados' => count($emps), 'beneficiarios' => count($bens) + $nuevosBenef, 'beneficiarios_nuevos' => $nuevosBenef,
                'cuentas_nuevas' => count($cuentas), 'incidencias' => $inc, 'advertencias' => $warn];
    }

    private function assertAction(CurrentUser $user, string $accion, array $op): void
    {
        if (!in_array($accion, self::ACCIONES, true) || !$user->can('asociaciones.masivo') || !$user->can('asociaciones.resolver')) {
            throw HttpException::forbidden();
        }
        if ($accion === 'CONFIRMAR_PROPUESTA' && (($op['confirmar_cuenta'] && !$user->can('cuentas.confirmar'))
                || ($op['registrar_beneficiario'] && (!$user->can('beneficiarios.confirmar') || !$user->can('cuentas.confirmar'))))) {
            throw HttpException::forbidden();
        }
    }

    /** @param list<string> $items @return array<int,int> */
    private function parseItems(array $items): array
    {
        $sel = [];
        foreach ($items as $it) {
            if (!is_string($it) || !preg_match('/^(\d{1,18}):(\d{1,9})$/', $it, $m)) {
                throw new ValidationException(['seleccion' => 'Selección inválida.']);
            }
            $sel[(int) $m[1]] = (int) $m[2];
        }
        ksort($sel);
        return $sel;
    }

    /** @param array<int,array<string,mixed>> $sel */
    private function canonical(string $accion, array $sel, array $op): string
    {
        $parts = [];
        foreach ($sel as $id => $e) {
            $parts[] = $id . ':' . $e['version'] . ':' . ($e['candidato'] ?? '');
        }
        return $accion . '|' . implode(',', $parts) . '|' . (int) $op['confirmar_cuenta'] . (int) $op['registrar_beneficiario'];
    }

    /** @param array<int,array<string,mixed>> $sel */
    private function sign(CurrentUser $user, string $accion, array $sel, array $op, ?int $exp = null): string
    {
        $exp ??= time() + self::TTL;
        $mac = hash_hmac('sha256', 'revision-masiva|v1|' . $user->id . '|' . $exp . '|' . $this->canonical($accion, $sel, $op),
            (string) hex2bin((string) $this->config->get('app.key')));
        return $exp . '.' . $mac;
    }

    /** @var array<int,int>|null alias → canónico */
    private ?array $canon = null;

    private function canonicalEmployee(int $id): int
    {
        $this->canon ??= array_map('intval', array_column($this->db->fetchAll('SELECT id, fusionado_en_id FROM empleados WHERE fusionado_en_id IS NOT NULL'),
            'fusionado_en_id', 'id'));
        return $this->canon[$id] ?? $id;
    }
}
