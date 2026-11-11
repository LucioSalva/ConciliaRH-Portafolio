<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Workbook;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final class DateFormat
{
    private const BUILTIN = [
        0 => 'General', 1 => '0', 2 => '0.00', 3 => '#,##0', 4 => '#,##0.00', 5 => '"$"#,##0_);("$"#,##0)',
        6 => '"$"#,##0_);[Red]("$"#,##0)', 7 => '"$"#,##0.00_);("$"#,##0.00)', 8 => '"$"#,##0.00_);[Red]("$"#,##0.00)',
        9 => '0%', 10 => '0.00%', 11 => '0.00E+00', 12 => '# ?/?', 13 => '# ??/??', 14 => 'mm-dd-yy', 15 => 'd-mmm-yy',
        16 => 'd-mmm', 17 => 'mmm-yy', 18 => 'h:mm AM/PM', 19 => 'h:mm:ss AM/PM', 20 => 'h:mm', 21 => 'h:mm:ss', 22 => 'm/d/yy h:mm',
        37 => '#,##0_);(#,##0)', 38 => '#,##0_);[Red](#,##0)', 39 => '#,##0.00_);(#,##0.00)', 40 => '#,##0.00_);[Red](#,##0.00)',
        41 => '_(* #,##0_);_(* \(#,##0\);_(* "-"_);_(@_)', 42 => '_("$"* #,##0_);_("$"* \(#,##0\);_("$"* "-"_);_(@_)',
        43 => '_(* #,##0.00_);_(* \(#,##0.00\);_(* "-"??_);_(@_)', 44 => '_("$"* #,##0.00_)_("$"* \(#,##0.00\)_("$"* "-"??_)_(@_)',
        45 => 'mm:ss', 46 => '[h]:mm:ss', 47 => 'mmss.0', 48 => '##0.0E+0', 49 => '@',
    ];

    public static function builtin(int $id): string
    {
        return self::BUILTIN[$id] ?? '';
    }

    public static function isBuiltinDateId(int $id): bool
    {
        return ($id >= 14 && $id <= 22) || ($id >= 27 && $id <= 36) || ($id >= 45 && $id <= 47) || ($id >= 50 && $id <= 58);
    }

    public static function isDateFormat(?string $fmt): bool
    {
        if ($fmt === null) {
            return false;
        }
        $fmt = explode(';', $fmt)[0];
        $fmt = (string) preg_replace('/".*?"|\[(?!hh?\]|mm?\]|ss?\])[^\]]*\]/u', '', $fmt);
        return preg_match('/(?<![_\\\\])[dmhysDMHYS]/u', $fmt) === 1;
    }

    public static function serialToDate(float $serial, bool $date1904): ?DateTimeImmutable
    {
        if ($date1904) {
            $base = new DateTimeImmutable('1904-01-01 00:00:00', new DateTimeZone('UTC'));
        } else {
            if ($serial < 1) {
                return null;
            }
            $base = new DateTimeImmutable($serial < 60 ? '1899-12-31 00:00:00' : '1899-12-30 00:00:00', new DateTimeZone('UTC'));
        }
        $micros = (int) round($serial * 86_400_000_000, 0, PHP_ROUND_HALF_EVEN);
        if (abs($micros) > 315_537_897_600_000_000) {
            return null;
        }
        $days = intdiv($micros, 86_400_000_000);
        $rest = $micros - $days * 86_400_000_000;
        if ($rest < 0) {
            $days--;
            $rest += 86_400_000_000;
        }
        $d = $days >= 0 ? $base->add(new DateInterval('P' . $days . 'D')) : $base->sub(new DateInterval('P' . -$days . 'D'));
        $seconds = intdiv($rest, 1_000_000);
        $d = $d->add(new DateInterval('PT' . $seconds . 'S'));
        $us = $rest % 1_000_000;
        $d = $us ? $d->modify('+' . $us . ' microseconds') : $d;
        $y = (int) $d->format('Y');
        return ($y < 1 || $y > 9999) ? null : $d;
    }

    public static function xlrdDate(float $serial, bool $date1904): ?DateTimeImmutable
    {
        if ($serial < 0) {
            return null;
        }
        $base = new DateTimeImmutable($date1904 ? '1904-01-01' : ($serial < 60 ? '1899-12-31' : '1899-12-30'), new DateTimeZone('UTC'));
        $days = (int) $serial;
        $ms = (int) round(($serial - $days) * 86_400_000.0, 0, PHP_ROUND_HALF_EVEN);
        $d = $base->add(new DateInterval('P' . $days . 'D'));
        $sec = intdiv($ms, 1000);
        $d = $d->add(new DateInterval('PT' . $sec . 'S'));
        $rest = $ms % 1000;
        return $rest ? $d->modify('+' . ($rest * 1000) . ' microseconds') : $d;
    }
}
