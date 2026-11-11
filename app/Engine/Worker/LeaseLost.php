<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Worker;

use RuntimeException;

final class LeaseLost extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('El trabajo ya no pertenece a este procesador.');
    }
}
