<?php

declare(strict_types=1);

namespace ConciliaRH\Core;

/**
 * Identificación del build (FASE 9 §48): versión, commit y fecha de empaquetado, SIN depender de .git en producción.
 * scripts/build-release.sh escribe BUILD.json en la raíz del paquete. En desarrollo no existe: se informa «desarrollo».
 * Se valida el formato: un BUILD.json alterado nunca inyecta texto arbitrario en la pantalla de salud.
 */
final class BuildInfo
{
    /** @return array{version:string,commit:?string,built_at:?string,origen:string} */
    public static function read(string $basePath, string $version): array
    {
        $f = $basePath . '/BUILD.json';
        $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        if (!is_array($d)) {
            return ['version' => $version, 'commit' => null, 'built_at' => null, 'origen' => 'desarrollo'];
        }
        $commit = is_string($d['commit'] ?? null) && preg_match('/^[0-9a-f]{7,40}$/', $d['commit']) ? $d['commit'] : null;
        $at = is_string($d['built_at'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $d['built_at']) ? $d['built_at'] : null;
        $v = is_string($d['version'] ?? null) && preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $d['version']) ? $d['version'] : $version;
        return ['version' => $v, 'commit' => $commit, 'built_at' => $at, 'origen' => 'paquete'];
    }
}
