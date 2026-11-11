<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Conciliation;

final class Dispersion
{
    public function __construct(
        public readonly int $archivoId,
        public readonly string $banco,
        public readonly string $categoria,
        public string $grupo,
        public string $total,
    ) {
    }
}
