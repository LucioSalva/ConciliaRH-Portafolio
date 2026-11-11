<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Analysis;

final class MoneyValue
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $value = null,
        public readonly bool $negative = false,
        public readonly string $rawKind = '',
        public readonly ?string $raw = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->status === 'OK';
    }
}
