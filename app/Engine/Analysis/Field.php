<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Analysis;

final class Field
{
    /** @param list<string> $headers */
    public function __construct(
        public readonly string $canon,
        public readonly array $headers,
        public readonly string $kind = 'text',
        public readonly bool $huella = true,
    ) {
    }
}
