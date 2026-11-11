<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Workbook;

final class Cell
{
    public function __construct(
        public mixed $value,
        public ?string $formula = null,
        public bool $isDate = false,
    ) {
    }
}
