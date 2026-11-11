<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Worker;

final class Job
{
    /** @param array<string,mixed> $params */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly int $contractVersion,
        public readonly array $params,
        public readonly int $attempts,
        public readonly int $maxAttempts,
        public readonly int $timeoutSeconds,
        public readonly string $token,
    ) {
    }
}
