<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Analysis;

use ConciliaRH\Engine\Support\Dec;
use ConciliaRH\Engine\Support\Py;
use ConciliaRH\Engine\Workbook\ErrorValue;
use DateTimeInterface;

final class Values
{
    private const INVISIBLE = '/[\x{00}-\x{08}\x{0B}\x{0C}\x{0E}-\x{1F}\x{7F}-\x{9F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}\x{AD}]/u';
    private const MAX_MONEY = '1000000000000';
    private const CURRENCY = '/(MXN|M\.?[' . Py::WS . ']?N\.?|PESOS|US\$|USD|\$)/iu';

    public static function isText(mixed $v): bool
    {
        return is_string($v) || $v instanceof ErrorValue;
    }

    public static function isNumber(mixed $v): bool
    {
        return is_int($v) || is_float($v);
    }

    public static function stripInvisible(string $s): string
    {
        return (string) preg_replace(self::INVISIBLE, '', $s);
    }

    public static function collapse(string $s): string
    {
        return (string) preg_replace('/[' . Py::WS . ']+/u', ' ', $s);
    }

    public static function normHeader(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        $s = self::stripInvisible(Py::str($v));
        $s = Py::upper(Py::dropMarks(Py::nfd($s)));
        $s = str_replace(['_', '-', '/'], ' ', $s);
        $s = (string) preg_replace('/[^A-Z0-9%$#&Ñ ]/u', '', $s);
        return Py::strip(self::collapse($s));
    }

    public static function normKey(mixed $v): string
    {
        return self::normHeader($v);
    }

    public static function normText(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = Py::strip(self::collapse(self::stripInvisible(Py::nfc(Py::str($v)))));
        return $s === '' ? null : $s;
    }

    public static function isBlank(mixed $v): bool
    {
        if ($v === null) {
            return true;
        }
        if (!self::isText($v)) {
            return false;
        }
        return (string) preg_replace('/[' . Py::WS . ']+/u', '', self::stripInvisible((string) $v)) === '';
    }

    public static function parseMoney(mixed $v): MoneyValue
    {
        if ($v === null) {
            return new MoneyValue('VACIO');
        }
        if ($v instanceof ErrorValue) {
            return new MoneyValue('INVALIDO', rawKind: 'ERROR');
        }
        if (is_bool($v)) {
            return new MoneyValue('INVALIDO', rawKind: 'BOOLEANO');
        }
        if ($v instanceof DateTimeInterface) {
            return new MoneyValue('INVALIDO', rawKind: 'FECHA');
        }
        if (is_int($v)) {
            return self::okMoney((string) $v, 'NUMERO');
        }
        if (is_float($v)) {
            if (is_nan($v) || is_infinite($v)) {
                return new MoneyValue('INVALIDO', rawKind: 'NUMERO');
            }
            return self::okMoney(Dec::fromFloat($v), 'NUMERO');
        }
        $s = Py::strip(self::stripInvisible((string) $v));
        if ($s === '' || in_array($s, ['-', '—', '–'], true)) {
            return new MoneyValue('VACIO', rawKind: 'TEXTO');
        }
        $neg = false;
        if (str_starts_with($s, '(') && str_ends_with($s, ')')) {
            $neg = true;
            $s = Py::strip(substr($s, 1, -1));
        }
        $s = Py::strip((string) preg_replace(self::CURRENCY, '', $s));
        if (str_starts_with($s, '-')) {
            if ($neg) {
                return new MoneyValue('INVALIDO', rawKind: 'TEXTO');
            }
            $neg = true;
            $s = Py::strip(substr($s, 1));
        } elseif (str_ends_with($s, '-')) {
            $neg = true;
            $s = Py::strip(substr($s, 0, -1));
        }
        if (str_starts_with($s, '+')) {
            $s = Py::strip(substr($s, 1));
        }
        $s = (string) preg_replace('/[' . Py::WS . ']+/u', '', $s);
        if (preg_match('/^\d[\d,.]*$/D', $s) !== 1) {
            return new MoneyValue('INVALIDO', rawKind: 'TEXTO');
        }
        if (str_contains($s, ',') && str_contains($s, '.')) {
            [$dec, $thou] = strrpos($s, '.') > strrpos($s, ',') ? ['.', ','] : [',', '.'];
        } elseif (str_contains($s, ',')) {
            if (preg_match('/^\d{1,3}(,\d{3})+$/D', $s) === 1) {
                [$dec, $thou] = [null, ','];
            } elseif (preg_match('/^\d+,\d{1,2}$/D', $s) === 1) {
                [$dec, $thou] = [',', null];
            } else {
                return new MoneyValue('INVALIDO', rawKind: 'TEXTO');
            }
        } elseif (substr_count($s, '.') > 1) {
            if (preg_match('/^\d{1,3}(\.\d{3})+$/D', $s) === 1) {
                [$dec, $thou] = [null, '.'];
            } else {
                return new MoneyValue('INVALIDO', rawKind: 'TEXTO');
            }
        } else {
            if (preg_match('/^[1-9]\d{0,2}\.\d{3}$/D', $s) === 1) {
                return new MoneyValue('INVALIDO', rawKind: 'AMBIGUO');
            }
            [$dec, $thou] = ['.', null];
        }
        if ($dec !== null) {
            $p = strpos($s, $dec);
            [$int, $frac] = $p === false ? [$s, ''] : [substr($s, 0, $p), substr($s, $p + 1)];
        } else {
            [$int, $frac] = [$s, ''];
        }
        if ($thou !== null) {
            if (preg_match('/^\d{1,3}(' . preg_quote($thou, '/') . '\d{3})*$/D', $int) !== 1) {
                return new MoneyValue('INVALIDO', rawKind: 'TEXTO');
            }
            $int = str_replace($thou, '', $int);
        }
        if ($dec !== null && str_contains($s, $dec) && preg_match('/^\d+$/D', $frac) !== 1) {
            return new MoneyValue('INVALIDO', rawKind: 'TEXTO');
        }
        if (preg_match('/^\d+$/D', $int) !== 1 && $int !== '') {
            return new MoneyValue('INVALIDO', rawKind: 'TEXTO');
        }
        $d = Dec::of(($int === '' ? '0' : $int) . ($frac !== '' ? '.' . $frac : ''));
        if ($int === '' && $frac === '') {
            return new MoneyValue('INVALIDO', rawKind: 'TEXTO');
        }
        return self::okMoney($neg ? Dec::neg($d) : $d, 'TEXTO');
    }

    public static function moneyStr(?string $d): ?string
    {
        return $d;
    }

    /** @return array{0:?string,1:bool} */
    public static function parseIdentifier(mixed $v): array
    {
        if ($v === null || $v instanceof ErrorValue || is_bool($v)) {
            return [null, false];
        }
        if (is_int($v)) {
            return [(string) $v, false];
        }
        if (is_float($v)) {
            if (is_nan($v)) {
                return [null, false];
            }
            if (is_finite($v) && floor($v) === $v) {
                return [sprintf('%.0f', $v), abs($v) > 2 ** 53];
            }
            return [Py::reprFloat($v), false];
        }
        if ($v instanceof DateTimeInterface) {
            return [Py::isoDateTime($v), false];
        }
        $s = self::normText($v);
        if ($s === null) {
            return [null, false];
        }
        return [preg_match('/^[\d ]+$/Du', $s) === 1 ? str_replace(' ', '', $s) : $s, false];
    }

    public static function identifier(mixed $v): ?string
    {
        return self::parseIdentifier($v)[0];
    }

    public static function digitsOnly(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $d = (string) preg_replace('/\D/u', '', $v);
        return $d === '' ? null : $d;
    }

    public static function parseDate(mixed $v): ?string
    {
        if ($v === null || $v instanceof ErrorValue) {
            return null;
        }
        if ($v instanceof DateTimeInterface) {
            return $v->format('H:i:s') === '00:00:00' ? $v->format('Y-m-d') : $v->format('Y-m-d\TH:i:s');
        }
        $s = self::normText($v);
        if ($s === null) {
            return null;
        }
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#D', $s, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]) : $s;
        }
        return $s;
    }

    public static function looksLike(string $kind, ?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        $v = Py::upper($value);
        return match ($kind) {
            'RFC' => preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/Du', $v) === 1,
            'CURP' => preg_match('/^[A-Z]{4}\d{6}[HMX][A-Z]{5}[A-Z0-9]\d$/Du', $v) === 1,
            'CLABE' => preg_match('/^\d{18}$/D', $v) === 1,
            'CUENTA' => preg_match('/^\d{10,11}$/D', $v) === 1,
            'NUM_EMPLEADO' => preg_match('/^\d{1,8}$/D', $v) === 1,
            default => false,
        };
    }

    private static function okMoney(string $d, string $kind): MoneyValue
    {
        if (Dec::ge(Dec::abs($d), self::MAX_MONEY)) {
            return new MoneyValue('INVALIDO', rawKind: 'FUERA_DE_RANGO');
        }
        $q = Dec::quantizeHalfUp($d, 2);
        if (Dec::isZero($q)) {
            $q = '0.00';
        }
        return new MoneyValue('OK', $q, Dec::lt($q, '0'), $kind, $d);
    }
}
