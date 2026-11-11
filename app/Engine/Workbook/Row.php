<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Workbook;

final class Row
{
    /** @param array<int,Cell> $cells */
    public function __construct(public int $index, public bool $hidden, public array $cells = [])
    {
    }
}
