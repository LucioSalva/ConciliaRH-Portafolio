<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Jobs;

use ConciliaRH\Engine\Worker\JobHandler;

final class JobRegistry
{
    /** @return array<string,JobHandler> */
    public static function handlers(): array
    {
        return [
            'DIAGNOSTICO' => new DiagnosticJob(),
            'VERIFICAR_ARCHIVO' => new VerifyFileJob(),
            'ANALIZAR_ARCHIVO' => new AnalyzeFileJob(),
            'NORMALIZAR_REGISTROS' => new NormalizeRecordsJob(),
            'CONCILIAR_LOTE' => new ConciliateLotJob(),
            'DETECTAR_DUPLICADOS' => new DetectDuplicatesJob(),
        ];
    }
}
