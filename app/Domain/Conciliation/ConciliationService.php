<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Conciliation;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Identity\AccountVault;
use ConciliaRH\Domain\Identity\EmployeeService;
use ConciliaRH\Domain\Periods\PeriodService;
use ConciliaRH\Domain\ValidationException;
use PDOException;

final class ConciliationService
{
    public const ESTADOS_EMPLEADO = ['CORRECTO', 'PAGO_MENOR', 'PAGO_MAYOR', 'NO_PAGADO', 'PAGO_SIN_RESPALDO_RRHH', 'POSIBLE_PAGO_DUPLICADO',
        'PENDIENTE_IDENTIDAD', 'CONFLICTO_IDENTIDAD', 'ERROR_DATOS_RRHH', 'NO_APLICA'];
    public const GRUPOS_RESULTADO = [
        'CORRECTOS' => ['CORRECTO'],
        'DIFERENCIA' => ['PAGO_MENOR', 'PAGO_MAYOR', 'POSIBLE_PAGO_DUPLICADO', 'PAGO_SIN_RESPALDO_RRHH', 'ERROR_DATOS_RRHH'],
        'NO_PAGADOS' => ['NO_PAGADO'],
        'PENDIENTES' => ['PENDIENTE_IDENTIDAD', 'CONFLICTO_IDENTIDAD'],
        'SIN_PAGO' => ['NO_APLICA'],
    ];
    public const TERMINADAS = ['COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS', 'REVISION_MANUAL'];
    public const ESTADOS_INCIDENCIA = ['ABIERTA', 'EN_REVISION', 'RESUELTA', 'ACEPTADA_JUSTIFICADA'];

    public function __construct(
        private readonly Database $db,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function list(?int $loteId = null, int $limit = 50): array
    {
        $rows = $this->db->fetchAll(
            "SELECT c.id, c.numero, c.estado, c.lote_oficial, c.tolerancia, c.created_at, c.terminada_at, c.resumen, c.igual_a_id,
                    l.id AS lote_id, l.numero AS lote_numero, l.nombre AS lote_nombre, l.es_oficial, p.id AS periodo_id, p.descripcion AS periodo,
                    u.username AS solicitado_por_usuario
               FROM conciliaciones c JOIN lotes_importacion l ON l.id = c.lote_id JOIN periodos_nomina p ON p.id = c.periodo_id
               JOIN usuarios u ON u.id = c.solicitado_por
              WHERE (CAST(:l AS bigint) IS NULL OR c.lote_id = :l2)
              ORDER BY p.anio DESC, p.mes DESC, p.quincena DESC, l.numero, c.numero DESC LIMIT :lim",
            ['l' => $loteId, 'l2' => $loteId, 'lim' => $limit]
        );
        foreach ($rows as &$r) {
            $r['resumen'] = self::json($r['resumen']);
        }
        return $rows;
    }

    /** Lotes conciliables (no anulados) con su estado de procesamiento. @return list<array<string,mixed>> */
    public function lots(): array
    {
        return $this->db->fetchAll(
            "SELECT l.id, l.numero, l.nombre, l.es_oficial, l.estado, p.descripcion AS periodo, r.estado_procesamiento, r.archivos,
                    (SELECT count(*) FROM conciliaciones c WHERE c.lote_id = l.id) AS ejecuciones
               FROM lotes_importacion l JOIN periodos_nomina p ON p.id = l.periodo_id JOIN v_lotes_resumen r ON r.lote_id = l.id
              WHERE l.estado <> 'ANULADO' AND p.estado <> 'ANULADO' AND r.archivos > 0
              ORDER BY p.anio DESC, p.mes DESC, p.quincena DESC, l.numero"
        );
    }

    public function request(CurrentUser $actor, int $loteId, string $motivo): int
    {
        \ConciliaRH\Domain\Security\Throttle::check($this->db, $actor->id, ['conciliaciones.solicitar', 'conciliaciones.reprocesar'], 10, 600,
            'Alcanzó el máximo de conciliaciones solicitadas en 10 minutos. Espere a que terminen las anteriores.');
        $motivo = trim($motivo);
        if (mb_strlen($motivo) > 500) {
            throw new ValidationException(['motivo' => 'Máximo 500 caracteres.']);
        }
        return $this->db->transaction(function () use ($actor, $loteId, $motivo): int {
            $l = $this->db->fetchOne(
                "SELECT l.id, l.estado, r.estado_procesamiento, r.en_proceso,
                        (SELECT count(*) FROM conciliaciones c WHERE c.lote_id = l.id) AS previas
                   FROM lotes_importacion l JOIN v_lotes_resumen r ON r.lote_id = l.id WHERE l.id = :id",
                ['id' => $loteId]
            );
            if ($l === null) {
                throw HttpException::notFound();
            }
            if ((int) $l['previas'] > 0 && !$actor->can('conciliaciones.reprocesar')) {
                throw HttpException::forbidden();
            }
            if ((int) $l['previas'] > 0 && mb_strlen($motivo) < 5) {
                throw new ValidationException(['motivo' => 'Indique el motivo para volver a cotejar (mínimo 5 caracteres).']);
            }
            if ((int) $l['en_proceso'] > 0) {
                throw new ValidationException(['lote_id' => 'El lote tiene archivos en proceso; espere a que terminen de analizarse.']);
            }
            try {
                $c = $this->db->fetchOne('SELECT * FROM fn_solicitar_conciliacion(:l, :u, :m)', ['l' => $loteId, 'u' => $actor->id, 'm' => $motivo]);
            } catch (PDOException $e) {
                throw match ($e->errorInfo[0] ?? '') {
                    '23505' => new ValidationException(['lote_id' => 'Ya hay un cotejo en curso para este lote.']),
                    '23514' => new ValidationException(['lote_id' => 'El lote no admite otro cotejo: está anulado o su cotejo está cerrado (hay que invalidarlo primero).']),
                    '55006' => new ValidationException(['lote_id' => 'El lote tiene archivos en proceso; espere a que terminen de analizarse.']),
                    '22023' => new ValidationException(['motivo' => 'Indique el motivo para volver a cotejar (mínimo 5 caracteres).']),
                    '42501' => HttpException::forbidden(),
                    default => $e,
                };
            }
            $jobId = (int) $c['proceso_id'];
            $this->audit->record((int) $l['previas'] > 0 ? 'conciliaciones.reprocesar' : 'conciliaciones.solicitar', 'conciliaciones', (int) $c['id'],
                null, ['lote_id' => $loteId, 'numero' => (int) $c['numero'], 'tolerancia' => $c['tolerancia']],
                ['motivo' => $motivo !== '' ? $motivo : null, 'proceso_id' => $jobId, 'lote_oficial' => (bool) $c['lote_oficial']]);
            return (int) $c['id'];
        });
    }

    /** @return array<string,mixed> */
    public function find(int $id): array
    {
        $c = $this->db->fetchOne(
            "SELECT c.*, l.numero AS lote_numero, l.nombre AS lote_nombre, l.es_oficial AS lote_es_oficial_hoy, p.descripcion AS periodo,
                    u.username AS solicitado_por_usuario, uc.username AS cerrada_por_usuario, ui.username AS invalidada_por_usuario,
                    pr.estado AS proceso_estado
               FROM conciliaciones c JOIN lotes_importacion l ON l.id = c.lote_id JOIN periodos_nomina p ON p.id = c.periodo_id
               JOIN usuarios u ON u.id = c.solicitado_por LEFT JOIN usuarios uc ON uc.id = c.cerrada_por
               LEFT JOIN usuarios ui ON ui.id = c.invalidada_por LEFT JOIN procesos pr ON pr.id = c.proceso_id
              WHERE c.id = :id",
            ['id' => $id]
        );
        if ($c === null) {
            throw HttpException::notFound();
        }
        foreach (['totales', 'resumen', 'metricas'] as $k) {
            $c[$k] = self::json($c[$k]);
        }
        $c['incidencias_por_estado'] = array_column($this->db->fetchAll(
            'SELECT estado, count(*) AS n FROM conciliacion_incidencias WHERE conciliacion_id = :id GROUP BY estado', ['id' => $id]), 'n', 'estado');
        $c['incidencias_por_severidad'] = array_column($this->db->fetchAll(
            "SELECT severidad, count(*) AS n FROM conciliacion_incidencias WHERE conciliacion_id = :id AND estado IN ('ABIERTA', 'EN_REVISION')
              GROUP BY severidad", ['id' => $id]), 'n', 'severidad');
        return $c;
    }

    public function dashboardConciliation(): ?int
    {
        $id = $this->db->fetchValue(
            "SELECT v.id FROM v_conciliaciones_vigentes v JOIN lotes_importacion l ON l.id = v.lote_id JOIN periodos_nomina p ON p.id = v.periodo_id
              ORDER BY p.anio DESC, p.mes DESC, p.quincena DESC, l.es_oficial DESC, v.terminada_at DESC LIMIT 1"
        );
        return $id === null ? null : (int) $id;
    }

    public function isLatestFinished(array $c): bool
    {
        return !(bool) $this->db->fetchValue(
            "SELECT EXISTS (SELECT 1 FROM conciliaciones WHERE lote_id = :l AND numero > :n
                               AND estado IN ('COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS', 'REVISION_MANUAL', 'CERRADA'))",
            ['l' => (int) $c['lote_id'], 'n' => (int) $c['numero']]);
    }

    /** @return list<array<string,mixed>> */
    public function controls(int $id): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM conciliacion_controles WHERE conciliacion_id = :id ORDER BY nivel, grupo NULLS LAST, codigo, id', ['id' => $id]);
        foreach ($rows as &$r) {
            $r['detalle'] = self::json($r['detalle']);
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function files(int $id): array
    {
        return $this->db->fetchAll(
            'SELECT ca.*, a.nombre_original FROM conciliacion_archivos ca JOIN archivos_importados a ON a.id = ca.archivo_id
              WHERE ca.conciliacion_id = :id ORDER BY ca.rol, ca.archivo_id', ['id' => $id]);
    }

    /**
     * @param array{estado:string,grupo:string,banco:string,area:string,pension:bool,incidencia:bool,min:?string,max:?string,q:string} $f
     * @return array{rows:list<array<string,mixed>>,total:int,page:int}
     */
    public function employees(CurrentUser $user, int $id, array $f, int $page, int $perPage): array
    {
        $w = ['ce.conciliacion_id = :id'];
        $p = ['id' => $id];
        if (in_array($f['estado'], self::ESTADOS_EMPLEADO, true)) {
            $w[] = 'ce.estado = :estado';
            $p['estado'] = $f['estado'];
        } elseif ($f['estado'] === 'CON_DIFERENCIA') {
            $w[] = "ce.estado NOT IN ('CORRECTO', 'NO_APLICA')";
        } elseif (isset(self::GRUPOS_RESULTADO[$f['estado']])) {
            $w[] = 'ce.estado = ANY(CAST(:estados AS text[]))';
            $p['estados'] = '{' . implode(',', self::GRUPOS_RESULTADO[$f['estado']]) . '}';
        }
        if (in_array($f['grupo'], ['POLICIA', 'BOMBEROS', 'OTRO'], true)) {
            $w[] = 'ce.grupo = :grupo';
            $p['grupo'] = $f['grupo'];
        }
        $bancos = ['SANTANDER' => 'pagado_santander', 'BBVA' => 'pagado_bbva', 'INTERBANCARIA' => 'pagado_interbancaria', 'OTROS' => 'pagado_otros'];
        if (isset($bancos[$f['banco']])) {
            $w[] = 'ce.' . $bancos[$f['banco']] . ' <> 0';
        }
        if ($f['area'] !== '') {
            $w[] = "upper(ce.area) LIKE :area ESCAPE '!'";
            $p['area'] = '%' . EmployeeService::likeEscape(mb_strtoupper($f['area'])) . '%';
        }
        if ($f['pension']) {
            $w[] = 'ce.con_pension';
        }
        if ($f['incidencia']) {
            $w[] = "EXISTS (SELECT 1 FROM conciliacion_incidencias i WHERE i.conciliacion_id = ce.conciliacion_id AND i.empleado_id = ce.empleado_id
                              AND i.estado IN ('ABIERTA', 'EN_REVISION'))";
        }
        foreach (['min' => '>=', 'max' => '<='] as $k => $op) {
            if ($f[$k] !== null) {
                $w[] = "abs(ce.diferencia) $op CAST(:$k AS numeric)";
                $p[$k] = $f[$k];
            }
        }
        $q = trim($f['q']);
        if ($q !== '') {
            $idq = EmployeeService::normalizeId($q);
            if (self::canSeeIds($user) && (preg_match(EmployeeService::RFC_RE, $idq) || preg_match(EmployeeService::CURP_RE, $idq))) {
                $w[] = '(e.rfc = :idq OR e.curp = :idq2)';
                $p += ['idq' => $idq, 'idq2' => $idq];
            } elseif (preg_match('/^[0-9A-Z-]{1,20}$/', $idq) && preg_match('/\d/', $idq)) {
                $w[] = "ce.numero_empleado LIKE :num ESCAPE '!'";
                $p['num'] = EmployeeService::likeEscape($idq) . '%';
            } else {
                $key = EmployeeService::nameKey($q);
                if (mb_strlen($key) < 3) {
                    throw new ValidationException(['q' => 'Escriba al menos 3 letras del nombre o el número de empleado.']);
                }
                foreach (array_slice(array_filter(explode(' ', $key)), 0, 5) as $i => $t) {
                    $w[] = "e.nombre_normalizado LIKE :t$i ESCAPE '!'";
                    $p["t$i"] = '%' . EmployeeService::likeEscape($t) . '%';
                }
            }
        }
        $where = implode(' AND ', $w);
        $total = (int) $this->db->fetchValue("SELECT count(*) FROM conciliacion_empleados ce JOIN empleados e ON e.id = ce.empleado_id WHERE $where", $p);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $rows = $this->db->fetchAll(
            "SELECT ce.*, e.nombre_completo FROM conciliacion_empleados ce JOIN empleados e ON e.id = ce.empleado_id
              WHERE $where
              ORDER BY CASE ce.estado WHEN 'CORRECTO' THEN 2 WHEN 'NO_APLICA' THEN 3 ELSE 0 END, abs(ce.diferencia) DESC, ce.numero_empleado
              LIMIT :lim OFFSET :off",
            $p + ['lim' => $perPage, 'off' => ($page - 1) * $perPage]
        );
        foreach ($rows as &$r) {
            $r['detalle'] = self::json($r['detalle']);
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page];
    }

    /** @return array<string,array<string,int>> grupo (o TODOS) → grupo de resultado (o TODOS) → personas */
    public function resultCounts(int $id): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT grupo, estado, count(*) AS n FROM conciliacion_empleados WHERE conciliacion_id = :id GROUP BY grupo, estado',
            ['id' => $id]) as $r) {
            $res = 'OTRO';
            foreach (self::GRUPOS_RESULTADO as $k => $estados) {
                if (in_array($r['estado'], $estados, true)) {
                    $res = $k;
                }
            }
            foreach ([(string) $r['grupo'], 'TODOS'] as $g) {
                foreach ([$res, 'TODOS'] as $k) {
                    $out[$g][$k] = ($out[$g][$k] ?? 0) + (int) $r['n'];
                }
            }
        }
        return $out;
    }

    /** @return array{rows:list<array<string,mixed>>,total:int,page:int} */
    public function pensions(int $id, string $grupo, string $estado, int $page, int $perPage): array
    {
        $w = ['cp.conciliacion_id = :id'];
        $p = ['id' => $id];
        if (in_array($grupo, ['POLICIA', 'BOMBEROS', 'OTRO'], true)) {
            $w[] = 'cp.grupo = :g';
            $p['g'] = $grupo;
        }
        if (in_array($estado, ['CORRECTA', 'NO_DISPERSADA', 'PENSION_MENOR', 'PENSION_MAYOR', 'SIN_RETENCION_RRHH', 'PENDIENTE_IDENTIDAD'], true)) {
            $w[] = 'cp.estado = :e';
            $p['e'] = $estado;
        }
        $where = implode(' AND ', $w);
        $total = (int) $this->db->fetchValue("SELECT count(*) FROM conciliacion_pensiones cp WHERE $where", $p);
        $page = min($page, max(1, (int) ceil($total / $perPage)));
        $rows = $this->db->fetchAll(
            "SELECT cp.*, e.nombre_completo FROM conciliacion_pensiones cp JOIN empleados e ON e.id = cp.empleado_id WHERE $where
              ORDER BY CASE cp.estado WHEN 'CORRECTA' THEN 2 ELSE 0 END, cp.grupo, cp.numero_empleado LIMIT :lim OFFSET :off",
            $p + ['lim' => $perPage, 'off' => ($page - 1) * $perPage]);
        foreach ($rows as &$r) {
            $r['detalle'] = self::json($r['detalle']);
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page];
    }

    /** @return array<string,mixed> */
    public function employeeDetail(CurrentUser $user, int $id, int $empleadoId): array
    {
        $row = $this->db->fetchOne(
            'SELECT ce.*, e.nombre_completo, e.rfc, e.curp FROM conciliacion_empleados ce JOIN empleados e ON e.id = ce.empleado_id
              WHERE ce.conciliacion_id = :c AND ce.empleado_id = :e',
            ['c' => $id, 'e' => $empleadoId]
        );
        $pen = $this->db->fetchOne('SELECT * FROM conciliacion_pensiones WHERE conciliacion_id = :c AND empleado_id = :e', ['c' => $id, 'e' => $empleadoId]);
        if ($row === null && $pen === null) {
            throw HttpException::notFound();
        }
        if ($row !== null) {
            $row['detalle'] = self::json($row['detalle']);
            if (!self::canSeeIds($user)) {
                foreach (['rfc', 'curp'] as $k) {
                    $row[$k] = $row[$k] === null ? null : AccountVault::maskId((string) $row[$k]);
                }
            }
        }
        if ($pen !== null) {
            $pen['detalle'] = self::json($pen['detalle']);
        }
        $fuentes = $this->db->fetchAll(
            "SELECT f.*, a.nombre_original, coalesce(t.asociacion_metodo, r.asociacion_metodo) AS metodo, coalesce(t.asociado_at, r.asociado_at) AS asociado_at,
                    t.cuenta_ultimos4
               FROM conciliacion_fuentes f JOIN archivos_importados a ON a.id = f.archivo_id
               LEFT JOIN registros_tesoreria t ON f.tipo NOT IN ('RRHH', 'RRHH_PENSION') AND t.id = f.registro_id
               LEFT JOIN registros_rrhh r ON f.tipo IN ('RRHH', 'RRHH_PENSION') AND r.id = f.registro_id
              WHERE f.conciliacion_id = :c AND f.empleado_id = :e ORDER BY f.tipo, f.archivo_id, f.fila_origen",
            ['c' => $id, 'e' => $empleadoId]
        );
        $conceptos = ($row !== null && $row['registro_rrhh_id'] !== null) ? $this->db->fetchAll(
            'SELECT c.codigo, coalesce(k.descripcion, c.descripcion_original) AS descripcion, k.tipo, k.es_pension, c.importe
               FROM registros_rrhh_conceptos c JOIN conceptos_nomina k ON k.id = c.concepto_id WHERE c.registro_id = :r ORDER BY k.tipo DESC, c.codigo',
            ['r' => $row['registro_rrhh_id']]) : [];
        $incidencias = $user->can('incidencias.ver') ? $this->db->fetchAll(
            'SELECT * FROM conciliacion_incidencias WHERE conciliacion_id = :c AND empleado_id = :e ORDER BY id', ['c' => $id, 'e' => $empleadoId]) : null;
        $nombre = $this->db->fetchOne('SELECT numero_empleado, nombre_completo FROM empleados WHERE id = :e', ['e' => $empleadoId]);
        return ['nomina' => $row, 'pension' => $pen, 'fuentes' => $fuentes, 'conceptos' => $conceptos, 'incidencias' => $incidencias,
                'empleado' => $nombre];
    }

    /** @return array{rows:list<array<string,mixed>>,total:int,page:int} */
    public function incidents(int $id, array $f, int $page, int $perPage): array
    {
        $w = ['i.conciliacion_id = :id'];
        $p = ['id' => $id];
        if (in_array($f['estado'], self::ESTADOS_INCIDENCIA, true)) {
            $w[] = 'i.estado = :estado';
            $p['estado'] = $f['estado'];
        } elseif ($f['estado'] === 'PENDIENTES') {
            $w[] = "i.estado IN ('ABIERTA', 'EN_REVISION')";
        }
        if (in_array($f['severidad'], ['INFO', 'ADVERTENCIA', 'ERROR', 'CRITICA'], true)) {
            $w[] = 'i.severidad = :sev';
            $p['sev'] = $f['severidad'];
        }
        if (preg_match('/^[A-Z][A-Z0-9_]{2,59}$/', $f['tipo'])) {
            $w[] = 'i.tipo = :tipo';
            $p['tipo'] = $f['tipo'];
        }
        if (in_array($f['banco'], ['SANTANDER', 'BBVA', 'INTERBANCARIA'], true)) {
            $w[] = 'i.banco = :banco';
            $p['banco'] = $f['banco'];
        }
        if ($f['empleado'] !== '') {
            $w[] = 'e.numero_empleado = :num';
            $p['num'] = EmployeeService::normalizeId($f['empleado']);
        }
        $where = implode(' AND ', $w);
        $total = (int) $this->db->fetchValue("SELECT count(*) FROM conciliacion_incidencias i LEFT JOIN empleados e ON e.id = i.empleado_id WHERE $where", $p);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $rows = $this->db->fetchAll(
            "SELECT i.*, e.numero_empleado, e.nombre_completo, u.username AS actualizado_por_usuario
               FROM conciliacion_incidencias i LEFT JOIN empleados e ON e.id = i.empleado_id LEFT JOIN usuarios u ON u.id = i.actualizado_por
              WHERE $where
              ORDER BY (i.estado IN ('ABIERTA', 'EN_REVISION')) DESC,
                       CASE i.severidad WHEN 'CRITICA' THEN 0 WHEN 'ERROR' THEN 1 WHEN 'ADVERTENCIA' THEN 2 ELSE 3 END, abs(coalesce(i.importe, 0)) DESC, i.id
              LIMIT :lim OFFSET :off",
            $p + ['lim' => $perPage, 'off' => ($page - 1) * $perPage]
        );
        foreach ($rows as &$r) {
            $r['detalle'] = self::json($r['detalle']);
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page];
    }

    /** @return list<array{tipo:string,n:int}> */
    public function incidentTypes(int $id): array
    {
        return array_map(static fn ($r) => ['tipo' => (string) $r['tipo'], 'n' => (int) $r['n']], $this->db->fetchAll(
            'SELECT tipo, count(*) AS n FROM conciliacion_incidencias WHERE conciliacion_id = :id GROUP BY tipo ORDER BY 2 DESC', ['id' => $id]));
    }

    public function updateIncident(CurrentUser $actor, int $id, int $incidenciaId, string $estado, string $comentario, string $evidencia): void
    {
        if (!in_array($estado, self::ESTADOS_INCIDENCIA, true)) {
            throw new ValidationException(['estado' => 'Estado inválido.']);
        }
        $comentario = PeriodService::requiredReason($comentario);
        $evidencia = trim($evidencia);
        if (mb_strlen($evidencia) > 1000) {
            throw new ValidationException(['evidencia' => 'Máximo 1000 caracteres.']);
        }
        $this->db->transaction(function () use ($actor, $id, $incidenciaId, $estado, $comentario, $evidencia): void {
            $i = $this->db->fetchOne(
                'SELECT i.id, i.estado, i.tipo, i.severidad, c.estado AS conciliacion_estado FROM conciliacion_incidencias i
                   JOIN conciliaciones c ON c.id = i.conciliacion_id WHERE i.id = :i AND i.conciliacion_id = :c FOR UPDATE OF i',
                ['i' => $incidenciaId, 'c' => $id]
            );
            if ($i === null) {
                throw HttpException::notFound();
            }
            if (in_array($i['conciliacion_estado'], ['CERRADA', 'INVALIDADA'], true)) {
                throw new ValidationException(['estado' => 'La conciliación está cerrada o invalidada: sus incidencias no se modifican.']);
            }
            if ($i['estado'] === $estado) {
                throw new ValidationException(['estado' => 'La incidencia ya está en ese estado.']);
            }
            $this->db->execute(
                'UPDATE conciliacion_incidencias SET estado = :e, actualizado_por = :u, actualizado_at = now(), comentario = :c, evidencia = :ev WHERE id = :id',
                ['e' => $estado, 'u' => $actor->id, 'c' => $comentario, 'ev' => $evidencia !== '' ? $evidencia : null, 'id' => $incidenciaId]
            );
            $accion = match ($estado) {
                'ACEPTADA_JUSTIFICADA' => 'incidencias.justificar',
                'RESUELTA' => 'incidencias.resolver',
                default => 'incidencias.cambiar_estado',
            };
            $this->audit->record($accion, 'conciliacion_incidencias', $incidenciaId, ['estado' => $i['estado']], ['estado' => $estado],
                ['conciliacion_id' => $id, 'tipo' => $i['tipo'], 'severidad' => $i['severidad'], 'comentario' => $comentario]);
        });
    }

    public function close(CurrentUser $actor, int $id, string $motivo): void
    {
        $motivo = PeriodService::requiredReason($motivo);
        $this->db->transaction(function () use ($actor, $id, $motivo): void {
            $c = $this->db->fetchOne('SELECT id, estado FROM conciliaciones WHERE id = :id FOR UPDATE', ['id' => $id]);
            if ($c === null) {
                throw HttpException::notFound();
            }
            if (!in_array($c['estado'], self::TERMINADAS, true)) {
                throw new ValidationException(['estado' => 'Sólo una conciliación terminada se cierra.']);
            }
            $inc = array_column($this->db->fetchAll('SELECT estado, count(*) AS n FROM conciliacion_incidencias WHERE conciliacion_id = :id GROUP BY estado',
                ['id' => $id]), 'n', 'estado');
            $posterior = $this->db->fetchValue(
                "SELECT count(*) FROM conciliaciones WHERE lote_id = (SELECT lote_id FROM conciliaciones WHERE id = :id)
                    AND numero > (SELECT numero FROM conciliaciones WHERE id = :id2) AND estado NOT IN ('INVALIDADA', 'ERROR')",
                ['id' => $id, 'id2' => $id]);
            if ((int) $posterior > 0) {
                throw new ValidationException(['estado' => 'Sólo se cierra el cotejo más reciente del lote.']);
            }
            $this->db->execute("UPDATE conciliaciones SET estado = 'CERRADA', cerrada_por = :u, cerrada_at = now(), motivo_cierre = :m WHERE id = :id",
                ['u' => $actor->id, 'm' => $motivo, 'id' => $id]);
            $this->audit->record('conciliaciones.cerrar', 'conciliaciones', $id, ['estado' => $c['estado']], ['estado' => 'CERRADA'],
                ['motivo' => $motivo, 'incidencias' => array_map('intval', $inc)]);
        });
    }

    public function invalidate(CurrentUser $actor, int $id, string $motivo): void
    {
        $motivo = PeriodService::requiredReason($motivo);
        $this->db->transaction(function () use ($actor, $id, $motivo): void {
            $c = $this->db->fetchOne('SELECT id, estado FROM conciliaciones WHERE id = :id FOR UPDATE', ['id' => $id]);
            if ($c === null) {
                throw HttpException::notFound();
            }
            if (in_array($c['estado'], ['INVALIDADA', 'EJECUTANDO'], true)) {
                throw new ValidationException(['estado' => 'La conciliación no puede invalidarse en su estado actual.']);
            }
            if ($c['estado'] === 'CERRADA' && !$actor->isGod()) {
                throw HttpException::forbidden();
            }
            $this->db->execute("UPDATE conciliaciones SET estado = 'INVALIDADA', invalidada_por = :u, invalidada_at = now(), motivo_invalidacion = :m WHERE id = :id",
                ['u' => $actor->id, 'm' => $motivo, 'id' => $id]);
            $this->audit->record('conciliaciones.invalidar', 'conciliaciones', $id, ['estado' => $c['estado']], ['estado' => 'INVALIDADA'], ['motivo' => $motivo]);
        });
    }

    /** @return array{columns:list<string>,rows:list<list<string|null>>,numeric:list<int>} */
    public function exportRows(CurrentUser $actor, int $id, string $formato): array
    {
        \ConciliaRH\Domain\Security\Throttle::check($this->db, $actor->id, ['conciliaciones.exportar'], 10, 60,
            'Alcanzó el máximo de exportaciones por minuto. Intente de nuevo en un momento.');
        $c = $this->find($id);
        $rows = $this->db->fetchAll(
            'SELECT e.numero_empleado, e.nombre_completo, coalesce(ce.grupo, cp.grupo) AS grupo, ce.area, ce.neto_esperado, ce.pagado_santander,
                    ce.pagado_bbva, ce.pagado_interbancaria, ce.pagado_otros, ce.pagado_total, ce.diferencia, ce.estado, ce.alerta,
                    cp.retenido, cp.dispersado, cp.estado AS estado_pension
               FROM (SELECT * FROM conciliacion_empleados WHERE conciliacion_id = :id) ce
               FULL JOIN (SELECT * FROM conciliacion_pensiones WHERE conciliacion_id = :id2) cp ON cp.empleado_id = ce.empleado_id
               JOIN empleados e ON e.id = coalesce(ce.empleado_id, cp.empleado_id)
              ORDER BY 3, 1',
            ['id' => $id, 'id2' => $id]
        );
        $this->audit->record('conciliaciones.exportar', 'conciliaciones', $id, null, null,
            ['formato' => $formato, 'filas' => count($rows), 'lote_id' => $c['lote_id'], 'numero' => $c['numero']]);
        $columns = ['Número de empleado', 'Nombre', 'Grupo', 'Área', 'Neto RH', 'Santander', 'BBVA', 'Interbancaria', 'Otros', 'Total pagado',
            'Diferencia', 'Estado', 'Alerta', 'Pensión retenida', 'Pensión dispersada', 'Estado pensión'];
        return ['columns' => $columns, 'rows' => array_map(static fn (array $r) => array_map(static fn ($v) => $v === null ? null : (string) $v, array_values($r)), $rows),
                'numeric' => [4, 5, 6, 7, 8, 9, 10, 13, 14]];
    }

    /** @return list<array<string,mixed>> */
    public function policyRelations(int $loteId): array
    {
        return $this->db->fetchAll(
            'SELECT r.*, ae.nombre_original AS egreso, ad.nombre_original AS dispersion, u.username
               FROM relaciones_poliza_dispersion r JOIN archivos_importados ae ON ae.id = r.archivo_egreso_id
               JOIN archivos_importados ad ON ad.id = r.archivo_dispersion_id JOIN usuarios u ON u.id = r.creado_por
              WHERE r.lote_id = :l ORDER BY (r.estado = \'ACTIVA\') DESC, r.id DESC', ['l' => $loteId]);
    }

    public function relatePolicy(CurrentUser $actor, int $loteId, int $egresoId, int $hoja, int $dispersionId, string $motivo): int
    {
        $motivo = PeriodService::requiredReason($motivo);
        return $this->db->transaction(function () use ($actor, $loteId, $egresoId, $hoja, $dispersionId, $motivo): int {
            $ok = $this->db->fetchOne(
                "SELECT (SELECT n.tipo_registro FROM importaciones_normalizadas n WHERE n.archivo_id = :e AND n.estado = 'VIGENTE') AS te,
                        (SELECT n.tipo_registro FROM importaciones_normalizadas n WHERE n.archivo_id = :d AND n.estado = 'VIGENTE') AS td,
                        (SELECT lote_id FROM archivos_importados WHERE id = :e2) AS le, (SELECT lote_id FROM archivos_importados WHERE id = :d2) AS ld,
                        EXISTS (SELECT 1 FROM polizas_egreso_lineas pl JOIN importaciones_normalizadas n ON n.id = pl.importacion_id AND n.estado = 'VIGENTE'
                                WHERE pl.archivo_id = :e3 AND pl.hoja_indice = :h) AS hoja_ok",
                ['e' => $egresoId, 'd' => $dispersionId, 'e2' => $egresoId, 'd2' => $dispersionId, 'e3' => $egresoId, 'h' => $hoja]
            );
            if ($ok['te'] !== 'POLIZA_EGRESO' || $ok['td'] !== 'TESORERIA' || (int) $ok['le'] !== $loteId || (int) $ok['ld'] !== $loteId || !$ok['hoja_ok']) {
                throw new ValidationException(['relacion' => 'La hoja debe ser de una póliza de egreso y la dispersión de Tesorería, ambas del mismo lote.']);
            }
            try {
                $id = (int) $this->db->fetchValue(
                    'INSERT INTO relaciones_poliza_dispersion (lote_id, archivo_egreso_id, hoja_indice, archivo_dispersion_id, motivo, creado_por)
                     VALUES (:l, :e, :h, :d, :m, :u) RETURNING id',
                    ['l' => $loteId, 'e' => $egresoId, 'h' => $hoja, 'd' => $dispersionId, 'm' => $motivo, 'u' => $actor->id]);
            } catch (PDOException $e) {
                if (($e->errorInfo[0] ?? '') === '23505') {
                    throw new ValidationException(['relacion' => 'La hoja ya tiene una relación activa: anúlela primero.']);
                }
                if (($e->errorInfo[0] ?? '') === '23514') {
                    throw new ValidationException(['relacion' => 'El lote está anulado o su periodo cerrado: no admite relaciones nuevas.']);
                }
                throw $e;
            }
            $this->audit->record('conciliaciones.relacionar_poliza', 'relaciones_poliza_dispersion', $id, null,
                ['archivo_egreso_id' => $egresoId, 'hoja' => $hoja, 'archivo_dispersion_id' => $dispersionId], ['motivo' => $motivo]);
            return $id;
        });
    }

    public function annulPolicyRelation(CurrentUser $actor, int $loteId, int $relId, string $motivo): void
    {
        $motivo = PeriodService::requiredReason($motivo);
        $this->db->transaction(function () use ($actor, $loteId, $relId, $motivo): void {
            $r = $this->db->fetchOne('SELECT id, estado FROM relaciones_poliza_dispersion WHERE id = :id AND lote_id = :l FOR UPDATE', ['id' => $relId, 'l' => $loteId]);
            if ($r === null) {
                throw HttpException::notFound();
            }
            if ($r['estado'] !== 'ACTIVA') {
                throw new ValidationException(['relacion' => 'La relación ya está anulada.']);
            }
            $this->db->execute("UPDATE relaciones_poliza_dispersion SET estado = 'ANULADA', anulado_por = :u, anulado_at = now(), motivo_anulacion = :m WHERE id = :id",
                ['u' => $actor->id, 'm' => $motivo, 'id' => $relId]);
            $this->audit->record('conciliaciones.anular_relacion_poliza', 'relaciones_poliza_dispersion', $relId, ['estado' => 'ACTIVA'], ['estado' => 'ANULADA'], ['motivo' => $motivo]);
        });
    }

    /** Hojas de pólizas de egreso y dispersiones del lote (para la relación manual). @return array{hojas:list<array<string,mixed>>,dispersiones:list<array<string,mixed>>} */
    public function relationCandidates(int $loteId): array
    {
        return [
            'hojas' => $this->db->fetchAll(
                "SELECT l.archivo_id, l.hoja_indice, min(l.hoja_nombre) AS hoja_nombre, a.nombre_original
                   FROM polizas_egreso_lineas l JOIN importaciones_normalizadas n ON n.id = l.importacion_id AND n.estado = 'VIGENTE'
                   JOIN archivos_importados a ON a.id = l.archivo_id WHERE l.lote_id = :l GROUP BY l.archivo_id, l.hoja_indice, a.nombre_original
                  ORDER BY l.archivo_id, l.hoja_indice", ['l' => $loteId]),
            'dispersiones' => $this->db->fetchAll(
                "SELECT a.id, a.nombre_original, (SELECT min(t.banco) FROM registros_tesoreria t WHERE t.importacion_id = n.id) AS banco
                   FROM archivos_importados a JOIN importaciones_normalizadas n ON n.archivo_id = a.id AND n.estado = 'VIGENTE'
                  WHERE a.lote_id = :l AND n.tipo_registro = 'TESORERIA' ORDER BY a.id", ['l' => $loteId]),
        ];
    }

    public static function canSeeIds(CurrentUser $user): bool
    {
        return $user->can('conciliaciones.ver_sensible') && $user->can('empleados.ver_sensible');
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
}
