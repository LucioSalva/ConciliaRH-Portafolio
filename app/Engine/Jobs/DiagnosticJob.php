<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Jobs;

use ConciliaRH\Domain\Jobs\JobTypes;
use ConciliaRH\Engine\Worker\JobContext;
use ConciliaRH\Engine\Worker\JobHandler;

final class DiagnosticJob implements JobHandler
{
    public function run(JobContext $ctx): array
    {
        $ctx->progress(10, 'VALIDANDO', 'Verificando entorno');
        $duration = (int) ($ctx->params()['duracion_segundos'] ?? 0);
        $steps = max(1, $duration);
        for ($i = 0; $i < $duration; $i++) {
            sleep(1);
            $ctx->progress(10 + 80 * ($i + 1) / $steps, 'VALIDANDO', 'Paso ' . ($i + 1) . " de $duration");
        }
        $storage = $ctx->settings->storagePath;
        $ok = is_dir($storage);
        $writable = false;
        if ($ok) {
            $work = rtrim($storage, '/') . '/trabajo';
            if (is_dir($work) || @mkdir($work, 0750, true)) {
                $tmp = @tempnam($work, 'diag-');
                if ($tmp !== false) {
                    $writable = @file_put_contents($tmp, 'ok') === 2;
                    @unlink($tmp);
                }
            }
        }
        $ctx->progress(95, 'FINALIZANDO', 'Reuniendo resultados');
        return ['eco' => $ctx->params()['eco'] ?? null, 'worker' => $ctx->workerId, 'motor_version' => $ctx->settings->version,
                'contract_version' => JobTypes::CONTRACT_VERSION, 'php' => PHP_VERSION,
                'postgresql' => (string) $ctx->db->fetchValue('SHOW server_version'),
                'almacenamiento' => ['existe' => $ok, 'escritura_temporal' => $writable]];
    }
}
