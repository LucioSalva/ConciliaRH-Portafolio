<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Workbook;

final class Workbook
{
    /** @var list<Sheet> */
    public array $sheets = [];
    public bool $hasMacros = false;
    public int $externalLinks = 0;
    public int $ddeLinks = 0;
    public int $definedNames = 0;
    public int $suspiciousNames = 0;
    /** @var array<string,int> */
    public array $externalRelationships = [];
    public bool $date1904 = false;

    public function __construct(public string $format)
    {
    }
}
