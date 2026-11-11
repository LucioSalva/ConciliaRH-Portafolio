<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Identity;

final class Evidence
{
    /** @param list<int> $rechazados */
    public function __construct(
        public readonly ?string $numero = null,
        public readonly bool $numeroValido = false,
        public readonly ?string $rfc = null,
        public readonly bool $rfcValido = false,
        public readonly ?string $curp = null,
        public readonly bool $curpValida = false,
        public readonly ?string $cuentaHmac = null,
        public readonly string $uso = 'NOMINA',
        public readonly ?string $nameKey = null,
        public readonly bool $allowName = true,
        public readonly array $rechazados = [],
    ) {
    }

    /** @param list<int>|null $rechazados */
    public static function of(?string $numero, bool $numeroValido, ?string $rfc, bool $rfcValido, ?string $curp, bool $curpValida,
                              ?string $nameKey, ?string $cuentaHmac, bool $pension, ?array $rechazados): self
    {
        return new self($numero, $numeroValido, $rfc, $rfcValido, $curp, $curpValida, $cuentaHmac,
            $pension ? 'PENSION_BENEFICIARIO' : 'NOMINA', $nameKey, !$pension, array_values(array_map('intval', $rechazados ?? [])));
    }
}
