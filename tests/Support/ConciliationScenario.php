<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Support;

use ConciliaRH\Core\Database;

trait ConciliationScenario
{
    use IdentityScenario;

    /** @return array<string,mixed> identityScenario() + conc, inc_menor, inc_pend */
    protected function conciliationScenario(): array
    {
        $s = $this->identityScenario();
        $db = $this->committedOwner(static function (Database $db): void {
            $db->execute("SET conciliarh.identidad_mantenimiento = 'on'");
            $db->execute('TRUNCATE conciliaciones, conciliacion_archivos, conciliacion_controles, conciliacion_empleados, conciliacion_pensiones,
                          conciliacion_fuentes, conciliacion_incidencias, conciliacion_incidencias_historial, relaciones_poliza_dispersion RESTART IDENTITY CASCADE');
            $db->execute("DELETE FROM procesos WHERE tipo = 'CONCILIAR_LOTE'");
            $db->execute('RESET conciliarh.identidad_mantenimiento');
        });
        $u = $this->scn['u'];
        $c = (int) $db->fetchValue(
            "INSERT INTO conciliaciones (periodo_id, lote_id, numero, estado, tolerancia, lote_oficial, solicitado_por)
             VALUES (:p, :l, 1, 'PENDIENTE', 0.01, false, :u) RETURNING id", ['p' => $s['periodo'], 'l' => $s['lote'], 'u' => $u]);
        $db->execute("UPDATE conciliaciones SET estado = 'EJECUTANDO', motor_version = 1, iniciada_at = now() WHERE id = :id", ['id' => $c]);
        foreach ([[$s['emp1'], '90001', $s['rrhh1'], '8000.00', '-500.00', 'PAGO_MENOR'], [$s['emp2'], '90002', $s['rrhh2'], '8500.00', '0.00', 'CORRECTO']] as [$e, $n, $r, $pag, $dif, $st]) {
            $db->execute(
                "INSERT INTO conciliacion_empleados (conciliacion_id, empleado_id, grupo, numero_empleado, area, registro_rrhh_id, percepciones, deducciones,
                    neto_esperado, pagado_santander, pagado_total, diferencia, pagos, estado, detalle)
                 VALUES (:c, :e, 'POLICIA', :n, 'AREA FICTICIA', :r, 10000, 1500, 8500, :p, :p2, :d, 1, :st, '{\"regla\": \"prueba\"}')",
                ['c' => $c, 'e' => $e, 'n' => $n, 'r' => $r, 'p' => $pag, 'p2' => $pag, 'd' => $dif, 'st' => $st]);
            $db->execute(
                "INSERT INTO conciliacion_fuentes (conciliacion_id, empleado_id, tipo, registro_id, archivo_id, hoja_nombre, fila_origen, importe)
                 VALUES (:c, :e, 'RRHH', :r, :a, 'Tabla', 4, 8500)", ['c' => $c, 'e' => $e, 'r' => $r, 'a' => $s['archivo_r']]);
        }
        $db->execute("INSERT INTO conciliacion_fuentes (conciliacion_id, empleado_id, tipo, registro_id, archivo_id, importe)
                      VALUES (:c, NULL, 'PENDIENTE_NOMINA', :r, :a, 700)", ['c' => $c, 'r' => $s['sug'], 'a' => $s['archivo_t']]);
        $s['inc_menor'] = (int) $db->fetchValue(
            "INSERT INTO conciliacion_incidencias (conciliacion_id, clave, tipo, severidad, nivel, empleado_id, grupo, importe, mensaje)
             VALUES (:c, 'EMP:1', 'PAGO_MENOR', 'ERROR', 4, :e, 'POLICIA', -500, 'Lo dispersado es menor que el neto autorizado por RRHH.') RETURNING id",
            ['c' => $c, 'e' => $s['emp1']]);
        $s['inc_pend'] = (int) $db->fetchValue(
            "INSERT INTO conciliacion_incidencias (conciliacion_id, clave, tipo, severidad, nivel, importe, mensaje)
             VALUES (:c, 'PENDIENTE_IDENTIDAD:NOMINA', 'IMPORTE_PENDIENTE_IDENTIDAD', 'ADVERTENCIA', 4, 1234.56, 'Hay importes dispersados sin persona identificada.') RETURNING id",
            ['c' => $c]);
        $bloque = ['rrhh' => ['percepciones' => '20000.00', 'deducciones' => '3000.00', 'neto' => '17000.00', 'registros' => 2, 'pension_retenida' => '0.00'],
                   'tesoreria_nomina' => ['total' => '17734.56', 'identificado' => '16500.00', 'pendiente_identidad' => '1234.56',
                                          'por_banco' => ['SANTANDER' => '16500.00', 'BBVA' => '0.00', 'INTERBANCARIA' => '1234.56', 'OTROS' => '0.00'], 'registros' => 3],
                   'pensiones' => ['retenido' => '0.00', 'dispersado' => '0.00', 'identificado' => '0.00', 'pendiente_identidad' => '0.00', 'por_banco' => []],
                   'diferencia_global_nomina' => '734.56', 'empleados' => ['CORRECTO' => 1, 'PAGO_MENOR' => 1], 'pensiones_estado' => []];
        $db->execute(
            "UPDATE conciliaciones SET estado = 'COMPLETADA_CON_INCIDENCIAS', huella_entradas = :h, totales = CAST(:t AS jsonb), resumen = CAST(:r AS jsonb),
                    metricas = '{\"total_ms\": 5}', terminada_at = now() - interval '1 hour' WHERE id = :id",
            ['h' => str_repeat('a', 64), 't' => json_encode(['TOTAL' => $bloque, 'POLICIA' => $bloque]),
             'r' => json_encode(['empleados' => 2, 'evaluables' => 2, 'correctos' => 1, 'pendientes_identidad' => 0, 'con_diferencia' => 1,
                                 'pct_conciliado' => '50.00', 'pct_pendiente_identidad' => '0.00', 'pct_monto_conciliado' => '50.00']), 'id' => $c]);
        return $s + ['conc' => $c];
    }
}
