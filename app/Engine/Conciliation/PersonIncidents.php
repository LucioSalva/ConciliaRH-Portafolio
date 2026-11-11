<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Conciliation;

final class PersonIncidents
{
    private const SEVERIDAD_EMPLEADO = ['PAGO_MENOR' => 'ERROR', 'PAGO_MAYOR' => 'ERROR', 'NO_PAGADO' => 'ERROR', 'PAGO_SIN_RESPALDO_RRHH' => 'ERROR',
                                        'POSIBLE_PAGO_DUPLICADO' => 'ERROR', 'ERROR_DATOS_RRHH' => 'ERROR', 'PENDIENTE_IDENTIDAD' => 'ADVERTENCIA',
                                        'CONFLICTO_IDENTIDAD' => 'ADVERTENCIA'];
    private const MENSAJE_EMPLEADO = [
        'PAGO_MENOR' => 'Lo dispersado es menor que el neto autorizado por RH.',
        'PAGO_MAYOR' => 'Lo dispersado es mayor que el neto autorizado por RH.',
        'NO_PAGADO' => 'RH autorizó un neto y no hay pagos identificados para la persona.',
        'PAGO_SIN_RESPALDO_RRHH' => 'Hay pagos identificados sin neto autorizado por RH en esta quincena.',
        'POSIBLE_PAGO_DUPLICADO' => 'Dos pagos con el mismo importe y la misma cuenta o referencia; sin uno de ellos el pago sería correcto.',
        'ERROR_DATOS_RRHH' => 'El registro de RH tiene importes inválidos, está duplicado o su neto es negativo.',
        'PENDIENTE_IDENTIDAD' => 'Un pago pendiente de confirmar identidad completaría el neto: confirme la asociación.',
        'CONFLICTO_IDENTIDAD' => 'Un pago en conflicto de identidad podría corresponder a la persona: resuelva el conflicto.',
    ];
    private const SEVERIDAD_PENSION = ['NO_DISPERSADA' => 'ERROR', 'PENSION_MENOR' => 'ERROR', 'PENSION_MAYOR' => 'ERROR', 'SIN_RETENCION_RRHH' => 'ERROR',
                                       'PENDIENTE_IDENTIDAD' => 'ADVERTENCIA'];
    private const MENSAJE_PENSION = [
        'NO_DISPERSADA' => 'RH retuvo pensión alimenticia y no hay dispersión identificada al beneficiario.',
        'PENSION_MENOR' => 'La pensión dispersada es menor que la retenida.',
        'PENSION_MAYOR' => 'La pensión dispersada es mayor que la retenida.',
        'SIN_RETENCION_RRHH' => 'Hay dispersión de pensión sin retención registrada por RH.',
        'PENDIENTE_IDENTIDAD' => 'Un pago de pensión sin trabajador identificado podría corresponder a esta retención.',
    ];

    private const MENSAJE_CUBIERTA = 'La pensión retenida a la población se dispersó completa; falta identificar al beneficiario de esta retención.';

    /**
     * @param list<EmployeeResult> $empleados
     * @param list<PensionResult> $pensiones
     */
    public static function add(State $st, array $empleados, array $pensiones): void
    {
        foreach ($empleados as $x) {
            $sev = self::SEVERIDAD_EMPLEADO[$x->estado] ?? null;
            if ($sev !== null) {
                $st->inc("N4:{$x->empleadoId}", $x->estado, $sev, 4, self::MENSAJE_EMPLEADO[$x->estado], $x->empleadoId, $x->grupo, importe: $x->diferencia(),
                    detalle: ['esperado' => $x->netoEsperado, 'pagado' => $x->pagadoTotal(), 'alerta' => $x->alerta]);
            }
        }
        foreach ($pensiones as $p) {
            $sev = self::SEVERIDAD_PENSION[$p->estado] ?? null;
            $cubierta = ($p->detalle['cubierta_por_total_poblacion'] ?? false) === true;
            if ($sev !== null) {
                $st->inc("N5:{$p->empleadoId}", str_starts_with($p->estado, 'PENSION_') ? $p->estado : 'PENSION_' . $p->estado, $cubierta ? 'INFO' : $sev, 5,
                    $cubierta ? self::MENSAJE_CUBIERTA : self::MENSAJE_PENSION[$p->estado], $p->empleadoId, $p->grupo, importe: $p->diferencia(),
                    detalle: ['retenido' => $p->retenido, 'dispersado' => $p->dispersado] + ($cubierta ? ['cubierta_por_total_poblacion' => true] : []));
            }
        }
    }
}
