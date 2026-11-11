<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Analysis;

final class HeaderInfo
{
    /** @var list<int> */
    public array $titleRows = [];
    /** @var list<string> */
    public array $titleText = [];

    /** @param array<int,array{0:string,1:string,2:string}> $columns */
    public function __construct(public int $rowIndex, public int $rows, public array $columns, public float $score = 0.0)
    {
    }

    /** @return array<int,array{0:string,1:string,2:string}> */
    public function sortedColumns(): array
    {
        $c = $this->columns;
        ksort($c);
        return $c;
    }
}
