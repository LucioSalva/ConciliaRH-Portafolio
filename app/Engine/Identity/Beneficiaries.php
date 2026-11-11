<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Identity;

use ConciliaRH\Core\Database;
use ConciliaRH\Engine\Support\BatchInsert;
use ConciliaRH\Engine\Support\Dec;
use ConciliaRH\Engine\Support\Json;
use ConciliaRH\Engine\Support\Py;

final class Beneficiaries
{
    public const FUERTES = ['NUMERO_EMPLEADO', 'RFC', 'CURP', 'CUENTA_CONFIRMADA'];
    public const MOTIVOS_PROPIOS = ['PENSION_SIN_TRABAJADOR', 'BENEFICIARIO_CONOCIDO', 'IMPORTE_RETENCION_UNICA', 'BENEFICIARIO_E_IMPORTE',
                                    'CLABE_Y_NOMBRE_HISTORICOS', 'IMPORTE_RECURRENTE_MISMA_CLABE', 'CLABE_ASOCIADA_OTRO_PERIODO', 'SENALES_CONTRADICTORIAS'];
    public const EDITABLES = ['NO_IDENTIFICADO', 'SUGERENCIA', 'AMBIGUO'];
    private const CATEGORIA = ['MEDIA' => 'MEDIA', 'BAJA' => 'BAJA', 'NINGUNA' => 'SIN_EVIDENCIA'];

    /** @return array<string,int> */
    public static function link(Database $db, int $importacionId): array
    {
        $out = [];
        $inc = static function (string $k) use (&$out): void {
            $out[$k] = ($out[$k] ?? 0) + 1;
        };
        $regs = $db->fetchAll(
            "SELECT r.id, r.empleado_id, r.asociacion_metodo, r.nombre_original, r.nombre_normalizado, r.cuenta_hmac, r.periodo_id,
                    r.archivo_id, r.lote_id, r.fila_origen, r.banco, pe.fecha_fin
               FROM registros_tesoreria r JOIN periodos_nomina pe ON pe.id = r.periodo_id
              WHERE r.importacion_id = :i AND r.categoria = 'PENSION' AND r.beneficiario_id IS NULL
                AND r.asociacion_estado IN ('ASOCIADO_AUTOMATICO', 'ASOCIADO_MANUAL') AND r.empleado_id IS NOT NULL
              ORDER BY r.id", ['i' => $importacionId]);
        foreach ($regs as $r) {
            $ben = null;
            $cuenta = null;
            $emp = (int) $r['empleado_id'];
            if ($r['cuenta_hmac'] !== null && $r['cuenta_hmac'] !== '') {
                $cuenta = $db->fetchOne("SELECT id, empleado_id, beneficiario_id FROM cuentas_bancarias
                                          WHERE cuenta_hmac = :h AND uso = 'PENSION_BENEFICIARIO' AND estado = 'ACTIVA'", ['h' => $r['cuenta_hmac']]);
                if ($cuenta !== null && (int) $cuenta['empleado_id'] === $emp && $cuenta['beneficiario_id'] !== null
                    && $db->fetchOne("SELECT 1 FROM beneficiario_trabajadores WHERE beneficiario_id = :b AND empleado_id = :e AND estado = 'ACTIVA'",
                        ['b' => (int) $cuenta['beneficiario_id'], 'e' => $emp]) !== null) {
                    $ben = (int) $cuenta['beneficiario_id'];
                    $inc('por_cuenta');
                }
            }
            if ($ben === null && $r['nombre_normalizado'] !== null && $r['nombre_normalizado'] !== '') {
                $found = $db->fetchAll(
                    "SELECT b.id FROM beneficiarios_pension b
                       JOIN beneficiario_trabajadores bt ON bt.beneficiario_id = b.id AND bt.estado = 'ACTIVA'
                      WHERE bt.empleado_id = :e AND b.nombre_normalizado = :n AND b.estado = 'ACTIVO'", ['e' => $emp, 'n' => $r['nombre_normalizado']]);
                if (count($found) === 1) {
                    $ben = (int) $found[0]['id'];
                    $inc('por_trabajador_y_nombre');
                }
            }
            if ($ben === null) {
                if (!in_array($r['asociacion_metodo'], self::FUERTES, true) || ($r['nombre_original'] ?? '') === '' || ($r['nombre_normalizado'] ?? '') === '') {
                    $inc('sin_beneficiario');
                    continue;
                }
                $ben = (int) $db->fetchValue(
                    "INSERT INTO beneficiarios_pension (nombre_original, nombre_normalizado, origen, origen_registro_id, primer_periodo_id, ultimo_periodo_id)
                     VALUES (:no, :nn, 'EVIDENCIA_FUERTE', :r, :p, :p2) RETURNING id",
                    ['no' => mb_substr(Py::strip((string) $r['nombre_original']), 0, 200), 'nn' => mb_substr((string) $r['nombre_normalizado'], 0, 200),
                     'r' => (int) $r['id'], 'p' => (int) $r['periodo_id'], 'p2' => (int) $r['periodo_id']]);
                $db->execute(
                    'INSERT INTO beneficiario_trabajadores (beneficiario_id, empleado_id, origen, evidencia, vigente_desde_periodo_id)
                     VALUES (:b, :e, :o, CAST(:ev AS jsonb), :p)',
                    ['b' => $ben, 'e' => $emp, 'o' => $r['asociacion_metodo'] === 'CUENTA_CONFIRMADA' ? 'CUENTA_CONFIRMADA' : 'NUMERO_EMPLEADO_ARCHIVO',
                     'ev' => Json::encode(['registro_id' => (int) $r['id'], 'archivo_id' => (int) $r['archivo_id'], 'lote_id' => (int) $r['lote_id'],
                                           'periodo_id' => (int) $r['periodo_id'], 'fila' => (int) $r['fila_origen'], 'banco' => $r['banco'],
                                           'metodo' => $r['asociacion_metodo']]),
                     'p' => (int) $r['periodo_id']]);
                $inc('beneficiarios_nuevos');
            }
            $db->execute('UPDATE registros_tesoreria SET beneficiario_id = :b WHERE id = :id', ['b' => $ben, 'id' => (int) $r['id']]);
            $db->execute('UPDATE beneficiarios_pension b SET ultimo_periodo_id = :p
                           WHERE b.id = :b AND (SELECT fecha_fin FROM periodos_nomina WHERE id = b.ultimo_periodo_id) < CAST(:f AS date)',
                ['p' => (int) $r['periodo_id'], 'b' => $ben, 'f' => $r['fecha_fin']]);
            if ($cuenta !== null && (int) $cuenta['empleado_id'] === $emp && $cuenta['beneficiario_id'] === null) {
                $db->execute('UPDATE cuentas_bancarias SET beneficiario_id = :b WHERE id = :id AND beneficiario_id IS NULL', ['b' => $ben, 'id' => (int) $cuenta['id']]);
                $inc('cuentas_ligadas');
            }
            $inc('enlazados');
        }
        return $out;
    }

    /** @return array<int,int> */
    private static function canonMap(Database $db): array
    {
        $out = [];
        foreach ($db->fetchAll('SELECT id, fusionado_en_id FROM empleados WHERE fusionado_en_id IS NOT NULL') as $e) {
            $out[(int) $e['id']] = (int) $e['fusionado_en_id'];
        }
        return $out;
    }

    /**
     * @param array<int,int> $canon
     * @return array<string,array<int,true>> importe (clave decimal) => trabajadores
     */
    public static function lotWithholdings(Database $db, int $loteId, array $canon = []): array
    {
        $cn = static fn (int $e): int => $canon[$e] ?? $e;
        $porImp = [];
        foreach ($db->fetchAll(
            "SELECT r.empleado_id, r.importacion_id, c.importe, n.metricas->'conceptos_tipo_inferido'->>'signo_deducciones' AS signo
               FROM registros_rrhh_conceptos c JOIN registros_rrhh r ON r.id = c.registro_id
               JOIN importaciones_normalizadas n ON n.id = r.importacion_id AND n.estado = 'VIGENTE'
               JOIN analisis_archivo an ON an.id = n.analisis_id
               JOIN conceptos_nomina k ON k.id = c.concepto_id AND k.es_pension
              WHERE r.lote_id = :l AND r.empleado_id IS NOT NULL AND c.importe_estado = 'OK'
                AND r.asociacion_estado IN ('ASOCIADO_AUTOMATICO', 'ASOCIADO_MANUAL')
                AND NOT coalesce(an.advertencias @> '[{\"codigo\": \"VISTA_DERIVADA\"}]'::jsonb, false)", ['l' => $loteId]) as $x) {
            $imp = (string) $x['importe'];
            $v = $x['signo'] === 'NEGATIVO' ? Dec::neg($imp) : ($x['signo'] === 'POSITIVO' ? $imp : Dec::abs($imp));
            $k = $cn((int) $x['empleado_id']) . ':' . (int) $x['importacion_id'];
            $porImp[$k] = Dec::add($porImp[$k] ?? '0', $v);
        }
        $porEmp = [];
        foreach ($porImp as $k => $v) {
            $e = (int) explode(':', (string) $k)[0];
            $cur = $porEmp[$e] ?? '0';
            $porEmp[$e] = Dec::ge($cur, $v) ? $cur : $v;
        }
        foreach ($db->fetchAll(
            "SELECT t.empleado_id, sum(t.importe) AS s FROM registros_tesoreria t
               JOIN importaciones_normalizadas n ON n.id = t.importacion_id AND n.estado = 'VIGENTE'
              WHERE t.lote_id = :l AND t.categoria = 'PENSION' AND t.empleado_id IS NOT NULL AND t.importe_estado = 'OK'
                AND t.asociacion_estado IN ('ASOCIADO_AUTOMATICO', 'ASOCIADO_MANUAL')
              GROUP BY t.empleado_id", ['l' => $loteId]) as $x) {
            $e = $cn((int) $x['empleado_id']);
            if (isset($porEmp[$e])) {
                $porEmp[$e] = Dec::sub($porEmp[$e], (string) $x['s']);
            }
        }
        $out = [];
        foreach ($porEmp as $e => $v) {
            if (Dec::gt($v, '0')) {
                $out[Dec::key(Dec::quantizeHalfEven($v, 2))][$e] = true;
            }
        }
        return $out;
    }

    /**
     * @param list<string> $hmacs
     * @param array<int,int> $canon
     * @return array<string,list<array<string,mixed>>>
     */
    private static function clabeHistory(Database $db, int $loteId, array $hmacs, array $canon): array
    {
        if ($hmacs === []) {
            return [];
        }
        $out = [];
        foreach ($db->fetchAll(
            "SELECT t.cuenta_hmac, t.periodo_id, t.empleado_id, t.asociacion_estado, t.nombre_normalizado, t.importe, t.asociacion_evidencia AS ev
               FROM registros_tesoreria t
               JOIN importaciones_normalizadas n ON n.id = t.importacion_id AND n.estado = 'VIGENTE'
               JOIN lotes_importacion l ON l.id = t.lote_id AND l.estado <> 'ANULADO'
              WHERE t.categoria = 'PENSION' AND t.cuenta_hmac = ANY(CAST(:h AS text[]))
                AND t.periodo_id <> (SELECT periodo_id FROM lotes_importacion WHERE id = :l)
              ORDER BY t.id", ['h' => BatchInsert::arrayLiteral($hmacs), 'l' => $loteId]) as $x) {
            if ($x['empleado_id'] !== null) {
                $x['empleado_id'] = $canon[(int) $x['empleado_id']] ?? (int) $x['empleado_id'];
            }
            $x['ev'] = Json::decode($x['ev']) ?? [];
            $out[(string) $x['cuenta_hmac']][] = $x;
        }
        return $out;
    }

    /** @return array<string,int> */
    public static function suggest(Database $db, int $loteId, ?int $procesoId): array
    {
        $out = [];
        $pend = $db->fetchAll(
            "SELECT r.id, r.asociacion_estado, r.asociacion_version, r.asociacion_confianza, r.asociacion_evidencia, r.nombre_normalizado,
                    r.importe, r.rechazados, r.cuenta_hmac
               FROM registros_tesoreria r
               JOIN importaciones_normalizadas n ON n.id = r.importacion_id AND n.estado = 'VIGENTE'
               JOIN periodos_nomina pe ON pe.id = r.periodo_id AND pe.estado = 'ABIERTO'
               JOIN lotes_importacion l ON l.id = r.lote_id AND l.estado <> 'ANULADO'
              WHERE r.lote_id = :l AND r.categoria = 'PENSION' AND r.empleado_id IS NULL AND r.asociacion_estado = ANY(CAST(:e AS text[]))
                AND coalesce(r.asociacion_evidencia->>'motivo', '') = ANY(CAST(:m AS text[]))
              ORDER BY r.id", ['l' => $loteId, 'e' => BatchInsert::arrayLiteral(self::EDITABLES), 'm' => BatchInsert::arrayLiteral(self::MOTIVOS_PROPIOS)]);
        if ($pend === []) {
            return [];
        }
        $canon = self::canonMap($db);
        $cn = static fn (int $e): int => $canon[$e] ?? $e;
        $retenciones = self::lotWithholdings($db, $loteId, $canon);
        $importes = [];
        foreach ($db->fetchAll(
            "SELECT r.importe FROM registros_tesoreria r JOIN importaciones_normalizadas n ON n.id = r.importacion_id AND n.estado = 'VIGENTE'
              WHERE r.lote_id = :l AND r.categoria = 'PENSION' AND r.empleado_id IS NULL AND r.importe IS NOT NULL
                AND r.asociacion_estado IN ('SUGERENCIA', 'AMBIGUO', 'CONFLICTO', 'NO_IDENTIFICADO')", ['l' => $loteId]) as $x) {
            $k = Dec::key(Dec::quantizeHalfEven((string) $x['importe'], 2));
            $importes[$k] = ($importes[$k] ?? 0) + 1;
        }
        $nombres = [];
        foreach ($pend as $r) {
            if ($r['nombre_normalizado'] !== null && $r['nombre_normalizado'] !== '') {
                $nombres[(string) $r['nombre_normalizado']] = true;
            }
        }
        $conocidos = [];
        if ($nombres !== []) {
            foreach ($db->fetchAll(
                "SELECT b.nombre_normalizado, b.id, bt.empleado_id FROM beneficiarios_pension b
                   JOIN beneficiario_trabajadores bt ON bt.beneficiario_id = b.id AND bt.estado = 'ACTIVA'
                  WHERE b.estado = 'ACTIVO' AND b.nombre_normalizado = ANY(CAST(:n AS text[]))",
                ['n' => BatchInsert::arrayLiteral(array_map('strval', array_keys($nombres)))]) as $b) {
                $conocidos[(string) $b['nombre_normalizado']][(int) $b['id']][$cn((int) $b['empleado_id'])] = true;
            }
        }
        $hmacs = [];
        foreach ($pend as $r) {
            if ($r['cuenta_hmac'] !== null && $r['cuenta_hmac'] !== '') {
                $hmacs[(string) $r['cuenta_hmac']] = true;
            }
        }
        $hmacs = array_map('strval', array_keys($hmacs));
        sort($hmacs, SORT_STRING);
        $historia = self::clabeHistory($db, $loteId, $hmacs, $canon);
        $hist = [];
        foreach ($pend as $r) {
            $rech = [];
            foreach (self::intArray($r['rechazados']) as $x) {
                $rech[$cn($x)] = true;
            }
            $senales = [];
            $detalle = [];
            $porNombre = [];
            $beneficiario = null;
            $bens = $conocidos[(string) ($r['nombre_normalizado'] ?? '')] ?? [];
            if (count($bens) === 1) {
                $beneficiario = (int) array_key_first($bens);
                $porNombre = array_diff_key($bens[$beneficiario], $rech);
            }
            $porImporte = [];
            $imp = $r['importe'] !== null ? Dec::key(Dec::quantizeHalfEven((string) $r['importe'], 2)) : null;
            if ($imp !== null && ($importes[$imp] ?? 0) === 1 && count($retenciones[$imp] ?? []) === 1) {
                $porImporte = array_diff_key($retenciones[$imp], $rech);
            }
            $otros = ($r['cuenta_hmac'] !== null && $r['cuenta_hmac'] !== '') ? ($historia[(string) $r['cuenta_hmac']] ?? []) : [];
            $asociada = [];
            $mismoNombre = false;
            $recurrente = [];
            $periodos = [];
            foreach ($otros as $x) {
                $periodos[(int) $x['periodo_id']] = true;
                if ($x['empleado_id'] !== null && in_array($x['asociacion_estado'], ['ASOCIADO_AUTOMATICO', 'ASOCIADO_MANUAL'], true)) {
                    $asociada[(int) $x['empleado_id']] = true;
                }
                if (($r['nombre_normalizado'] ?? '') !== '' && $x['nombre_normalizado'] === $r['nombre_normalizado']) {
                    $mismoNombre = true;
                }
                $evx = is_array($x['ev']) ? $x['ev'] : [];
                $candsX = [];
                foreach ((array) ($evx['candidatos'] ?? []) as $c) {
                    if (preg_match('/^\d+$/D', (string) $c) === 1) {
                        $candsX[$cn((int) $c)] = true;
                    }
                }
                if ($imp !== null && $x['importe'] !== null && Dec::key(Dec::quantizeHalfEven((string) $x['importe'], 2)) === $imp
                    && in_array('IMPORTE_RETENCION_UNICA', (array) ($evx['senales'] ?? []), true) && count($candsX) === 1) {
                    $recurrente += $candsX;
                }
            }
            if ($otros !== []) {
                $detalle['clabe_otros_periodos'] = count($periodos);
            }
            $porClabe = count($asociada) === 1 ? array_diff_key($asociada, $rech) : [];
            if (count($asociada) > 1) {
                $senales[] = 'CLABE_CON_TRABAJADORES_DISTINTOS';
            }
            foreach ([['BENEFICIARIO_CONOCIDO', $porNombre], ['IMPORTE_RETENCION_UNICA', $porImporte], ['CLABE_ASOCIADA_OTRO_PERIODO', $porClabe]] as [$n, $ok]) {
                if ($ok !== []) {
                    $senales[] = $n;
                }
            }
            if ($mismoNombre && $otros !== []) {
                $senales[] = 'MISMO_NOMBRE_CON_ESA_CLABE';
            }
            $recOk = $recurrente !== [] && count($recurrente) === 1 && $porImporte !== [] && self::sameSet($recurrente, $porImporte);
            if ($recOk) {
                $senales[] = 'IMPORTE_RECURRENTE_MISMA_CLABE';
            }
            $candidatos = array_values(array_filter([$porNombre, $porImporte, $porClabe], static fn ($c) => $c !== []));
            $union = [];
            foreach ($candidatos as $c) {
                $union += $c;
            }
            $comun = $candidatos === [] ? [] : array_intersect_key(...$candidatos);
            if (count($asociada) > 1 || (count($candidatos) > 1 && $comun === [])) {
                [$cands, $motivo, $conf] = [$union + array_diff_key($asociada, $rech), 'SENALES_CONTRADICTORIAS', 'BAJA'];
            } elseif (count($candidatos) > 1 && count($comun) === 1 && $porNombre !== [] && $porImporte !== [] && $porClabe === []) {
                [$cands, $motivo, $conf] = [$comun, 'BENEFICIARIO_E_IMPORTE', 'MEDIA'];
            } elseif ($porClabe !== [] && $mismoNombre) {
                [$cands, $motivo, $conf] = [$porClabe, 'CLABE_Y_NOMBRE_HISTORICOS', 'MEDIA'];
            } elseif ($recOk) {
                [$cands, $motivo, $conf] = [$porImporte, 'IMPORTE_RECURRENTE_MISMA_CLABE', 'MEDIA'];
            } elseif ($porNombre !== [] && $porImporte !== [] && self::sameSet($porNombre, $porImporte)) {
                [$cands, $motivo, $conf] = [$porNombre, 'BENEFICIARIO_E_IMPORTE', 'MEDIA'];
            } elseif ($porNombre !== []) {
                [$cands, $motivo, $conf] = [$porNombre, 'BENEFICIARIO_CONOCIDO', 'BAJA'];
            } elseif ($porClabe !== []) {
                [$cands, $motivo, $conf] = [$porClabe, 'CLABE_ASOCIADA_OTRO_PERIODO', 'BAJA'];
            } elseif ($porImporte !== []) {
                [$cands, $motivo, $conf] = [$porImporte, 'IMPORTE_RETENCION_UNICA', 'BAJA'];
            } else {
                [$cands, $motivo, $conf] = [[], 'PENSION_SIN_TRABAJADOR', 'NINGUNA'];
            }
            $estado = $cands === [] ? 'NO_IDENTIFICADO' : (count($cands) === 1 ? 'SUGERENCIA' : 'AMBIGUO');
            $candList = array_map('intval', array_keys($cands));
            sort($candList);
            $ev = ['motivo' => $motivo, 'candidatos' => $candList, 'fuertes' => [], 'senales' => $senales, 'categoria' => self::CATEGORIA[$conf]];
            if ($detalle !== []) {
                $ev['detalle_senales'] = $detalle;
            }
            if ($beneficiario !== null && $porNombre !== []) {
                $ev['beneficiario_candidato'] = $beneficiario;
            }
            $prev = Json::decode($r['asociacion_evidencia']) ?? [];
            if ($estado === $r['asociacion_estado'] && ($prev['candidatos'] ?? []) == $ev['candidatos'] && ($prev['motivo'] ?? null) === $motivo
                && ($prev['beneficiario_candidato'] ?? null) == ($ev['beneficiario_candidato'] ?? null) && $conf === $r['asociacion_confianza']
                && ($prev['senales'] ?? null) == $senales && ($prev['categoria'] ?? null) === $ev['categoria']) {
                continue;
            }
            $upd = $db->fetchOne(
                'UPDATE registros_tesoreria SET asociacion_estado = :e, asociacion_metodo = NULL, asociacion_confianza = :c,
                        asociacion_evidencia = CAST(:ev AS jsonb), asociacion_version = asociacion_version + 1
                  WHERE id = :id AND asociacion_version = :v AND empleado_id IS NULL AND asociacion_estado = ANY(CAST(:ed AS text[])) RETURNING id',
                ['e' => $estado, 'c' => $conf, 'ev' => Json::encode($ev), 'id' => (int) $r['id'], 'v' => (int) $r['asociacion_version'],
                 'ed' => BatchInsert::arrayLiteral(self::EDITABLES)]);
            if ($upd === null) {
                continue;
            }
            if ($estado !== $r['asociacion_estado']) {
                $hist[] = ['TESORERIA', (int) $r['id'], 'REASOCIACION', $r['asociacion_estado'], $estado, null, null, null, $conf, null, $procesoId,
                           mb_substr('Sugerencia de pensión: ' . $motivo, 0, 1000)];
            }
            $key = $r['asociacion_estado'] . '->' . $estado;
            $out[$key] = ($out[$key] ?? 0) + 1;
        }
        History::insert($db, $hist);
        return $out;
    }

    /** @return list<int> */
    public static function intArray(mixed $pgArray): array
    {
        if ($pgArray === null || $pgArray === '' || $pgArray === '{}') {
            return [];
        }
        if (is_array($pgArray)) {
            return array_map('intval', $pgArray);
        }
        return array_map('intval', array_filter(explode(',', trim((string) $pgArray, '{}')), static fn ($x) => $x !== '' && $x !== 'NULL'));
    }

    /** @param array<int,true> $a @param array<int,true> $b */
    private static function sameSet(array $a, array $b): bool
    {
        return count($a) === count($b) && array_diff_key($a, $b) === [];
    }
}
