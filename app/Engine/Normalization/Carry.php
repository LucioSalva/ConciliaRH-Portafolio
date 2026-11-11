<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Normalization;

use ConciliaRH\Core\Database;
use ConciliaRH\Engine\Support\Dec;
use ConciliaRH\Engine\Support\Py;

final class Carry
{
    public static function positionKey(mixed $hoja, mixed $fila, mixed $numero, mixed $nombre, mixed $importe = null, mixed $ultimos4 = null): string
    {
        $imp = $importe !== null ? Dec::quantizeHalfEven((string) $importe, 2) : '';
        $t = static fn (mixed $v): string => ($v === null || $v === '' || $v === false) ? '' : Py::str($v);
        return 'pos:' . Py::str($hoja) . ':' . Py::str($fila) . ':' . $t($numero) . ':' . $t($nombre) . ':' . $imp . ':' . $t($ultimos4);
    }

    /** @return array{0:array<string,list<array<string,mixed>>>,1:bool} */
    public static function load(Database $db, ?int $prevImport, string $tipo, ?int $analisisId): array
    {
        if ($prevImport === null) {
            return [[], false];
        }
        $table = $tipo === 'RRHH' ? 'registros_rrhh' : 'registros_tesoreria';
        $keys = $db->fetchOne(
            'SELECT (SELECT a.hash_key_id FROM importaciones_normalizadas i JOIN analisis_archivo a ON a.id = i.analisis_id WHERE i.id = :p) AS prev,
                    (SELECT hash_key_id FROM analisis_archivo WHERE id = :a) AS cur', ['p' => $prevImport, 'a' => $analisisId]);
        $byPos = $analisisId !== null && $keys['prev'] !== null && $keys['cur'] !== null && $keys['prev'] !== $keys['cur'];
        $extra = $table === 'registros_tesoreria' ? 'importe, cuenta_ultimos4' : 'NULL AS importe, NULL AS cuenta_ultimos4';
        $out = [];
        foreach ($db->fetchAll(
            "SELECT hash_registro, hoja_indice, fila_origen, numero_empleado, nombre_normalizado, empleado_id, asociacion_estado, asociado_por,
                    asociado_at, rechazados, $extra
               FROM $table
              WHERE importacion_id = :i AND (asociacion_estado IN ('ASOCIADO_MANUAL', 'NO_IDENTIFICADO_CONFIRMADO') OR cardinality(rechazados) > 0)
              ORDER BY hoja_indice, fila_origen, id", ['i' => $prevImport]) as $r) {
            if ($byPos && ($r['numero_empleado'] ?? '') === '' && ($r['nombre_normalizado'] ?? '') === '') {
                continue;
            }
            $key = $byPos ? self::positionKey((int) $r['hoja_indice'], (int) $r['fila_origen'], $r['numero_empleado'], $r['nombre_normalizado'],
                $r['importe'], $r['cuenta_ultimos4']) : (string) $r['hash_registro'];
            $out[$key][] = $r;
        }
        return [$out, $byPos];
    }
}
