<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Conciliation;

final class Incident
{
    /** @param array<string,mixed> $detalle */
    public function __construct(
        public readonly string $clave,
        public readonly string $tipo,
        public readonly string $severidad,
        public readonly int $nivel,
        public readonly string $mensaje,
        public readonly ?int $empleadoId = null,
        public readonly ?string $grupo = null,
        public readonly ?string $banco = null,
        public readonly ?string $importe = null,
        public readonly array $detalle = [],
    ) {
    }
}
