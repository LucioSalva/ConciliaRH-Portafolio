<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Support;

use PDOException;

final class DbError
{
    public static function sqlState(PDOException $e): string
    {
        return (string) ($e->errorInfo[0] ?? $e->getCode());
    }

    public static function detail(PDOException $e): string
    {
        $line = '';
        foreach (preg_split('/\R/', (string) ($e->errorInfo[2] ?? $e->getMessage())) ?: [] as $l) {
            if (preg_match('/^\s*(DETAIL|DETALLE|CONTEXT|CONTEXTO|HINT|SUGERENCIA)\s*:/i', $l) === 1) {
                break;
            }
            $line .= ' ' . $l;
        }
        $table = self::quoted($line, '(?:relation|table|relación|tabla)');
        $constraint = self::quoted($line, '(?:constraint|restricción(?:\s+\S+)?)');
        return 'PDOException sqlstate=' . self::sqlState($e) . ' tabla=' . ($table ?? 'None') . ' restriccion=' . ($constraint ?? 'None');
    }

    private static function quoted(string $text, string $word): ?string
    {
        if (preg_match('/' . $word . '\s+(?:"([A-Za-z_][A-Za-z0-9_$.]*)"|«([A-Za-z_][A-Za-z0-9_$.]*)»)/u', $text, $m) !== 1) {
            return null;
        }
        return $m[1] !== '' ? $m[1] : ($m[2] ?? null);
    }
}
