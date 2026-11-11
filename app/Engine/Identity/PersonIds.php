<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Identity;

use ConciliaRH\Engine\Support\Py;

final class PersonIds
{
    private const INVIS = '/[\x{00}-\x{1F}\x{7F}-\x{9F}\x{AD}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u';
    public const RFC_RE = '/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/Du';
    public const CURP_RE = '/^[A-Z][AEIOUX][A-Z]{2}\d{6}[HMX](AS|BC|BS|CC|CL|CM|CS|CH|DF|DG|GT|GR|HG|JC|MC|MN|MS|NT|NL|OC|PL|QT|QR|SP|SL|SR|TC|TS|TL|VZ|YN|ZS|NE)[B-DF-HJ-NP-TV-Z]{3}[A-Z0-9]\d$/Du';
    private const NUM_RE = '/^[0-9A-Z][0-9A-Z-]{0,19}$/Du';

    public static function cleanText(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = Py::strip((string) preg_replace('/[' . Py::WS . ']+/u', ' ', (string) preg_replace(self::INVIS, '', Py::nfc(Py::str($v)))));
        return $s === '' ? null : $s;
    }

    public static function nameKey(mixed $v): ?string
    {
        $s = self::cleanText($v);
        if ($s === null) {
            return null;
        }
        $s = Py::upper(Py::dropMarks(Py::nfkd($s)));
        $s = (string) preg_replace('/[^A-Z0-9 ]/u', ' ', $s);
        $s = Py::strip((string) preg_replace('/[' . Py::WS . ']+/u', ' ', $s));
        return $s === '' ? null : $s;
    }

    /** @return array{0:?string,1:bool} */
    public static function rfc(mixed $v): array
    {
        $s = self::cleanText($v);
        if ($s === null) {
            return [null, false];
        }
        $s = (string) preg_replace('/[' . Py::WS . '\-_.]/u', '', Py::upper($s));
        if ($s === '') {
            return [null, false];
        }
        return [$s, preg_match(self::RFC_RE, $s) === 1];
    }

    /** @return array{0:?string,1:bool} */
    public static function curp(mixed $v): array
    {
        $s = self::cleanText($v);
        if ($s === null) {
            return [null, false];
        }
        $s = (string) preg_replace('/[' . Py::WS . '\-_.]/u', '', Py::upper($s));
        if ($s === '') {
            return [null, false];
        }
        return [$s, preg_match(self::CURP_RE, $s) === 1];
    }

    /** @return array{0:?string,1:bool} */
    public static function employeeNumber(mixed $v): array
    {
        $s = self::cleanText($v);
        if ($s === null) {
            return [null, false];
        }
        $s = Py::upper($s);
        if (preg_match('/[0-9A-Z]/u', $s) !== 1) {
            return [null, false];
        }
        if (preg_match('/^\d+\.0+$/Du', $s) === 1) {
            $s = explode('.', $s)[0];
        }
        return [$s, preg_match(self::NUM_RE, $s) === 1 && preg_match('/\d/u', $s) === 1];
    }

    public static function digits(mixed $v): ?string
    {
        $s = self::cleanText($v);
        if ($s === null) {
            return null;
        }
        $d = (string) preg_replace('/\D/u', '', $s);
        return $d === '' ? null : $d;
    }
}
