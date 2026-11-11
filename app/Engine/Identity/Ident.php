<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Identity;

final class Ident
{
    public ?string $numeroOriginal = null;
    public ?string $numero = null;
    public bool $numeroValido = false;
    public ?string $nombreOriginal = null;
    public ?string $nombreKey = null;
    public ?string $rfcOriginal = null;
    public ?string $rfc = null;
    public bool $rfcValido = false;
    public ?string $curpOriginal = null;
    public ?string $curp = null;
    public bool $curpValida = false;
}
