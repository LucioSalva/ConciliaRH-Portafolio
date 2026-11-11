<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Engine;

use Closure;
use ConciliaRH\Core\Logger;
use ConciliaRH\Engine\Support\Json;
use ConciliaRH\Engine\Worker\JobContext;
use ConciliaRH\Engine\Worker\JobHandler;
use ConciliaRH\Engine\Worker\Worker;
use ConciliaRH\Engine\Worker\WorkerQueue;
use ConciliaRH\Engine\Worker\WorkerSettings;
use ConciliaRH\Tests\Support\EngineTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ValueError;

final class WorkerTest extends EngineTestCase
{
    private static function handler(Closure $fn): JobHandler
    {
        return new class ($fn) implements JobHandler {
            public function __construct(private readonly Closure $fn)
            {
            }

            public function run(JobContext $ctx): array
            {
                return ($this->fn)($ctx);
            }
        };
    }

    private static function workerWith(Closure $fn, string $id, ?WorkerSettings $settings = null): Worker
    {
        return new Worker($settings ?? self::settings(), ['DIAGNOSTICO' => self::handler($fn)], new Logger(self::storage() . '/logs'), $id);
    }

    /** @return array<string,mixed> */
    private static function jobResult(int $jobId): array
    {
        $raw = self::jobRow($jobId)['resultado'];
        return is_string($raw) ? (array) Json::decode($raw) : [];
    }

    public function testDiagnosticJobEndToEnd(): void
    {
        $jid = self::enqueue(params: ['eco' => 'hola']);
        self::assertSame(1, self::worker(null, 'phpunit-e2e')->run(true));
        $row = self::jobRow($jid);
        self::assertSame('COMPLETADO', $row['estado']);
        self::assertSame(0, bccomp((string) $row['progreso'], '100', 2));
        $res = self::jobResult($jid);
        self::assertSame('hola', $res['eco']);
        self::assertSame(1, $res['contract_version']);
        self::assertSame(['existe' => true, 'escritura_temporal' => true], $res['almacenamiento']);
        self::assertNotEmpty($res['postgresql']);
        $events = array_column(self::owner()->fetchAll('SELECT mensaje FROM proceso_eventos WHERE proceso_id = :id ORDER BY id', ['id' => $jid]), 'mensaje');
        self::assertStringStartsWith('Tomado por el procesador', $events[0]);
        self::assertSame('Terminado: COMPLETADO', $events[count($events) - 1]);
        $w = self::owner()->fetchOne("SELECT * FROM workers WHERE id = 'phpunit-e2e'");
        self::assertNotNull($w);
        self::assertNotNull($w['detenido_at']);
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function invalidParams(): array
    {
        return [
            'ruta' => [['ruta' => '/etc/passwd']],
            'duracion excesiva' => [['duracion_segundos' => 999]],
            'duracion texto' => [['duracion_segundos' => '5']],
            'eco largo' => [['eco' => str_repeat('x', 101)]],
        ];
    }

    /** @param array<string,mixed> $params */
    #[DataProvider('invalidParams')]
    public function testInvalidParametersFailPermanently(array $params): void
    {
        $jid = self::enqueue(params: $params, maxIntentos: 3);
        self::worker(null, 'phpunit-params')->run(true);
        $row = self::jobRow($jid);
        self::assertSame(['ERROR', 'PARAMETROS_INVALIDOS', 1], [$row['estado'], $row['error_codigo'], $row['intentos']]);
    }

    public function testUnsupportedContractVersionFailsPermanently(): void
    {
        $jid = self::enqueue(contract: 2);
        self::worker(null, 'phpunit-contract')->run(true);
        self::assertSame('CONTRATO_INVALIDO', self::jobRow($jid)['error_codigo']);
    }

    public function testUnexpectedExceptionIsRetriedThenErrorsWithoutLeaking(): void
    {
        $jid = self::enqueue(maxIntentos: 2);
        $w = self::workerWith(static function (JobContext $ctx): array {
            throw new ValueError('fallo con cuenta 012345678901234567 y CURP FICT850101HDFXXX09');
        }, 'phpunit-boom');
        $w->run(true);
        $first = self::jobRow($jid);
        self::assertSame('PENDIENTE', $first['estado']);
        self::assertSame('ERROR_INTERNO', $first['error_codigo']);
        self::assertSame('Ocurrió un error inesperado al procesar el trabajo.', $first['error_mensaje']);
        self::assertStringNotContainsString('012345678901234567', (string) $first['error_detalle']);
        self::assertStringNotContainsString('FICT850101HDFXXX09', (string) $first['error_detalle']);
        self::owner()->execute('UPDATE procesos SET disponible_desde = now() WHERE id = :id', ['id' => $jid]);
        $w->run(true);
        self::assertSame('ERROR', self::jobRow($jid)['estado']);
    }

    public function testLostLeaseStopsHandlerWithoutWritingResults(): void
    {
        $jid = self::enqueue();
        $started = false;
        $w = self::workerWith(static function (JobContext $ctx) use (&$started, $jid): array {
            $started = true;
            for ($i = 0; $i < 50; $i++) {
                usleep(100_000);
                if ($i === 0) {
                    self::owner()->execute("UPDATE procesos SET heartbeat_at = now() - interval '1 hour' WHERE id = :id", ['id' => $jid]);
                    (new WorkerQueue(self::settings()->connect(), 'reaper'))->reap(60);
                }
                $ctx->progress($i);
            }
            return ['no' => 'debe guardarse'];
        }, 'phpunit-lease');
        $w->run(true);
        self::assertTrue($started);
        $row = self::jobRow($jid);
        self::assertSame('PENDIENTE', $row['estado']);
        self::assertNull($row['resultado']);
        self::assertSame('WORKER_ABANDONADO', $row['error_codigo']);
    }

    public function testHandlerTimeoutIsEnforced(): void
    {
        $jid = self::enqueue(maxIntentos: 1, timeout: 10);
        self::workerWith(static function (JobContext $ctx): array {
            usleep(10_200_000);
            $ctx->progress(10);
            return [];
        }, 'phpunit-timeout')->run(true);
        $row = self::jobRow($jid);
        self::assertSame(['ERROR', 'TIEMPO_EXCEDIDO'], [$row['estado'], $row['error_codigo']]);
    }

    public function testInvalidFinalStateFromHandlerIsPermanent(): void
    {
        $jid = self::enqueue(maxIntentos: 3);
        self::workerWith(static fn (JobContext $ctx): array => ['_estado' => 'INVENTADO'], 'phpunit-estado')->run(true);
        $row = self::jobRow($jid);
        self::assertSame(['ERROR', 'RESULTADO_INVALIDO', 1], [$row['estado'], $row['error_codigo'], $row['intentos']]);
    }

    public function testHeartbeatKeepsWorkerAliveDuringLongJobs(): void
    {
        $observed = [];
        self::enqueue();
        self::workerWith(static function (JobContext $ctx) use (&$observed): array {
            self::owner()->execute("UPDATE workers SET ultimo_latido_at = now() - interval '1 hour' WHERE id = 'phpunit-hb'");
            usleep(5_500_000);
            $ctx->check();
            $observed['vivo'] = self::owner()->fetchValue(
                "SELECT ultimo_latido_at > now() - interval '1 minute' FROM workers WHERE id = 'phpunit-hb'");
            return [];
        }, 'phpunit-hb', self::settings(['heartbeatTimeout' => 20]))->run(true);
        self::assertTrue($observed['vivo'] ?? false, 'El latido debe actualizar workers.ultimo_latido_at mientras el trabajo corre');
    }
}
