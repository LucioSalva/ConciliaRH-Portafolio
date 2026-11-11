<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Engine;

use ConciliaRH\Tests\Support\EngineTestCase;

final class SanityEngineTest extends EngineTestCase
{
    public function testDiagnosticJobRuns(): void
    {
        $id = self::enqueue('DIAGNOSTICO');
        self::assertSame(1, self::runOnce());
        self::assertSame('COMPLETADO', self::jobRow($id)['estado']);
    }
}
