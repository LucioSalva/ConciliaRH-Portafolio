<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Workbook;

final class ErrorValue
{
    public function __construct(public readonly string $text)
    {
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
