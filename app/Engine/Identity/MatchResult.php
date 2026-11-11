<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Identity;

final class MatchResult
{
    /**
     * @param array<string,mixed> $evidencia
     * @param list<array{0:string,1:string,2:array<string,mixed>}> $incidencias
     */
    public function __construct(
        public string $estado,
        public ?int $empleadoId = null,
        public ?string $metodo = null,
        public string $confianza = 'NINGUNA',
        public array $evidencia = [],
        public array $incidencias = [],
    ) {
    }
}
