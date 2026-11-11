<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Conciliation;

final class LevelControl
{
    /**
     * @param list<int> $fuentes
     * @param array<string,mixed> $detalle
     */
    public function __construct(
        public int $nivel,
        public string $codigo,
        public string $descripcion,
        public string $estado,
        public ?string $grupo = null,
        public ?string $banco = null,
        public ?string $categoria = null,
        public ?string $referencia = null,
        public ?string $valorA = null,
        public ?string $etiquetaA = null,
        public ?string $valorB = null,
        public ?string $etiquetaB = null,
        public ?string $valorC = null,
        public ?string $etiquetaC = null,
        public ?string $diferencia = null,
        public array $fuentes = [],
        public array $detalle = [],
    ) {
    }
}
