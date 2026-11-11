<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Normalization;

use ConciliaRH\Core\Database;
use ConciliaRH\Engine\Support\BatchInsert;

final class AccountRegistry
{
    /**
     * @param array<string,mixed> $row cuenta_hmac, cuenta_cifrada (binario), cuenta_key_id, cuenta_ultimos4, nombre_original, tipo_cuenta, banco_cuenta
     * @return array{0:string,1:int}
     */
    public static function register(Database $db, int $rid, int $emp, string $uso, int $periodoId, string $fechaFin, array $row): array
    {
        $owner = $db->fetchOne("SELECT id, empleado_id FROM cuentas_bancarias WHERE cuenta_hmac = :h AND uso = :u AND estado = 'ACTIVA'",
            ['h' => $row['cuenta_hmac'], 'u' => $uso]);
        if ($owner === null) {
            if ($row['cuenta_cifrada'] === null) {
                return ['SIN_CIFRADO', 0];
            }
            $had = (int) $db->fetchValue("SELECT count(*) FROM cuentas_bancarias WHERE empleado_id = :e AND uso = :u AND estado = 'ACTIVA'",
                ['e' => $emp, 'u' => $uso]);
            $ins = $db->fetchOne(
                "INSERT INTO cuentas_bancarias (empleado_id, uso, beneficiario_nombre, tipo, cuenta_hmac, cuenta_ultimos4, cuenta_cifrada, key_id, banco,
                        origen, origen_registro_id, primer_periodo_id, ultimo_periodo_id)
                 VALUES (:e, :u, :bn, :t, :h, :l4, decode(:cc, 'hex'), :k, :b, 'EVIDENCIA_FUERTE', :r, :p1, :p2)
                 ON CONFLICT (cuenta_hmac, uso) WHERE estado = 'ACTIVA' DO NOTHING RETURNING id",
                ['e' => $emp, 'u' => $uso, 'bn' => $uso === 'PENSION_BENEFICIARIO' ? $row['nombre_original'] : null, 't' => $row['tipo_cuenta'] ?? 'CUENTA',
                 'h' => $row['cuenta_hmac'], 'l4' => $row['cuenta_ultimos4'], 'cc' => bin2hex((string) $row['cuenta_cifrada']), 'k' => $row['cuenta_key_id'],
                 'b' => $row['banco_cuenta'], 'r' => $rid, 'p1' => $periodoId, 'p2' => $periodoId]);
            if ($ins !== null) {
                return ['NUEVA', $had];
            }
            $owner = $db->fetchOne("SELECT id, empleado_id FROM cuentas_bancarias WHERE cuenta_hmac = :h AND uso = :u AND estado = 'ACTIVA'",
                ['h' => $row['cuenta_hmac'], 'u' => $uso]);
        }
        if ((int) $owner['empleado_id'] !== $emp) {
            $canon = [];
            foreach ($db->fetchAll('SELECT id, coalesce(fusionado_en_id, id) AS c FROM empleados WHERE id = ANY(CAST(:ids AS bigint[]))',
                ['ids' => BatchInsert::arrayLiteral([(int) $owner['empleado_id'], $emp])]) as $x) {
                $canon[(int) $x['id']] = (int) $x['c'];
            }
            $o = (int) $owner['empleado_id'];
            if (($canon[$o] ?? $o) !== ($canon[$emp] ?? $emp)) {
                return ['CONFLICTO', 0];
            }
        }
        $db->execute(
            "UPDATE cuentas_bancarias c SET ultima_vista_at = now(),
                    veces_vista = greatest(1, (SELECT count(DISTINCT r.archivo_id) FROM registros_tesoreria r
                                    JOIN importaciones_normalizadas n ON n.id = r.importacion_id AND n.estado = 'VIGENTE'
                                   WHERE r.cuenta_hmac = c.cuenta_hmac)),
                    ultimo_periodo_id = CASE WHEN (SELECT fecha_fin FROM periodos_nomina WHERE id = c.ultimo_periodo_id) <= CAST(:f AS date)
                                             THEN :p ELSE c.ultimo_periodo_id END
              WHERE c.id = :id", ['f' => $fechaFin, 'p' => $periodoId, 'id' => (int) $owner['id']]);
        return ['VISTA', 0];
    }

    public static function toConflict(Database $db, int $rid, int $emp, string $note): void
    {
        $db->execute(
            "UPDATE registros_tesoreria SET empleado_id = NULL, asociacion_estado = 'CONFLICTO', asociacion_metodo = NULL,
                    asociacion_confianza = 'NINGUNA', asociacion_version = asociacion_version + 1,
                    asociacion_evidencia = asociacion_evidencia || jsonb_build_object('motivo', CAST(:n AS text), 'candidatos', jsonb_build_array(CAST(:e AS bigint)))
              WHERE id = :id", ['n' => $note, 'e' => $emp, 'id' => $rid]);
    }
}
