<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Periods;

use DateTimeImmutable;

/** Utilidades de calendario para periodos quincenales (mes numérico; el nombre es sólo presentación). */
final class PeriodCalendar
{
    public const MESES = [
        1 => 'ENERO', 2 => 'FEBRERO', 3 => 'MARZO', 4 => 'ABRIL', 5 => 'MAYO', 6 => 'JUNIO',
        7 => 'JULIO', 8 => 'AGOSTO', 9 => 'SEPTIEMBRE', 10 => 'OCTUBRE', 11 => 'NOVIEMBRE', 12 => 'DICIEMBRE',
    ];

    /** @return array{0:string,1:string} fechas por omisión (1–15 / 16–fin de mes) en formato Y-m-d */
    public static function defaultDates(int $anio, int $mes, int $quincena): array
    {
        $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes));
        if ($quincena === 1) {
            return [$first->format('Y-m-d'), $first->modify('+14 days')->format('Y-m-d')];
        }
        return [$first->modify('+15 days')->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d')];
    }

    public static function label(int $anio, int $mes, int $quincena, string $tipo = 'ORDINARIA'): string
    {
        $base = sprintf('%s QUINCENA %s %d', $quincena === 1 ? '1RA' : '2DA', self::MESES[$mes] ?? '?', $anio);
        return $tipo === 'ORDINARIA' ? $base : $base . ' (EXTRAORDINARIA)';
    }
}
