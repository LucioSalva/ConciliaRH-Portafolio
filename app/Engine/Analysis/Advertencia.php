<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Analysis;

final class Advertencia
{
    public function __construct(
        public readonly string $codigo,
        public readonly string $severidad,
        public readonly string $mensaje,
        public readonly ?int $hoja = null,
    ) {
    }

    /** @return array{codigo:string,severidad:string,mensaje:string,hoja:?int} */
    public function asArray(): array
    {
        return ['codigo' => $this->codigo, 'severidad' => $this->severidad, 'mensaje' => $this->mensaje, 'hoja' => $this->hoja];
    }
}
