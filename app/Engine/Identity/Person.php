<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Identity;

final class Person
{
    public function __construct(
        public readonly int $id,
        public readonly string $numero,
        public readonly ?string $rfc = null,
        public readonly bool $rfcValido = false,
        public readonly ?string $curp = null,
        public readonly bool $curpValida = false,
        public readonly ?string $nameKey = null,
    ) {
    }
}
