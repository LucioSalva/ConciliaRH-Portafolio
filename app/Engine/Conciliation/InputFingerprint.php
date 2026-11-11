<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Conciliation;

use ConciliaRH\Engine\Support\Json;

final class InputFingerprint
{
    /** @param array<string,mixed> $inp */
    public static function of(array $inp): string
    {
        $sortBy = static function (array $rows, callable $key): array {
            usort($rows, static fn ($a, $b) => $key($a) <=> $key($b));
            return array_map(static fn ($r) => Json::obj($r), $rows);
        };
        $rrhh = array_map(static function ($r) {
            $r['pensiones'] = array_map(static fn ($p) => Json::obj($p), $r['pensiones']);
            return $r;
        }, $inp['rrhh']);
        $relaciones = [];
        foreach ($inp['relaciones_manuales'] as $k => $v) {
            [$a, $h] = array_map('intval', explode(':', (string) $k));
            $relaciones[] = [$a, $h, (int) $v];
        }
        sort($relaciones);
        $payload = Json::obj([
            'motor' => Engine::VERSION,
            'tolerancia' => (string) $inp['tolerancia'],
            'archivos' => $sortBy($inp['archivos'], static fn ($a) => $a['archivo_id']),
            'rrhh' => $sortBy($rrhh, static fn ($r) => $r['id']),
            'tesoreria' => $sortBy($inp['tesoreria'], static fn ($t) => $t['id']),
            'globales' => $sortBy($inp['globales'], static fn ($g) => $g['archivo_id']),
            'egreso' => $sortBy($inp['egreso'], static fn ($h) => [$h['archivo_id'], $h['hoja_indice']]),
            'empleados' => $sortBy(array_values($inp['empleados']), static fn ($e) => $e['id']),
            'relaciones' => $relaciones,
        ]);
        return hash('sha256', Json::canonical($payload));
    }
}
