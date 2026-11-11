<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Reports;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Próxima ejecución de una programación (docs/SCHEDULED_REPORTS.md §3). Función pura, en la zona horaria de la aplicación.
 *
 * - DIARIO: todos los días a la hora indicada.
 * - SEMANAL: el día ISO indicado (1 = lunes … 7 = domingo) a la hora.
 * - QUINCENAL: días 1 y 16 de cada mes a la hora (después de cada quincena de pago: 15 y fin de mes).
 * - MENSUAL: el día indicado (1–28, existe en todos los meses) a la hora.
 * - MANUAL: nunca (sólo «Generar ahora»).
 * Siempre estrictamente DESPUÉS de $from: si el scheduler estuvo detenido, no se genera un rezago de ejecuciones perdidas.
 */
final class ScheduleCalculator
{
    public const FRECUENCIAS = ['MANUAL', 'DIARIO', 'SEMANAL', 'QUINCENAL', 'MENSUAL'];

    public static function next(string $frecuencia, int $hora, ?int $diaSemana, ?int $diaMes, DateTimeImmutable $from, string $tz): ?DateTimeImmutable
    {
        $zone = new DateTimeZone($tz);
        $now = $from->setTimezone($zone);
        $at = static fn (DateTimeImmutable $d): DateTimeImmutable => $d->setTime($hora, 0, 0);
        switch ($frecuencia) {
            case 'MANUAL':
                return null;
            case 'DIARIO':
                $c = $at($now);
                return $c > $now ? $c : $at($now->modify('+1 day'));
            case 'SEMANAL':
                $dow = max(1, min(7, (int) $diaSemana));
                for ($i = 0; $i <= 7; $i++) {
                    $c = $at($now->modify("+$i day"));
                    if ((int) $c->format('N') === $dow && $c > $now) {
                        return $c;
                    }
                }
                return null;                                 // inalcanzable
            case 'QUINCENAL':
                foreach ([0, 1, 2] as $m) {
                    $first = $now->modify('first day of this month')->modify("+$m month");
                    foreach ([1, 16] as $day) {
                        $c = $at($first->setDate((int) $first->format('Y'), (int) $first->format('m'), $day));
                        if ($c > $now) {
                            return $c;
                        }
                    }
                }
                return null;
            case 'MENSUAL':
                $day = max(1, min(28, (int) $diaMes));
                foreach ([0, 1, 2] as $m) {
                    $first = $now->modify('first day of this month')->modify("+$m month");
                    $c = $at($first->setDate((int) $first->format('Y'), (int) $first->format('m'), $day));
                    if ($c > $now) {
                        return $c;
                    }
                }
                return null;
        }
        return null;
    }
}
