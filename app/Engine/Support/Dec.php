<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Support;

use InvalidArgumentException;

final class Dec
{
    public const ZERO = '0';

    public static function of(string|int $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        $v = trim($value);
        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/', $v, $m) !== 1 || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new InvalidArgumentException('Decimal inválido');
        }
        $sign = $m[1] === '-' ? '-' : '';
        $int = $m[2] === '' ? '0' : $m[2];
        $frac = $m[3] ?? '';
        $exp = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;
        if ($exp !== 0) {
            $digits = $int . $frac;
            $point = strlen($int) + $exp;
            if ($point <= 0) {
                $int = '0';
                $frac = str_repeat('0', -$point) . $digits;
            } elseif ($point >= strlen($digits)) {
                $int = $digits . str_repeat('0', $point - strlen($digits));
                $frac = '';
            } else {
                $int = substr($digits, 0, $point);
                $frac = substr($digits, $point);
            }
        }
        $int = ltrim($int, '0');
        $int = $int === '' ? '0' : $int;
        return $sign . $int . ($frac !== '' ? '.' . $frac : '');
    }

    public static function fromFloat(float $value): string
    {
        return self::of(Py::reprFloat($value));
    }

    public static function scale(string $a): int
    {
        $p = strpos($a, '.');
        return $p === false ? 0 : strlen($a) - $p - 1;
    }

    public static function add(string $a, string $b): string
    {
        return self::clean(bcadd($a, $b, max(self::scale($a), self::scale($b))));
    }

    public static function sub(string $a, string $b): string
    {
        return self::clean(bcsub($a, $b, max(self::scale($a), self::scale($b))));
    }

    public static function mul(string $a, string $b): string
    {
        return self::clean(bcmul($a, $b, self::scale($a) + self::scale($b)));
    }

    /** @param iterable<string> $values */
    public static function sum(iterable $values, string $start = self::ZERO): string
    {
        $t = $start;
        foreach ($values as $v) {
            $t = self::add($t, $v);
        }
        return $t;
    }

    public static function neg(string $a): string
    {
        return str_starts_with($a, '-') ? substr($a, 1) : '-' . $a;
    }

    public static function abs(string $a): string
    {
        return ltrim($a, '-');
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, max(self::scale($a), self::scale($b)));
    }

    public static function lt(string $a, string $b): bool
    {
        return self::cmp($a, $b) < 0;
    }

    public static function le(string $a, string $b): bool
    {
        return self::cmp($a, $b) <= 0;
    }

    public static function gt(string $a, string $b): bool
    {
        return self::cmp($a, $b) > 0;
    }

    public static function ge(string $a, string $b): bool
    {
        return self::cmp($a, $b) >= 0;
    }

    public static function eq(string $a, string $b): bool
    {
        return self::cmp($a, $b) === 0;
    }

    public static function isZero(string $a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    public static function within(string $a, string $b, string $tol): bool
    {
        return self::le(self::abs(self::sub($a, $b)), $tol);
    }

    public static function quantizeHalfUp(string $a, int $places = 2): string
    {
        if (self::scale($a) <= $places) {
            return self::pad($a, $places);
        }
        $neg = str_starts_with($a, '-');
        $half = '0.' . str_repeat('0', $places) . '5';
        $r = bcadd(self::abs($a), $half, $places);
        return $neg ? '-' . $r : $r;
    }

    public static function quantizeHalfEven(string $a, int $places = 2): string
    {
        if (self::scale($a) <= $places) {
            return self::pad($a, $places);
        }
        $neg = str_starts_with($a, '-');
        $abs = self::abs($a);
        $trunc = bcadd($abs, '0', $places);
        $rest = bcsub($abs, $trunc, self::scale($abs));
        $unit = $places === 0 ? '1' : '0.' . str_repeat('0', $places - 1) . '1';
        $half = bcdiv($unit, '2', $places + 1);
        $c = bccomp($rest, $half, self::scale($abs));
        $last = (int) substr(str_replace('.', '', $trunc), -1);
        if ($c > 0 || ($c === 0 && $last % 2 === 1)) {
            $trunc = bcadd($trunc, $unit, $places);
        }
        return self::clean(($neg && !self::isZero($trunc)) ? '-' . $trunc : $trunc);
    }

    public static function divToPlaces(string $a, string $b, int $places = 2): string
    {
        return self::quantizeHalfEven(bcdiv($a, $b, 40), $places);
    }

    public static function key(string $a): string
    {
        $v = self::of($a);
        if (str_contains($v, '.')) {
            $v = rtrim(rtrim($v, '0'), '.');
        }
        return $v === '-0' ? '0' : $v;
    }

    public static function pad(string $a, int $places): string
    {
        $s = self::scale($a);
        if ($s >= $places) {
            return $a;
        }
        return ($s === 0 ? $a . '.' : $a) . str_repeat('0', $places - $s);
    }

    public static function toInt(string $a): int
    {
        return (int) bcadd($a, '0', 0);
    }

    private static function clean(string $r): string
    {
        return preg_match('/^-0(\.0+)?$/', $r) === 1 ? substr($r, 1) : $r;
    }
}
