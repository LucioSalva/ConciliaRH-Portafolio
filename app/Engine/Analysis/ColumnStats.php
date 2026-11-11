<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Analysis;

final class ColumnStats
{
    public function __construct(
        public readonly int $index,
        public readonly string $letter,
        public readonly ?string $headerOriginal,
        public readonly ?string $headerNormalized,
        public readonly ?string $canon,
        public readonly string $tipo,
        public readonly float $pctNull,
        public readonly float $pctText,
        public readonly float $pctNum,
        public readonly float $pctDate,
        public readonly ?string $identificador,
        public readonly bool $auxiliar,
    ) {
    }
}
