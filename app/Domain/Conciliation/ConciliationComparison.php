<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Conciliation;

use ConciliaRH\Core\Database;
use ConciliaRH\Domain\ValidationException;

final class ConciliationComparison
{
    private const MAX_CAMBIOS = 500;

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{asociaciones:int,cuentas:int,beneficiarios:int,importaciones:int,incidencias:int,empleados:int,hay_cambios:bool} */
    public function identityChangesSince(array $c): array
    {
        $zero = ['asociaciones' => 0, 'cuentas' => 0, 'beneficiarios' => 0, 'importaciones' => 0, 'incidencias' => 0, 'empleados' => 0, 'fusiones' => 0, 'hay_cambios' => false];
        if ($c['terminada_at'] === null) {
            return $zero;
        }
        $p = ['l' => (int) $c['lote_id'], 't' => $c['terminada_at']];
        $h = $this->db->fetchOne(
            "WITH regs AS (
                 SELECT 'TESORERIA'::text AS tipo, r.id FROM registros_tesoreria r
                   JOIN importaciones_normalizadas n ON n.id = r.importacion_id AND n.estado = 'VIGENTE' WHERE r.lote_id = :l
                 UNION ALL
                 SELECT 'RRHH', r.id FROM registros_rrhh r
                   JOIN importaciones_normalizadas n ON n.id = r.importacion_id AND n.estado = 'VIGENTE' WHERE r.lote_id = :l2)
             SELECT count(DISTINCT (h.registro_tipo, h.registro_id)) AS asociaciones,
                    count(DISTINCT coalesce(h.empleado_nuevo, h.empleado_anterior)) AS empleados,
                    array_remove(array_agg(DISTINCT coalesce(h.empleado_nuevo, h.empleado_anterior)), NULL)::text AS emps
               FROM asociaciones_historial h JOIN regs ON regs.tipo = h.registro_tipo AND regs.id = h.registro_id
              WHERE h.created_at > :t AND h.accion <> 'AUTOMATICA'",
            ['l' => $p['l'], 'l2' => $p['l'], 't' => $p['t']]);
        $cuentas = (int) $this->db->fetchValue(
            "SELECT count(*) FROM cuentas_bancarias c JOIN registros_tesoreria r ON r.id = c.origen_registro_id
              WHERE c.origen = 'CONFIRMACION_MANUAL' AND c.confirmado_at > :t AND r.lote_id = :l", ['t' => $p['t'], 'l' => $p['l']]);
        $benef = (int) $this->db->fetchValue(
            "SELECT count(*) FROM beneficiario_trabajadores bt
              WHERE (bt.created_at > :t OR bt.desactivado_at > :t2)
                AND (bt.evidencia->>'lote_id' = CAST(:l AS text)
                     OR bt.beneficiario_id IN (SELECT r.beneficiario_id FROM registros_tesoreria r WHERE r.lote_id = :l2 AND r.beneficiario_id IS NOT NULL))",
            ['t' => $p['t'], 't2' => $p['t'], 'l' => $p['l'], 'l2' => $p['l']]);
        $imps = (int) $this->db->fetchValue(
            "SELECT count(*) FROM importaciones_normalizadas n WHERE n.lote_id = :l AND (n.created_at > :t
                    OR (n.estado <> 'VIGENTE' AND n.id IN (SELECT ca.importacion_id FROM conciliacion_archivos ca WHERE ca.conciliacion_id = :c)))",
            ['l' => $p['l'], 't' => $p['t'], 'c' => (int) $c['id']]);
        $fus = (int) $this->db->fetchValue(
            "SELECT count(*) FROM fusiones_empleados f
              WHERE (f.created_at > :t OR f.separada_at > :t2)
                AND EXISTS (SELECT 1 FROM registros_rrhh r WHERE r.lote_id = :l AND r.empleado_id IN (f.origen_id, f.destino_id)
                            UNION ALL SELECT 1 FROM registros_tesoreria r WHERE r.lote_id = :l2 AND r.empleado_id IN (f.origen_id, f.destino_id))",
            ['t' => $p['t'], 't2' => $p['t'], 'l' => $p['l'], 'l2' => $p['l']]);
        $emps = trim((string) ($h['emps'] ?? '{}'), '{}');
        $inc = (int) $this->db->fetchValue(
            "SELECT count(*) FROM conciliacion_incidencias i WHERE i.conciliacion_id = :c AND i.estado IN ('ABIERTA', 'EN_REVISION')
                AND (i.empleado_id = ANY(CAST(:e AS bigint[])) OR (CAST(:hay AS boolean) AND i.tipo IN ('IMPORTE_PENDIENTE_IDENTIDAD', 'IMPORTE_PENSION_PENDIENTE_IDENTIDAD')))",
            ['c' => (int) $c['id'], 'e' => '{' . $emps . '}', 'hay' => ((int) $h['asociaciones'] + $cuentas) > 0 ? 'true' : 'false']);
        $out = ['asociaciones' => (int) $h['asociaciones'], 'cuentas' => $cuentas, 'beneficiarios' => $benef, 'importaciones' => $imps,
                'incidencias' => $inc, 'empleados' => (int) $h['empleados'], 'fusiones' => $fus];
        $out['hay_cambios'] = $out['asociaciones'] + $out['cuentas'] + $out['beneficiarios'] + $out['importaciones'] + $out['fusiones'] > 0;
        return $out;
    }

    /** @return list<array<string,mixed>> otras ejecuciones terminadas del mismo lote */
    public function siblings(array $c): array
    {
        return $this->db->fetchAll(
            "SELECT id, numero, estado, terminada_at FROM conciliaciones WHERE lote_id = :l AND id <> :id AND totales <> '{}'::jsonb
              AND estado IN ('COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS', 'REVISION_MANUAL', 'CERRADA', 'INVALIDADA') ORDER BY numero DESC",
            ['l' => (int) $c['lote_id'], 'id' => (int) $c['id']]);
    }

    /** @return array<string,mixed> */
    public function compare(array $a, array $b): array
    {
        if ((int) $a['lote_id'] !== (int) $b['lote_id']) {
            throw new ValidationException(['comparar' => 'Sólo se comparan ejecuciones del mismo lote.']);
        }
        if ($a['totales'] === [] || $b['totales'] === []) {
            throw new ValidationException(['comparar' => 'Ambas ejecuciones deben estar terminadas.']);
        }
        if ((int) $a['numero'] > (int) $b['numero']) {
            [$a, $b] = [$b, $a];
        }
        $estados = [];
        foreach ([$a['resumen']['por_estado'] ?? [], $b['resumen']['por_estado'] ?? []] as $i => $m) {
            foreach ($m as $k => $n) {
                $estados[$k][$i] = (int) $n;
            }
        }
        $pens = [];
        foreach ([$a['resumen']['pensiones_por_estado'] ?? [], $b['resumen']['pensiones_por_estado'] ?? []] as $i => $m) {
            foreach ($m as $k => $n) {
                $pens[$k][$i] = (int) $n;
            }
        }
        foreach ([&$estados, &$pens] as &$m) {
            foreach ($m as &$par) {
                $par = [$par[0] ?? 0, $par[1] ?? 0];
            }
            unset($par);
        }
        unset($m);
        $ta = $a['totales']['TOTAL'] ?? [];
        $tb = $b['totales']['TOTAL'] ?? [];
        $metricas = [
            'Diferencia global (pagado − RH)' => [$ta['diferencia_global_nomina'] ?? null, $tb['diferencia_global_nomina'] ?? null],
            'Tesorería identificada' => [$ta['tesoreria_nomina']['identificado'] ?? null, $tb['tesoreria_nomina']['identificado'] ?? null],
            'Pendiente de identificar (nómina)' => [$ta['tesoreria_nomina']['pendiente_identidad'] ?? null, $tb['tesoreria_nomina']['pendiente_identidad'] ?? null],
            'Pensión retenida' => [$ta['pensiones']['retenido'] ?? null, $tb['pensiones']['retenido'] ?? null],
            'Pensión dispersada' => [$ta['pensiones']['dispersado'] ?? null, $tb['pensiones']['dispersado'] ?? null],
            'Pensión pendiente de identidad' => [$ta['pensiones']['pendiente_identidad'] ?? null, $tb['pensiones']['pendiente_identidad'] ?? null],
        ];
        $pct = [$a['resumen']['pct_conciliado'] ?? null, $b['resumen']['pct_conciliado'] ?? null];
        $p = ['a' => (int) $a['id'], 'b' => (int) $b['id']];
        $total = (int) $this->db->fetchValue(
            "SELECT count(*) FROM (SELECT * FROM conciliacion_empleados WHERE conciliacion_id = :a) x
               FULL JOIN (SELECT * FROM conciliacion_empleados WHERE conciliacion_id = :b) y ON y.empleado_id = x.empleado_id
              WHERE x.estado IS DISTINCT FROM y.estado OR x.diferencia IS DISTINCT FROM y.diferencia", $p);
        $cambios = $this->db->fetchAll(
            "SELECT coalesce(x.empleado_id, y.empleado_id) AS empleado_id, e.numero_empleado, e.nombre_completo, coalesce(y.grupo, x.grupo) AS grupo,
                    x.estado AS estado_a, y.estado AS estado_b, x.diferencia AS dif_a, y.diferencia AS dif_b, x.pagado_total AS pag_a, y.pagado_total AS pag_b
               FROM (SELECT * FROM conciliacion_empleados WHERE conciliacion_id = :a) x
               FULL JOIN (SELECT * FROM conciliacion_empleados WHERE conciliacion_id = :b) y ON y.empleado_id = x.empleado_id
               JOIN empleados e ON e.id = coalesce(x.empleado_id, y.empleado_id)
              WHERE x.estado IS DISTINCT FROM y.estado OR x.diferencia IS DISTINCT FROM y.diferencia
              ORDER BY (x.estado = 'CORRECTO') ASC, abs(coalesce(y.diferencia, 0) - coalesce(x.diferencia, 0)) DESC, e.numero_empleado
              LIMIT " . self::MAX_CAMBIOS, $p);
        $this->explain($cambios, $a, $b);
        return ['a' => $a, 'b' => $b, 'estados' => $estados, 'pensiones' => $pens, 'metricas' => $metricas, 'pct' => $pct,
                'cambios' => $cambios, 'total_cambios' => $total, 'limite' => self::MAX_CAMBIOS];
    }

    /** Motivos derivados de las fuentes de ambas ejecuciones y del historial (2 consultas para toda la lista). @param list<array<string,mixed>> $rows */
    private function explain(array &$rows, array $a, array $b): void
    {
        if ($rows === []) {
            return;
        }
        $emps = '{' . implode(',', array_map(static fn ($r) => (int) $r['empleado_id'], $rows)) . '}';
        $src = $this->db->fetchAll(
            "SELECT f.conciliacion_id, f.empleado_id, f.tipo, f.registro_id, f.archivo_id, f.importe FROM conciliacion_fuentes f
              WHERE f.conciliacion_id IN (:a, :b) AND f.tipo IN ('RRHH', 'TESORERIA_NOMINA') AND f.empleado_id = ANY(CAST(:e AS bigint[]))",
            ['a' => (int) $a['id'], 'b' => (int) $b['id'], 'e' => $emps]);
        $pendA = array_column($this->db->fetchAll(
            "SELECT registro_id FROM conciliacion_fuentes WHERE conciliacion_id = :a AND tipo = 'PENDIENTE_NOMINA'", ['a' => (int) $a['id']]), 'registro_id');
        $pendA = array_flip(array_map('intval', $pendA));
        $by = [];
        foreach ($src as $s) {
            $by[(int) $s['empleado_id']][(int) $s['conciliacion_id'] === (int) $a['id'] ? 'a' : 'b'][$s['tipo']][(int) $s['registro_id']] = $s;
        }
        $nuevos = [];
        foreach ($rows as $r) {
            $e = (int) $r['empleado_id'];
            foreach (array_diff_key($by[$e]['b']['TESORERIA_NOMINA'] ?? [], $by[$e]['a']['TESORERIA_NOMINA'] ?? []) as $rid => $_) {
                $nuevos[$rid] = true;
            }
        }
        $hist = $nuevos === [] ? [] : array_column($this->db->fetchAll(
            "SELECT DISTINCT ON (h.registro_id) h.registro_id, h.accion, h.metodo, h.accion_masiva_id, h.created_at, u.username
               FROM asociaciones_historial h LEFT JOIN usuarios u ON u.id = h.usuario_id
              WHERE h.registro_tipo = 'TESORERIA' AND h.registro_id = ANY(CAST(:r AS bigint[])) AND h.accion IN ('ASOCIAR', 'REASOCIACION', 'AUTOMATICA')
                AND h.created_at <= CAST(:fin AS timestamptz)
              ORDER BY h.registro_id, h.id DESC", ['r' => '{' . implode(',', array_keys($nuevos)) . '}', 'fin' => $b['terminada_at']]), null, 'registro_id');
        $fus = [];
        foreach ($this->db->fetchAll(
            "SELECT f.id, f.origen_id, f.destino_id, f.created_at, u.username, o.numero_empleado AS origen_numero, d.numero_empleado AS destino_numero
               FROM fusiones_empleados f JOIN usuarios u ON u.id = f.usuario_id JOIN empleados o ON o.id = f.origen_id JOIN empleados d ON d.id = f.destino_id
              WHERE f.estado = 'APLICADA' AND f.created_at > CAST(:ini AS timestamptz) AND f.created_at <= CAST(:fin AS timestamptz)
                AND (f.origen_id = ANY(CAST(:e AS bigint[])) OR f.destino_id = ANY(CAST(:e2 AS bigint[])))",
            ['ini' => $a['terminada_at'], 'fin' => $b['terminada_at'], 'e' => $emps, 'e2' => $emps]) as $f) {
            $fus[(int) $f['origen_id']][] = ['rol' => 'origen'] + $f;
            $fus[(int) $f['destino_id']][] = ['rol' => 'destino'] + $f;
        }
        foreach ($this->db->fetchAll(
            "SELECT f.id, f.origen_id, f.destino_id, u.username, o.numero_empleado AS origen_numero, d.numero_empleado AS destino_numero
               FROM fusiones_empleados f JOIN usuarios u ON u.id = f.separada_por JOIN empleados o ON o.id = f.origen_id JOIN empleados d ON d.id = f.destino_id
              WHERE f.estado = 'SEPARADA' AND f.separada_at > CAST(:ini AS timestamptz) AND f.separada_at <= CAST(:fin AS timestamptz)
                AND (f.origen_id = ANY(CAST(:e AS bigint[])) OR f.destino_id = ANY(CAST(:e2 AS bigint[])))",
            ['ini' => $a['terminada_at'], 'fin' => $b['terminada_at'], 'e' => $emps, 'e2' => $emps]) as $f) {
            $fus[(int) $f['origen_id']][] = ['rol' => 'separado'] + $f;
            $fus[(int) $f['destino_id']][] = ['rol' => 'separado'] + $f;
        }
        $rawEmp = [];
        if ($fus !== [] && $nuevos !== []) {
            $rawEmp = array_column($this->db->fetchAll('SELECT id, empleado_id FROM registros_tesoreria WHERE id = ANY(CAST(:r AS bigint[]))',
                ['r' => '{' . implode(',', array_keys($nuevos)) . '}']), 'empleado_id', 'id');
        }
        foreach ($rows as &$r) {
            $e = (int) $r['empleado_id'];
            $sa = $by[$e]['a'] ?? [];
            $sb = $by[$e]['b'] ?? [];
            $motivos = [];
            $aliasDe = [];
            foreach ($fus[$e] ?? [] as $f) {
                if ($f['rol'] === 'separado') {
                    $motivos[] = "La fusión #{$f['id']} ({$f['origen_numero']} → {$f['destino_numero']}) se separó ({$f['username']}): sus registros vuelven a contarse por separado.";
                } elseif ($f['rol'] === 'origen') {
                    $motivos[] = "El empleado se fusionó en el número {$f['destino_numero']} (fusión #{$f['id']} de {$f['username']}): sus registros cuentan ahora para la identidad canónica.";
                } else {
                    $motivos[] = "Recibe los registros del número {$f['origen_numero']} por la fusión #{$f['id']} de {$f['username']}.";
                    $aliasDe[(int) $f['origen_id']] = $f;
                }
            }
            foreach (array_diff_key($sb['TESORERIA_NOMINA'] ?? [], $sa['TESORERIA_NOMINA'] ?? []) as $rid => $f) {
                if (isset($rawEmp[$rid], $aliasDe[(int) $rawEmp[$rid]])) {
                    continue;                                    // ya explicado por la fusión
                }
                $h = $hist[$rid] ?? null;
                $quien = $h ? ' (' . ($h['accion'] === 'ASOCIAR' ? 'asociación manual' : 'asociación automática') . ($h['username'] ? ' de ' . $h['username'] : '')
                    . ($h['accion_masiva_id'] ? ', acción masiva #' . $h['accion_masiva_id'] : '') . ')' : '';
                $motivos[] = isset($pendA[$rid])
                    ? "Se confirmó una transferencia previamente pendiente de identidad (registro #$rid)$quien."
                    : "Nuevo pago identificado en el archivo #{$f['archivo_id']} (registro #$rid)$quien.";
            }
            $esOrigen = array_filter($fus[$e] ?? [], static fn ($f) => $f['rol'] === 'origen') !== [];
            foreach ($esOrigen ? [] : array_diff_key($sa['TESORERIA_NOMINA'] ?? [], $sb['TESORERIA_NOMINA'] ?? []) as $rid => $f) {
                $motivos[] = "El pago del registro #$rid ya no se atribuye a la persona (asociación corregida, archivo excluido o sustituido).";
            }
            if (array_keys($sa['RRHH'] ?? []) !== array_keys($sb['RRHH'] ?? []) && !isset($fus[$e])) {
                $motivos[] = ($sb['RRHH'] ?? []) === [] ? 'La persona ya no aparece en la fuente RRHH del lote.'
                    : (($sa['RRHH'] ?? []) === [] ? 'La persona aparece en la fuente RRHH por primera vez.' : 'Cambió el registro RRHH fuente (otro tabulado o reimportación).');
            }
            if ($motivos === []) {
                if ((int) $a['motor_version'] !== (int) $b['motor_version']) {
                    $motivos[] = 'Mismas fuentes; cambió la versión del motor de conciliación.';
                } elseif ($a['tolerancia'] !== $b['tolerancia']) {
                    $motivos[] = 'Mismas fuentes; cambió la tolerancia monetaria.';
                } else {
                    $motivos[] = 'Sin diferencias en las fuentes de nómina de la persona (el cambio proviene de otras reglas del lote, p. ej. pagos pendientes que la explican).';
                }
            }
            $r['motivos'] = $motivos;
        }
    }

}
