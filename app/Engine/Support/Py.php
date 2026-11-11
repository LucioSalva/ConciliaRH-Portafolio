<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Support;

use Normalizer;

final class Py
{
    public const WS = '\t\n\x{0B}\f\r\x{1C}-\x{20}\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}';

    public static function reprFloat(float $v): string
    {
        if (is_nan($v)) {
            return 'nan';
        }
        if (is_infinite($v)) {
            return $v > 0 ? 'inf' : '-inf';
        }
        $s = var_export($v, true);
        $neg = str_starts_with($s, '-');
        $s = ltrim($s, '-');
        $exp = 0;
        if (preg_match('/^(\d+)(?:\.(\d+))?[eE]([+-]?\d+)$/', $s, $m) === 1) {
            $digits = $m[1] . ($m[2] ?? '');
            $exp = (int) $m[3] + strlen($m[1]) - 1;
        } else {
            [$i, $f] = array_pad(explode('.', $s, 2), 2, '');
            $digits = $i . $f;
            $exp = strlen($i) - 1;
        }
        $lead = strlen($digits) - strlen(ltrim($digits, '0'));
        $digits = ltrim($digits, '0');
        $exp -= $lead;
        $digits = rtrim($digits, '0');
        if ($digits === '') {
            return ($neg ? '-' : '') . '0.0';
        }
        if ($exp < -4 || $exp >= 16) {
            $mant = $digits[0] . (strlen($digits) > 1 ? '.' . substr($digits, 1) : '');
            $out = $mant . 'e' . ($exp < 0 ? '-' : '+') . str_pad((string) abs($exp), 2, '0', STR_PAD_LEFT);
        } elseif ($exp < 0) {
            $out = '0.' . str_repeat('0', -$exp - 1) . $digits;
        } else {
            $int = substr($digits . str_repeat('0', max(0, $exp + 1 - strlen($digits))), 0, $exp + 1);
            $frac = substr($digits, $exp + 1);
            $out = $int . '.' . ($frac === '' ? '0' : $frac);
        }
        return ($neg ? '-' : '') . $out;
    }

    public static function round(float $v, int $places): float
    {
        if (is_nan($v) || is_infinite($v)) {
            return $v;
        }
        $exact = sprintf('%.53F', $v);
        $r = Dec::quantizeHalfEven(Dec::of($exact), $places);
        return (float) $r;
    }

    public static function strip(string $s): string
    {
        return (string) preg_replace('/^[' . self::WS . ']+|[' . self::WS . ']+$/u', '', $s);
    }

    public static function isBlankText(string $s): bool
    {
        return self::strip($s) === '';
    }

    public static function upper(string $s): string
    {
        return mb_strtoupper($s, 'UTF-8');
    }

    public static function nfc(string $s): string
    {
        return self::norm($s, Normalizer::FORM_C);
    }

    public static function nfd(string $s): string
    {
        return self::norm($s, Normalizer::FORM_D);
    }

    public static function nfkd(string $s): string
    {
        return self::norm($s, Normalizer::FORM_KD);
    }

    public static function dropMarks(string $s): string
    {
        return (string) preg_replace('/\p{Mn}/u', '', $s);
    }

    public static function sub(string $s, int $length): string
    {
        return mb_substr($s, 0, $length, 'UTF-8');
    }

    public static function len(string $s): int
    {
        return mb_strlen($s, 'UTF-8');
    }

    public static function str(mixed $v): string
    {
        return match (true) {
            $v === null => 'None',
            $v === true => 'True',
            $v === false => 'False',
            is_float($v) => self::reprFloat($v),
            $v instanceof \DateTimeInterface => self::isoDateTime($v, ' '),
            default => (string) $v,
        };
    }

    public static function isoDateTime(\DateTimeInterface $d, string $sep = 'T'): string
    {
        $micro = (int) $d->format('u');
        return $d->format('Y-m-d') . $sep . $d->format('H:i:s') . ($micro ? sprintf('.%06d', $micro) : '');
    }

    public static function isDigits(string $s): bool
    {
        return $s !== '' && preg_match('/^\d+$/u', $s) === 1;
    }

    private static function norm(string $s, int $form): string
    {
        $r = Normalizer::normalize($s, $form);
        return $r === false ? $s : $r;
    }
}
