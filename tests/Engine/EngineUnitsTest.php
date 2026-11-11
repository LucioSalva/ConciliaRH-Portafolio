<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Engine;

use ConciliaRH\Core\Config;
use ConciliaRH\Core\Env;
use ConciliaRH\Core\Logger;
use ConciliaRH\Domain\Jobs\JobTypes;
use ConciliaRH\Domain\ValidationException;
use ConciliaRH\Engine\Worker\WorkerSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EngineUnitsTest extends TestCase
{
    private const ISOLATED_ENV = ['WORKER_POLL_INTERVAL', 'WORKER_HEARTBEAT_TIMEOUT', 'DB_PORT', 'WORKER_DB_USERNAME'];

    public function testParseEnv(): void
    {
        $vars = Env::parse("# c\nA=1\nB=\"x # y\"\nC=val # comentario\nlower=no\n");
        self::assertSame(['A' => '1', 'B' => 'x # y', 'C' => 'val'], $vars);
    }

    public function testSettingsBoundsAndDefaults(): void
    {
        $saved = [];
        foreach (self::ISOLATED_ENV as $key) {
            $saved[$key] = getenv($key);
            putenv($key);
        }
        try {
            $basePath = dirname(__DIR__, 2);
            $factory = require $basePath . '/config/app.php';
            $env = Env::fromArray(['WORKER_POLL_INTERVAL' => '999', 'WORKER_HEARTBEAT_TIMEOUT' => '5', 'DB_PORT' => 'x']);
            $s = WorkerSettings::fromConfig(new Config($factory($env, $basePath)));
        } finally {
            foreach ($saved as $key => $value) {
                putenv($value === false ? $key : "$key=$value");
            }
        }
        self::assertSame(60, $s->pollInterval);
        self::assertSame(30, $s->heartbeatTimeout);
        self::assertSame(5432, $s->db['port']);
        self::assertSame('conciliarh_worker', $s->db['username']);
        self::assertSame('conciliarh_worker', $s->db['application_name']);
    }

    public function testMaskingAndRedaction(): void
    {
        $text = Logger::maskText('CLABE 012345678901234567 cta 1234567890 CURP FICT850101HDFXXX09 RFC FICT850101AB1');
        self::assertStringNotContainsString('012345678901234567', $text);
        self::assertStringContainsString('**************4567', $text);
        self::assertStringContainsString('[CURP]', $text);
        self::assertStringContainsString('[RFC]', $text);
        self::assertSame(['password' => '[REDACTADO]', 'datos' => ['clabe' => '[REDACTADO]'], 'ok' => '5'],
            Logger::redact(['password' => 'x', 'datos' => ['clabe' => '1'], 'ok' => '5']));
    }

    public function testContractParamValidationAcceptsValidParams(): void
    {
        self::assertSame(['eco' => 'a', 'duracion_segundos' => 0], JobTypes::validate('DIAGNOSTICO', ['eco' => 'a', 'duracion_segundos' => 0]));
    }

    /** @return array<string,array{array<mixed>}> */
    public static function invalidDiagnosticParams(): array
    {
        return [
            'parametro desconocido' => [['x' => 1]],
            'duracion booleana' => [['duracion_segundos' => true]],
            'duracion excesiva' => [['duracion_segundos' => 61]],
            'eco no texto' => [['eco' => 5]],
            'no es objeto' => [['no', 'objeto']],
        ];
    }

    /** @param array<mixed> $params */
    #[DataProvider('invalidDiagnosticParams')]
    public function testContractParamValidationRejectsInvalidParams(array $params): void
    {
        $this->expectException(ValidationException::class);
        JobTypes::validate('DIAGNOSTICO', $params);
    }
}
