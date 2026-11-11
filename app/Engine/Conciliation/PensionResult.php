<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Conciliation;

use ConciliaRH\Engine\Support\Dec;

final class PensionResult
{
    /** @param array<string,mixed> $detalle */
    public function __construct(
        public int $empleadoId,
        public string $grupo,
        public string $numero,
        public string $retenido,
        public string $dispersado,
        public int $dispersiones,
        public string $estado,
        public ?string $alerta = null,
        public array $detalle = [],
    ) {
    }

    public function diferencia(): string
    {
        return Dec::sub($this->dispersado, $this->retenido);
    }
}
