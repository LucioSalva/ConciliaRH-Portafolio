<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Normalization;

use ConciliaRH\Core\Database;
use ConciliaRH\Engine\Support\Json;
use ConciliaRH\Engine\Support\Py;
use stdClass;

final class ImportMetrics
{
    /** @param array<string,mixed> $extra @return array<string,mixed> */
    public static function compute(Database $db, int $importacionId, string $tipo, array $extra): array
    {
        $out = [];
        foreach ($extra as $k => $v) {
            if ($v === null || is_int($v) || is_string($v) || is_float($v) || is_bool($v) || $v instanceof stdClass || (is_array($v) && !array_is_list($v))) {
                $out[$k] = $v;
            }
        }
        if (!in_array($tipo, ['RRHH', 'TESORERIA'], true)) {
            return $out;
        }
        $table = $tipo === 'RRHH' ? 'registros_rrhh' : 'registros_tesoreria';
        $porEstado = [];
        foreach ($db->fetchAll("SELECT asociacion_estado AS k, count(*) AS n FROM $table WHERE importacion_id = :i GROUP BY 1", ['i' => $importacionId]) as $r) {
            $porEstado[(string) $r['k']] = (int) $r['n'];
        }
        $porMetodo = [];
        foreach ($db->fetchAll("SELECT coalesce(asociacion_metodo, 'NINGUNO') AS k, count(*) AS n FROM $table WHERE importacion_id = :i GROUP BY 1",
            ['i' => $importacionId]) as $r) {
            $porMetodo[(string) $r['k']] = (int) $r['n'];
        }
        $curp = $tipo === 'RRHH' ? 'count(*) FILTER (WHERE curp_original IS NOT NULL AND NOT curp_valida)' : '0';
        $q = $db->fetchOne(
            "SELECT count(*) AS total, count(*) FILTER (WHERE numero_original IS NOT NULL AND NOT numero_valido) AS numero_invalido,
                    count(*) FILTER (WHERE rfc_original IS NOT NULL AND NOT rfc_valido) AS rfc_invalido, $curp AS curp_invalido,
                    count(DISTINCT empleado_id) AS empleados
               FROM $table WHERE importacion_id = :i", ['i' => $importacionId]);
        $total = (int) ($q['total'] ?? 0);
        $asociados = ($porEstado['ASOCIADO_AUTOMATICO'] ?? 0) + ($porEstado['ASOCIADO_MANUAL'] ?? 0);
        return [...$out, 'por_estado' => Json::obj($porEstado), 'por_metodo' => Json::obj($porMetodo), 'total' => $total,
                'numero_invalido' => (int) $q['numero_invalido'], 'rfc_invalido' => (int) $q['rfc_invalido'], 'curp_invalida' => (int) $q['curp_invalido'],
                'empleados_distintos' => (int) $q['empleados'],
                'pct_asociacion_automatica' => $total ? Py::round(100 * ($porEstado['ASOCIADO_AUTOMATICO'] ?? 0) / $total, 2) : null,
                'pct_asociados' => $total ? Py::round(100 * $asociados / $total, 2) : null];
    }
}
