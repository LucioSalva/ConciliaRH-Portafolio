<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Analysis;

final class StagingRow
{
    /** @param array<string,mixed> $datos */
    public function __construct(
        public readonly int $hoja,
        public readonly int $fila,
        public readonly bool $oculta,
        public readonly ?string $importe,
        public readonly string $importeEstado,
        public readonly array $datos,
        public readonly ?string $cuenta,
        public readonly string $hash,
        public readonly ?string $clave = null,
    ) {
    }
}
