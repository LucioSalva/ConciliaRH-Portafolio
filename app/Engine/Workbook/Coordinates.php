<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Workbook;

use InvalidArgumentException;

final class Coordinates
{
    public static function letter(int $zeroBased): string
    {
        $s = '';
        $n = $zeroBased + 1;
        while ($n > 0) {
            $r = ($n - 1) % 26;
            $n = intdiv($n - 1, 26);
            $s = chr(65 + $r) . $s;
        }
        return $s;
    }

    public static function index(string $letters): int
    {
        $letters = strtoupper($letters);
        if (preg_match('/^[A-Z]{1,3}$/', $letters) !== 1) {
            throw new InvalidArgumentException('Columna inválida');
        }
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        if ($n > 18278) {
            throw new InvalidArgumentException('Columna inválida');
        }
        return $n;
    }

    /** @return array{int,int} [fila, columna] base 1 */
    public static function toTuple(string $ref): array
    {
        if (preg_match('/^\$?([A-Za-z]{1,3})\$?(\d+)$/', $ref, $m) !== 1) {
            throw new InvalidArgumentException('Celda inválida');
        }
        return [(int) $m[2], self::index($m[1])];
    }

    /** @return array{int,int,int,int}|null [col1, fila1, col2, fila2] base 1 */
    public static function rangeBoundaries(string $ref): ?array
    {
        $ref = strtoupper(str_replace('$', '', $ref));
        if (preg_match('/^([A-Z]{1,3})(\d+)(?::([A-Z]{1,3})(\d+))?$/', $ref, $m) !== 1) {
            return null;
        }
        try {
            $c1 = self::index($m[1]);
            $r1 = (int) $m[2];
            $c2 = isset($m[3]) && $m[3] !== '' ? self::index($m[3]) : $c1;
            $r2 = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : $r1;
        } catch (InvalidArgumentException) {
            return null;
        }
        return [min($c1, $c2), min($r1, $r2), max($c1, $c2), max($r1, $r2)];
    }
}
