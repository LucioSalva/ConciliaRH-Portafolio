<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Normalization;

use ConciliaRH\Core\Database;
use ConciliaRH\Engine\Identity\Matcher;
use ConciliaRH\Engine\Identity\Person;
use ConciliaRH\Engine\Identity\PersonIndex;

final class IndexLoader
{
    public static function fuzzyMin(Database $db): float
    {
        $v = $db->fetchValue("SELECT valor FROM configuracion WHERE clave = 'identidad.similitud_minima'");
        $f = is_string($v) && is_numeric(trim($v)) ? (float) $v : Matcher::FUZZY_MIN;
        return min(0.99, max(0.70, $f));
    }

    /** @return array{0:PersonIndex,1:array<int,array<string,mixed>>} */
    public static function load(Database $db): array
    {
        $idx = new PersonIndex(self::fuzzyMin($db));
        $emps = [];
        foreach ($db->fetchAll(
            'SELECT id, numero_empleado, nombre_completo, nombre_normalizado, rfc, rfc_valido, curp, curp_valida, issemym, grupo, area, departamento,
                    puesto, cargo, fecha_ingreso::text AS fecha_ingreso, fecha_baja::text AS fecha_baja, activo, ultimo_periodo_fecha::text AS ultimo_periodo_fecha,
                    fusionado_en_id
               FROM empleados ORDER BY id') as $e) {
            $id = (int) $e['id'];
            $emps[$id] = $e;
            $idx->add(new Person($id, (string) $e['numero_empleado'], $e['rfc'], (bool) $e['rfc_valido'], $e['curp'], (bool) $e['curp_valida'],
                $e['nombre_normalizado']));
        }
        foreach ($emps as $id => $e) {
            if ($e['fusionado_en_id'] !== null) {
                $idx->setAlias($id, (int) $e['fusionado_en_id']);
            }
        }
        foreach ($db->fetchAll(
            "SELECT c.cuenta_hmac, c.uso, c.empleado_id FROM cuentas_bancarias c
              WHERE c.estado = 'ACTIVA'
                AND (c.origen = 'CONFIRMACION_MANUAL' OR EXISTS (
                      SELECT 1 FROM registros_tesoreria r
                        JOIN importaciones_normalizadas n ON n.id = r.importacion_id AND n.estado = 'VIGENTE'
                       WHERE r.cuenta_hmac = c.cuenta_hmac
                         AND (r.empleado_id = c.empleado_id
                              OR (SELECT coalesce(fusionado_en_id, id) FROM empleados WHERE id = r.empleado_id)
                               = (SELECT coalesce(fusionado_en_id, id) FROM empleados WHERE id = c.empleado_id))
                         AND (r.categoria = 'PENSION') = (c.uso = 'PENSION_BENEFICIARIO')
                         AND r.asociacion_estado IN ('ASOCIADO_AUTOMATICO', 'ASOCIADO_MANUAL')
                         AND r.asociacion_metodo IS DISTINCT FROM 'CUENTA_CONFIRMADA'))") as $a) {
            $idx->accounts[PersonIndex::accountKey((string) $a['cuenta_hmac'], (string) $a['uso'])] = (int) $a['empleado_id'];
        }
        return [$idx, $emps];
    }
}
