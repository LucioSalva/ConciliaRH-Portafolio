<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Workbook;

use InvalidArgumentException;

final class FormulaTranslator
{
    public static function translate(string $formula, string $origin, string $dest): ?string
    {
        try {
            [$r0, $c0] = Coordinates::toTuple($origin);
            [$r1, $c1] = Coordinates::toTuple($dest);
        } catch (InvalidArgumentException) {
            return null;
        }
        $rd = $r1 - $r0;
        $cd = $c1 - $c0;
        $out = '';
        $len = strlen($formula);
        $i = 0;
        while ($i < $len) {
            $ch = $formula[$i];
            if ($ch === '"') {
                $j = $i + 1;
                while ($j < $len) {
                    if ($formula[$j] === '"') {
                        if ($j + 1 < $len && $formula[$j + 1] === '"') {
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $j++;
                }
                $out .= substr($formula, $i, $j - $i + 1);
                $i = $j + 1;
                continue;
            }
            if (preg_match('/\G(?:\'(?:[^\']|\'\')*\'!|[A-Za-z0-9_.$!:#\[\]]+)+/', $formula, $m, 0, $i) === 1) {
                $token = $m[0];
                $next = $i + strlen($token);
                if ($next < $len && $formula[$next] === '(') {
                    $out .= $token;
                } else {
                    $t = self::range($token, $rd, $cd);
                    if ($t === null) {
                        return null;
                    }
                    $out .= $t;
                }
                $i = $next;
                continue;
            }
            $out .= $ch;
            $i++;
        }
        return $out;
    }

    private static function range(string $range, int $rd, int $cd): ?string
    {
        $ws = '';
        if (str_contains($range, '!')) {
            $p = strrpos($range, '!');
            $ws = substr($range, 0, $p + 1);
            $range = substr($range, $p + 1);
        }
        if (preg_match('/^(\$?[1-9][0-9]{0,6}):(\$?[1-9][0-9]{0,6})$/', $range, $m) === 1) {
            $a = self::row($m[1], $rd);
            $b = self::row($m[2], $rd);
            return $a === null || $b === null ? null : $ws . $a . ':' . $b;
        }
        if (preg_match('/^(\$?[A-Za-z]{1,3}):(\$?[A-Za-z]{1,3})$/', $range, $m) === 1) {
            $a = self::col($m[1], $cd);
            $b = self::col($m[2], $cd);
            return $a === null || $b === null ? null : $ws . $a . ':' . $b;
        }
        if (str_contains($range, ':')) {
            $pieces = [];
            foreach (explode(':', $range) as $piece) {
                $t = self::range($piece, $rd, $cd);
                if ($t === null) {
                    return null;
                }
                $pieces[] = $t;
            }
            return $ws . implode(':', $pieces);
        }
        if (preg_match('/^(\$?[A-Za-z]{1,3})(\$?[1-9][0-9]{0,6})$/', $range, $m) !== 1) {
            return $ws . $range;
        }
        $c = self::col($m[1], $cd);
        $r = self::row($m[2], $rd);
        return $c === null || $r === null ? null : $ws . $c . $r;
    }

    private static function row(string $s, int $rd): ?string
    {
        if (str_starts_with($s, '$')) {
            return $s;
        }
        $n = (int) $s + $rd;
        return $n <= 0 ? null : (string) $n;
    }

    private static function col(string $s, int $cd): ?string
    {
        if (str_starts_with($s, '$')) {
            return $s;
        }
        try {
            $n = Coordinates::index($s) + $cd;
        } catch (InvalidArgumentException) {
            return null;
        }
        return $n < 1 || $n > 18278 ? null : Coordinates::letter($n - 1);
    }
}
