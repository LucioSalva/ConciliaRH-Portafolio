<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Files;

use RuntimeException;

final class StoredOriginal
{
    private const RELATIVE = '#^imports/[0-9]+/[0-9]+/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/original\.(xls|xlsx|xlsm)$#';

    public static function resolve(string $storageRoot, string $relative): string
    {
        if (preg_match(self::RELATIVE, $relative) !== 1) {
            throw new RuntimeException('ruta relativa con formato inválido');
        }
        $base = realpath(rtrim($storageRoot, '/') . '/imports');
        $candidate = rtrim($storageRoot, '/') . '/' . $relative;
        if ($base === false) {
            throw new RuntimeException('ruta fuera del almacenamiento de importaciones');
        }
        if (is_link($candidate)) {
            throw new RuntimeException('ruta fuera del almacenamiento de importaciones');
        }
        $resolved = realpath($candidate);
        if ($resolved === false) {
            return $candidate;
        }
        if (!str_starts_with($resolved, $base . '/')) {
            throw new RuntimeException('ruta fuera del almacenamiento de importaciones');
        }
        return $resolved;
    }
}
