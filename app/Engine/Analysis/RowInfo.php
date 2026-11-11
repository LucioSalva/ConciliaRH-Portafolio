<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Analysis;

use ConciliaRH\Engine\Workbook\Cell;

final class RowInfo
{
    /** @param array<int,Cell> $cells */
    public function __construct(
        public int $index,
        public bool $hidden,
        public string $kind,
        public string $subkind = '',
        public ?MoneyValue $amount = null,
        public ?string $amountFormula = null,
        public bool $amountNoCache = false,
        public array $cells = [],
    ) {
    }
}
