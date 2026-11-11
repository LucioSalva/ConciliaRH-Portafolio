<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Workbook;

final class Sheet
{
    /** @var list<Row> */
    public array $rows = [];
    public int $hiddenRows = 0;
    /** @var array<int,true> */
    public array $hiddenCols = [];
    public ?string $autofilter = null;
    public bool $filterMode = false;
    /** @var list<array{int,int,int,int}> */
    public array $merges = [];
    public ?string $dimension = null;
    public ?int $formulas = 0;
    public ?int $formulasWithoutCache = 0;
    public int $externalFormulas = 0;
    public int $ddeFormulas = 0;
    public int $cellsNonempty = 0;
    public int $externalHyperlinks = 0;

    public function __construct(public int $index, public string $name, public string $visibility = 'VISIBLE')
    {
    }
}
