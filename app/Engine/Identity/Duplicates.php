<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Identity;

use ConciliaRH\Core\Database;
use ConciliaRH\Engine\Support\Json;
use ConciliaRH\Engine\Support\Py;
use ConciliaRH\Engine\Support\SequenceMatcher;

final class Duplicates
{
    private const SIMILITUD_MIN = 0.92;
    private const RANGO = ['BAJA' => 1, 'MEDIA' => 2, 'ALTA' => 3];
    private const MAX_GRUPO_NOMBRE = 20;

    /**
     * @param array<string,array<int,true>> $groups
     * @param array<int,true>|null $focus
     * @return array<string,array{int,int}>
     */
    private static function pairsFromGroups(array $groups, ?array $focus, ?int $maxGroup = null): array
    {
        $out = [];
        foreach ($groups as $ids) {
            $n = count($ids);
            if ($n < 2 || ($maxGroup !== null && $n > $maxGroup)) {
                continue;
            }
            $ord = array_keys($ids);
            sort($ord);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    [$a, $b] = [$ord[$i], $ord[$j]];
                    if ($focus === null || isset($focus[$a]) || isset($focus[$b])) {
                        $out[$a . ':' . $b] = [$a, $b];
                    }
                }
            }
        }
        return $out;
    }

    /** @param array<int,true>|null $focus @return array<string,array{int,int}> */
    private static function evidencePairs(Database $db, ?array $focus): array
    {
        $cuentas = [];
        foreach ($db->fetchAll('SELECT DISTINCT cuenta_hmac, uso, empleado_id FROM cuentas_bancarias') as $r) {
            $cuentas[$r['cuenta_hmac'] . '|' . $r['uso']][(int) $r['empleado_id']] = true;
        }
        $pagos = [];
        foreach ($db->fetchAll("SELECT DISTINCT cuenta_hmac, empleado_id FROM registros_tesoreria
                                 WHERE categoria = 'NOMINA' AND cuenta_hmac IS NOT NULL AND empleado_id IS NOT NULL
                                   AND asociacion_estado IN ('ASOCIADO_AUTOMATICO', 'ASOCIADO_MANUAL')") as $r) {
            $pagos[(string) $r['cuenta_hmac']][(int) $r['empleado_id']] = true;
        }
        $inc = [];
        foreach ($db->fetchAll("SELECT id, detalle->'empleados' AS e FROM incidencias_identidad
                                 WHERE jsonb_typeof(detalle->'empleados') = 'array' AND jsonb_array_length(detalle->'empleados') BETWEEN 2 AND 10") as $r) {
            $set = [];
            foreach ((array) (Json::decode($r['e']) ?? []) as $x) {
                if (preg_match('/^\d+$/D', (string) $x) === 1) {
                    $set[(int) $x] = true;
                }
            }
            $inc['i' . $r['id']] = $set;
        }
        return self::pairsFromGroups($cuentas, $focus) + self::pairsFromGroups($pagos, $focus, 50) + self::pairsFromGroups($inc, $focus);
    }

    /** @param array<int,true>|null $focus @return array<string,array{int,int}> */
    private static function identityPairs(Database $db, ?array $focus): array
    {
        $groups = [];
        foreach ($db->fetchAll('SELECT id, rfc, rfc_valido, curp, curp_valida, issemym, nombre_normalizado FROM empleados WHERE fusionado_en_id IS NULL ORDER BY id') as $e) {
            $id = (int) $e['id'];
            if ($e['rfc'] !== null && $e['rfc'] !== '' && $e['rfc_valido']) {
                $groups['rfc|' . $e['rfc']][] = $id;
            }
            if ($e['curp'] !== null && $e['curp'] !== '' && $e['curp_valida']) {
                $groups['curp|' . $e['curp']][] = $id;
            }
            $iss = Py::upper(Py::strip((string) ($e['issemym'] ?? '')));
            if (mb_strlen($iss) >= 4) {
                $groups['issemym|' . $iss][] = $id;
            }
            if ($e['nombre_normalizado'] !== null && $e['nombre_normalizado'] !== '') {
                $groups['nombre|' . $e['nombre_normalizado']][] = $id;
            }
        }
        $out = [];
        foreach ($groups as $k => $ids) {
            $n = count($ids);
            if ($n < 2 || (str_starts_with((string) $k, 'nombre|') && $n > self::MAX_GRUPO_NOMBRE)) {
                continue;
            }
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    [$a, $b] = [$ids[$i], $ids[$j]];
                    if ($focus === null || isset($focus[$a]) || isset($focus[$b])) {
                        $out[$a . ':' . $b] = [$a, $b];
                    }
                }
            }
        }
        return $out;
    }

    /** @param array<int,true>|null $focus @return array<string,array{int,int}> */
    private static function similarPairs(Database $db, ?array $focus): array
    {
        $buckets = [];
        foreach ($db->fetchAll('SELECT id, nombre_normalizado FROM empleados WHERE fusionado_en_id IS NULL ORDER BY id') as $e) {
            $t = explode(' ', (string) ($e['nombre_normalizado'] ?? ''));
            if (count($t) >= 2) {
                $buckets[$t[0] . '|' . $t[count($t) - 1]][] = [(int) $e['id'], (string) $e['nombre_normalizado']];
            }
        }
        $out = [];
        foreach ($buckets as $g) {
            $n = count($g);
            if ($n > 200) {
                continue;
            }
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    [$a, $na] = $g[$i];
                    [$b, $nb] = $g[$j];
                    if ($focus !== null && !isset($focus[$a]) && !isset($focus[$b])) {
                        continue;
                    }
                    if ($na !== $nb && SequenceMatcher::ratioOf($na, $nb) >= self::SIMILITUD_MIN) {
                        $out[$a . ':' . $b] = [$a, $b];
                    }
                }
            }
        }
        return $out;
    }

    /** @param list<int>|null $employees @return array<string,int> */
    public static function detect(Database $db, ?int $procesoId = null, ?array $employees = null): array
    {
        $out = [];
        $bump = static function (string $k) use (&$out): void {
            $out[$k] = ($out[$k] ?? 0) + 1;
        };
        $focus = $employees !== null ? array_fill_keys($employees, true) : null;
        if ($focus !== null && $focus === []) {
            return ['pares' => 0];
        }
        $canon = [];
        foreach ($db->fetchAll('SELECT id, fusionado_en_id FROM empleados') as $e) {
            $canon[(int) $e['id']] = $e['fusionado_en_id'] !== null ? (int) $e['fusionado_en_id'] : null;
        }
        $all = self::evidencePairs($db, $focus) + self::identityPairs($db, $focus);
        $pairs = [];
        foreach (self::sortPairs($all) as [$a, $b]) {
            if ($a === $b || !array_key_exists($a, $canon) || !array_key_exists($b, $canon) || $canon[$a] !== null || $canon[$b] !== null) {
                continue;
            }
            [$x, $y] = [min($a, $b), max($a, $b)];
            $pairs[$x . ':' . $y] = [$x, $y];
        }
        $similar = array_diff_key(self::similarPairs($db, $focus), $pairs);
        $seen = [];
        foreach (self::sortPairs($pairs + $similar) as [$a, $b]) {
            $an = Json::decode((string) $db->fetchValue('SELECT fn_fusion_analisis(:a, :b)', ['a' => $a, 'b' => $b])) ?? [];
            $evid = array_values((array) ($an['evidencias'] ?? []));
            $conf = (string) ($an['confianza'] ?? 'NINGUNA');
            if (isset($similar[$a . ':' . $b])) {
                $evid[] = ['codigo' => 'NOMBRE_SIMILAR', 'fuerza' => 'DEBIL'];
                $conf = in_array($conf, ['NINGUNA', 'BAJA'], true) ? 'BAJA' : $conf;
            }
            if (!isset(self::RANGO[$conf])) {
                continue;
            }
            $seen[$a . ':' . $b] = true;
            $prev = $db->fetchOne('SELECT id, estado, confianza, confianza_al_decidir FROM duplicados_empleados WHERE empleado_a = :a AND empleado_b = :b',
                ['a' => $a, 'b' => $b]);
            $conflictos = Json::encode($an['conflictos'] ?? []);
            if ($prev === null) {
                $db->execute('INSERT INTO duplicados_empleados (empleado_a, empleado_b, confianza, evidencias, conflictos, proceso_id)
                              VALUES (:a, :b, :c, CAST(:e AS jsonb), CAST(:k AS jsonb), :p)',
                    ['a' => $a, 'b' => $b, 'c' => $conf, 'e' => Json::encode($evid), 'k' => $conflictos, 'p' => $procesoId]);
                $bump('nuevo_' . $conf);
                continue;
            }
            $estado = (string) $prev['estado'];
            if ($estado === 'OBSOLETO') {
                $estado = 'PENDIENTE';
            } elseif ($estado === 'DESCARTADO' && self::RANGO[$conf] > (self::RANGO[$prev['confianza_al_decidir'] ?? 'BAJA'] ?? 1)) {
                $estado = 'PENDIENTE';
                $bump('reabierto');
            }
            $db->execute('UPDATE duplicados_empleados SET confianza = :c, evidencias = CAST(:e AS jsonb), conflictos = CAST(:k AS jsonb), estado = :s,
                                 proceso_id = :p WHERE id = :id',
                ['c' => $conf, 'e' => Json::encode($evid), 'k' => $conflictos, 's' => $estado, 'p' => $procesoId, 'id' => (int) $prev['id']]);
        }
        foreach ($db->fetchAll("SELECT id, empleado_a, empleado_b FROM duplicados_empleados WHERE estado = 'PENDIENTE'") as $d) {
            [$a, $b] = [(int) $d['empleado_a'], (int) $d['empleado_b']];
            if ($focus !== null && !isset($focus[$a]) && !isset($focus[$b])) {
                continue;
            }
            if (!isset($seen[$a . ':' . $b])) {
                $db->execute("UPDATE duplicados_empleados SET estado = 'OBSOLETO', proceso_id = :p WHERE id = :id", ['p' => $procesoId, 'id' => (int) $d['id']]);
                $bump('obsoleto');
            }
        }
        $out['pares'] = count($seen);
        return $out;
    }

    /** @param array<string,array{int,int}> $pairs @return list<array{int,int}> */
    private static function sortPairs(array $pairs): array
    {
        $list = array_values($pairs);
        usort($list, static fn ($x, $y) => $x <=> $y);
        return $list;
    }
}
