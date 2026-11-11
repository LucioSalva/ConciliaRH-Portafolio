<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Unit;

use ConciliaRH\Domain\Reports\ScheduleCalculator as S;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** Casos 22–25 de la FASE 7: próxima ejecución por frecuencia (función pura, zona horaria de la aplicación). */
final class ScheduleCalculatorTest extends TestCase
{
    private const TZ = 'America/Mexico_City';

    private static function at(string $local): DateTimeImmutable
    {
        return new DateTimeImmutable($local, new \DateTimeZone(self::TZ));
    }

    private static function fmt(?DateTimeImmutable $d): ?string
    {
        return $d?->setTimezone(new \DateTimeZone(self::TZ))->format('Y-m-d H:i');
    }

    public function testCase22Daily(): void
    {
        self::assertSame('2031-04-10 07:00', self::fmt(S::next('DIARIO', 7, null, null, self::at('2031-04-10 06:59'), self::TZ)));
        self::assertSame('2031-04-11 07:00', self::fmt(S::next('DIARIO', 7, null, null, self::at('2031-04-10 07:00'), self::TZ)), 'Estrictamente después');
        self::assertSame('2032-01-01 00:00', self::fmt(S::next('DIARIO', 0, null, null, self::at('2031-12-31 23:30'), self::TZ)));
    }

    public function testCase23Weekly(): void
    {
        // 2031-04-10 es jueves (ISO 4)
        self::assertSame('2031-04-14 08:00', self::fmt(S::next('SEMANAL', 8, 1, null, self::at('2031-04-10 12:00'), self::TZ)), 'Siguiente lunes');
        self::assertSame('2031-04-10 13:00', self::fmt(S::next('SEMANAL', 13, 4, null, self::at('2031-04-10 12:00'), self::TZ)), 'Hoy mismo, más tarde');
        self::assertSame('2031-04-17 12:00', self::fmt(S::next('SEMANAL', 12, 4, null, self::at('2031-04-10 12:00'), self::TZ)), 'Una semana después');
    }

    public function testCase24Fortnightly(): void
    {
        self::assertSame('2031-04-16 07:00', self::fmt(S::next('QUINCENAL', 7, null, null, self::at('2031-04-01 07:00'), self::TZ)));
        self::assertSame('2031-05-01 07:00', self::fmt(S::next('QUINCENAL', 7, null, null, self::at('2031-04-16 08:00'), self::TZ)));
        self::assertSame('2032-01-01 07:00', self::fmt(S::next('QUINCENAL', 7, null, null, self::at('2031-12-20 00:00'), self::TZ)), 'Cambio de año');
        self::assertSame('2031-03-01 07:00', self::fmt(S::next('QUINCENAL', 7, null, null, self::at('2031-02-28 09:00'), self::TZ)), 'Febrero');
    }

    public function testCase25MonthlyAndManual(): void
    {
        self::assertSame('2031-04-28 06:00', self::fmt(S::next('MENSUAL', 6, null, 28, self::at('2031-04-10 00:00'), self::TZ)));
        self::assertSame('2031-05-28 06:00', self::fmt(S::next('MENSUAL', 6, null, 28, self::at('2031-04-28 06:00'), self::TZ)));
        self::assertSame('2031-03-28 06:00', self::fmt(S::next('MENSUAL', 6, null, 28, self::at('2031-02-28 07:00'), self::TZ)), 'Día 28 existe en febrero');
        self::assertNull(S::next('MANUAL', 7, null, null, self::at('2031-04-10 00:00'), self::TZ));
        // Sin rezago: si el scheduler estuvo detenido, la siguiente es después de AHORA (no una por cada ejecución perdida)
        self::assertSame('2031-06-11 07:00', self::fmt(S::next('DIARIO', 7, null, null, self::at('2031-06-10 09:00'), self::TZ)));
    }
}
