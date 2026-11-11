<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Files;

final class Verdict
{
    /** @param array<string,mixed> $metrics */
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $code,
        public readonly ?string $message,
        public readonly bool $hasMacros,
        public readonly array $metrics,
    ) {
    }

    /** @param array<string,mixed> $metrics */
    public static function ok(bool $macros, array $metrics): self
    {
        return new self(true, null, null, $macros, $metrics);
    }

    /** @param array<string,mixed> $metrics */
    public static function fail(string $code, string $message, array $metrics = []): self
    {
        return new self(false, $code, $message, false, $metrics);
    }
}
