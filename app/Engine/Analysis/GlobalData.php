<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Analysis;

final class GlobalData
{
    /**
     * @param list<array{block:string,row:int,code:?string,description:mixed,amount:MoneyValue}> $lines
     * @param array<string,array{0:MoneyValue,1:int}> $footer
     */
    public function __construct(public int $headerRow, public array $lines, public array $footer, public float $score)
    {
    }
}
