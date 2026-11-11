<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Analysis;

use ConciliaRH\Engine\Support\Dec;
use ConciliaRH\Engine\Support\Json;
use ConciliaRH\Engine\Support\Py;
use ConciliaRH\Engine\Workbook\Cell;
use ConciliaRH\Engine\Workbook\ErrorValue;

final class Fingerprint
{
    public const VERSION = 1;

    public static function generic(?Cell $cell): mixed
    {
        if ($cell === null || $cell->value === null) {
            return null;
        }
        $v = $cell->value;
        if ($v instanceof ErrorValue) {
            return '!ERROR:' . $v->text;
        }
        if ($cell->isDate) {
            return Values::parseDate($v);
        }
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            if (is_finite($v) && floor($v) === $v) {
                return sprintf('%.0f', $v);
            }
            return self::normalizedDecimal(Py::reprFloat($v));
        }
        return Values::normText($v);
    }

    public static function fieldValue(string $kind, ?Cell $cell): mixed
    {
        if ($cell === null || $cell->value === null) {
            return null;
        }
        $v = $cell->value;
        if ($kind === 'money') {
            $m = Values::parseMoney($v);
            if ($m->ok()) {
                return $m->value;
            }
            if ($m->status === 'VACIO') {
                return null;
            }
            return '!INVALIDO:' . (Values::normText($v) ?? '');
        }
        if (in_array($kind, ['id', 'code', 'int'], true)) {
            return Values::identifier($v);
        }
        if ($kind === 'account' || $kind === 'clabe') {
            $ident = Values::identifier($v);
            return Values::digitsOnly($ident) ?? $ident;
        }
        if ($kind === 'rfc' || $kind === 'curp') {
            $ident = Values::identifier($v);
            return $ident !== null && $ident !== '' ? Py::upper($ident) : null;
        }
        if ($kind === 'date') {
            return Values::parseDate($v);
        }
        return Values::isNumber($v) ? self::generic($cell) : Values::normText($v);
    }

    /** @return array<string,mixed> */
    public static function canonicalRow(Profile $profile, Mapping $mapping, RowInfo $row): array
    {
        $out = [];
        foreach ($mapping->cols as $col => $canon) {
            $fld = $profile->field($canon);
            if ($fld === null || !$fld->huella) {
                continue;
            }
            $out[$canon] = self::fieldValue($fld->kind, $row->cells[$col] ?? null);
        }
        foreach ($mapping->conceptCols as $col => $code) {
            $out['C:' . $code] = self::fieldValue('money', $row->cells[$col] ?? null);
        }
        $seen = [];
        $others = $mapping->otherCols;
        ksort($others);
        foreach ($others as $col => $norm) {
            $n = $seen[$norm] ?? 0;
            $seen[$norm] = $n + 1;
            $out['X:' . $norm . ($n ? '#' . ($n + 1) : '')] = self::generic($row->cells[$col] ?? null);
        }
        return $out;
    }

    /** @param array{block:string,row:int,code:?string,description:mixed,amount:MoneyValue} $ln */
    public static function canonicalGlobalLine(array $ln): array
    {
        $amount = $ln['amount'];
        return ['BLOQUE' => $ln['block'], 'CODIGO' => $ln['code'], 'DESCRIPCION' => Values::normText($ln['description']),
                'IMPORTE' => $amount->ok() ? $amount->value : ($amount->status === 'VACIO' ? null : '!INVALIDO')];
    }

    public static function dumps(mixed $obj): string
    {
        return Json::canonical($obj);
    }

    public static function rowHash(array $record, ?string $key = null): string
    {
        return self::digest('r:' . self::dumps(Json::obj($record)), $key);
    }

    /** @param list<string> $fields */
    public static function keyHash(array $record, array $fields, ?string $key = null): ?string
    {
        if ($fields === []) {
            return null;
        }
        $k = [];
        $allEmpty = true;
        foreach ($fields as $f) {
            $k[$f] = $record[$f] ?? null;
            if ($k[$f] !== null && $k[$f] !== '') {
                $allEmpty = false;
            }
        }
        if ($allEmpty) {
            return null;
        }
        return self::digest('k:' . self::dumps(Json::obj($k)), $key);
    }

    /** @param list<array{0:?string,1:list<array<string,mixed>>}> $sheets */
    public static function huella(string $profileCode, array $sheets, bool $orderSignificant = false): string
    {
        $blocks = [];
        foreach ($sheets as [$name, $records]) {
            $campos = [];
            foreach ($records as $r) {
                foreach (array_keys($r) as $k) {
                    $campos[(string) $k] = true;
                }
            }
            $campos = array_keys($campos);
            usort($campos, static fn ($a, $b) => strcmp((string) $a, (string) $b));
            $rows = [];
            foreach ($records as $r) {
                $rows[] = array_map(static fn ($k) => $r[$k] ?? null, $campos);
            }
            if (!$orderSignificant) {
                $keyed = array_map(static fn ($row) => [self::dumps($row), $row], $rows);
                usort($keyed, static fn ($a, $b) => strcmp($a[0], $b[0]));
                $rows = array_column($keyed, 1);
            }
            $blocks[] = Json::obj(['hoja' => $name !== null ? Values::normHeader($name) : null, 'campos' => $campos, 'registros' => $rows]);
        }
        $keyedBlocks = array_map(static fn ($b) => [self::dumps($b), $b], $blocks);
        usort($keyedBlocks, static fn ($a, $b) => strcmp($a[0], $b[0]));
        $payload = Json::obj(['v' => self::VERSION, 'perfil' => $profileCode, 'hojas' => array_column($keyedBlocks, 1)]);
        return hash('sha256', self::dumps($payload));
    }

    private static function digest(string $data, ?string $key): string
    {
        return $key !== null && $key !== '' ? hash_hmac('sha256', $data, $key) : hash('sha256', $data);
    }

    private static function normalizedDecimal(string $repr): string
    {
        $d = Dec::of($repr);
        if (str_contains($d, '.')) {
            $d = rtrim(rtrim($d, '0'), '.');
        }
        return $d === '-0' ? '0' : $d;
    }
}
