<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Support;

use ConciliaRH\Core\Database;

final class BatchInsert
{
    private const MAX_PARAMS = 60000;

    /**
     * @param list<string> $columns
     * @param list<list<mixed>> $rows
     * @param array<string,string> $casts columna => jsonb | bytea | bigint[] | text[] | ...
     * @return list<array<string,mixed>>
     */
    public static function run(Database $db, string $table, array $columns, array $rows, array $casts = [], string $returning = ''): array
    {
        if ($rows === []) {
            return [];
        }
        $n = count($columns);
        $per = max(1, intdiv(self::MAX_PARAMS, $n));
        $out = [];
        foreach (array_chunk($rows, $per) as $chunk) {
            $params = [];
            $tuples = [];
            foreach ($chunk as $i => $row) {
                $ph = [];
                foreach ($columns as $j => $col) {
                    $name = 'p' . $i . '_' . $j;
                    $v = $row[$j] ?? null;
                    $cast = $casts[$col] ?? null;
                    if ($cast === 'jsonb') {
                        $params[$name] = $v === null ? null : (is_string($v) ? $v : Json::encode($v));
                        $ph[] = 'CAST(:' . $name . ' AS jsonb)';
                    } elseif ($cast === 'bytea') {
                        $params[$name] = $v === null ? null : bin2hex((string) $v);
                        $ph[] = "decode(:$name, 'hex')";
                    } elseif ($cast !== null && str_ends_with($cast, '[]')) {
                        $params[$name] = $v === null ? null : self::arrayLiteral((array) $v);
                        $ph[] = 'CAST(:' . $name . ' AS ' . $cast . ')';
                    } elseif ($cast !== null) {
                        $params[$name] = $v;
                        $ph[] = 'CAST(:' . $name . ' AS ' . $cast . ')';
                    } else {
                        $params[$name] = $v;
                        $ph[] = ':' . $name;
                    }
                }
                $tuples[] = '(' . implode(', ', $ph) . ')';
            }
            $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES ' . implode(', ', $tuples)
                . ($returning !== '' ? ' RETURNING ' . $returning : '');
            if ($returning !== '') {
                array_push($out, ...$db->fetchAll($sql, $params));
            } else {
                $db->execute($sql, $params);
            }
        }
        return $out;
    }

    /** @param list<mixed> $values */
    public static function arrayLiteral(array $values): string
    {
        $items = array_map(static function ($v): string {
            if ($v === null) {
                return 'NULL';
            }
            if (is_int($v)) {
                return (string) $v;
            }
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v) . '"';
        }, array_values($values));
        return '{' . implode(',', $items) . '}';
    }
}
