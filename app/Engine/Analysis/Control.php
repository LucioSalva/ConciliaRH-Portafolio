<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Analysis;

final class Control
{
    /**
     * @param list<int> $filas
     * @param array<string,mixed> $detalle
     */
    public function __construct(
        public string $codigo,
        public string $descripcion,
        public string $tipoValor = 'MONEDA',
        public ?string $declarado = null,
        public ?string $calculado = null,
        public ?string $diferencia = null,
        public ?string $tolerancia = null,
        public string $estado = 'NO_APLICA',
        public array $filas = [],
        public string $mensaje = '',
        public array $detalle = [],
        public ?int $hoja = null,
    ) {
    }
}
