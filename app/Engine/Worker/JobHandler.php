<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Worker;

interface JobHandler
{
    /** @return array<string,mixed> */
    public function run(JobContext $ctx): array;
}
