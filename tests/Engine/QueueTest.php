<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Engine;

use ConciliaRH\Core\Database;
use ConciliaRH\Engine\Worker\WorkerQueue;
use ConciliaRH\Tests\Support\EngineTestCase;
use PDOException;
use RuntimeException;

final class QueueTest extends EngineTestCase
{
    private const TYPES = ['DIAGNOSTICO'];

    private const CLAIMER_SCRIPT = <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];
$cfg = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
$db = new ConciliaRH\Core\Database($cfg['db'], $cfg['tz']);
$q = new ConciliaRH\Engine\Worker\WorkerQueue($db, $cfg['worker']);
$ids = [];
while (($job = $q->claim(['DIAGNOSTICO'])) !== null) {
    if (!$q->complete($job, ['por' => $cfg['n']])) {
        fwrite(STDERR, "complete devolvió false\n");
        exit(2);
    }
    $ids[] = $job->id;
}
echo json_encode($ids);
PHP;

    private function connect(): Database
    {
        return self::settings()->connect();
    }

    public function testClaimIsExclusiveAndSetsLease(): void
    {
        $jid = self::enqueue();
        $job = (new WorkerQueue($this->connect(), 'w1'))->claim(self::TYPES);
        self::assertNotNull($job);
        self::assertSame($jid, $job->id);
        self::assertSame(1, $job->attempts);
        self::assertNull((new WorkerQueue($this->connect(), 'w2'))->claim(self::TYPES));
        $row = self::jobRow($jid);
        self::assertSame('EN_EJECUCION', $row['estado']);
        self::assertSame('w1', $row['bloqueado_por']);
        self::assertSame($job->token, (string) $row['token_bloqueo']);
    }

    public function testConcurrentWorkersNeverProcessTheSameJob(): void
    {
        $ids = [];
        for ($i = 0; $i < 80; $i++) {
            $ids[] = self::enqueue();
        }
        $base = (string) tempnam(sys_get_temp_dir(), 'crh-claim-');
        $script = $base . '.php';
        file_put_contents($script, self::CLAIMER_SCRIPT);
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $settings = self::settings();
        $procs = [];
        try {
            for ($n = 0; $n < 8; $n++) {
                $arg = json_encode(['db' => $settings->db, 'tz' => $settings->timezone, 'worker' => "hilo-$n", 'n' => $n], JSON_THROW_ON_ERROR);
                $proc = proc_open([PHP_BINARY, $script, $autoload, $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($proc);
                $procs[] = [$proc, $pipes];
            }
            $processed = [];
            $errors = [];
            foreach ($procs as [$proc, $pipes]) {
                $out = (string) stream_get_contents($pipes[1]);
                $err = (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $code = proc_close($proc);
                if ($code !== 0) {
                    $errors[] = "código $code: $err";
                    continue;
                }
                foreach (json_decode($out, true, 512, JSON_THROW_ON_ERROR) as $id) {
                    $processed[] = (int) $id;
                }
            }
        } finally {
            @unlink($script);
            @unlink($base);
        }
        self::assertSame([], $errors);
        $counts = array_count_values($processed);
        $keys = array_keys($counts);
        sort($keys);
        sort($ids);
        self::assertSame($ids, $keys);
        self::assertSame(1, max($counts), 'Un trabajo fue procesado más de una vez');
        $rows = self::owner()->fetchAll('SELECT estado, intentos, count(*) AS n FROM procesos GROUP BY 1, 2');
        self::assertSame([['estado' => 'COMPLETADO', 'intentos' => 1, 'n' => 80]], $rows);
        $used = self::owner()->fetchValue('SELECT count(DISTINCT bloqueado_por) FILTER (WHERE bloqueado_por IS NOT NULL) FROM procesos');
        self::assertSame(0, $used);
    }

    public function testPriorityAvailabilityTypeAndCancelledFilters(): void
    {
        $low = self::enqueue(prioridad: 200);
        $high = self::enqueue(prioridad: 10);
        self::enqueue(delay: 3600);
        $other = self::enqueue('OTRO_TIPO');
        $cancelled = self::enqueue();
        self::owner()->execute("UPDATE procesos SET estado = 'CANCELADO' WHERE id = :id", ['id' => $cancelled]);
        $q = new WorkerQueue($this->connect(), 'w');
        $order = [];
        while (($job = $q->claim(self::TYPES)) !== null) {
            $order[] = $job->id;
            $q->complete($job, []);
        }
        self::assertSame([$high, $low], $order);
        self::assertSame('PENDIENTE', self::jobRow($other)['estado']);
        self::assertSame('CANCELADO', self::jobRow($cancelled)['estado']);
    }

    public function testClaimSpecificJob(): void
    {
        $a = self::enqueue();
        $b = self::enqueue();
        $job = (new WorkerQueue($this->connect(), 'w'))->claim(self::TYPES, $b);
        self::assertNotNull($job);
        self::assertSame($b, $job->id);
        self::assertSame('PENDIENTE', self::jobRow($a)['estado']);
    }

    public function testReaperRecoversAbandonedJobAndFencesTheZombie(): void
    {
        $jid = self::enqueue();
        $q = new WorkerQueue($this->connect(), 'zombi');
        $job = $q->claim(self::TYPES);
        self::assertNotNull($job);
        self::owner()->execute("UPDATE procesos SET heartbeat_at = now() - interval '1 hour' WHERE id = :id", ['id' => $jid]);
        $recovered = $q->reap(60);
        self::assertSame([$jid], array_map(static fn (array $r): int => (int) $r['id'], $recovered));
        $row = self::jobRow($jid);
        self::assertSame('PENDIENTE', $row['estado']);
        self::assertSame('WORKER_ABANDONADO', $row['error_codigo']);
        self::assertNull($row['token_bloqueo']);
        self::assertTrue(self::owner()->fetchValue('SELECT disponible_desde > now() FROM procesos WHERE id = :id', ['id' => $jid]));
        self::assertFalse($q->heartbeat($job));
        self::assertFalse($q->progress($job, 50));
        self::assertFalse($q->complete($job, ['tarde' => true]));
        self::assertNull($q->fail($job, 'X', 'x', true));
        self::assertNull(self::jobRow($jid)['resultado']);
    }

    public function testReaperMarksErrorWhenAttemptsExhausted(): void
    {
        $jid = self::enqueue(maxIntentos: 1);
        $q = new WorkerQueue($this->connect(), 'w');
        $q->claim(self::TYPES);
        self::owner()->execute("UPDATE procesos SET heartbeat_at = now() - interval '1 hour' WHERE id = :id", ['id' => $jid]);
        $q->reap(60);
        $row = self::jobRow($jid);
        self::assertSame('ERROR', $row['estado']);
        self::assertNotNull($row['finalizado_at']);
        self::assertSame(1, $row['intentos']);
    }

    public function testReaperEnforcesTimeoutEvenWithHeartbeat(): void
    {
        $jid = self::enqueue(timeout: 30);
        $q = new WorkerQueue($this->connect(), 'w');
        $q->claim(self::TYPES);
        self::owner()->execute("UPDATE procesos SET bloqueado_at = now() - interval '5 minutes', heartbeat_at = now() WHERE id = :id", ['id' => $jid]);
        $q->reap(60);
        self::assertSame('TIEMPO_EXCEDIDO', self::jobRow($jid)['error_codigo']);
    }

    public function testRetryIsBoundedWithExponentialBackoff(): void
    {
        $jid = self::enqueue(maxIntentos: 3);
        $q = new WorkerQueue($this->connect(), 'w');
        $states = [];
        for ($i = 0; $i < 3; $i++) {
            self::owner()->execute('UPDATE procesos SET disponible_desde = now() WHERE id = :id', ['id' => $jid]);
            $job = $q->claim(self::TYPES);
            self::assertNotNull($job);
            $states[] = $q->fail($job, 'FALLO_TRANSITORIO', 'Falló', true, 'cuenta 012345678901234567');
        }
        self::owner()->execute('UPDATE procesos SET disponible_desde = now() WHERE id = :id', ['id' => $jid]);
        self::assertNull($q->claim(self::TYPES), 'No debe reintentarse tras agotar max_intentos');
        self::assertSame(['PENDIENTE', 'PENDIENTE', 'ERROR'], $states);
        $row = self::jobRow($jid);
        self::assertSame(3, $row['intentos']);
        self::assertSame('ERROR', $row['estado']);
        self::assertStringNotContainsString('012345678901234567', (string) ($row['error_detalle'] ?? ''));
        self::assertSame(15, WorkerQueue::backoff(1));
        self::assertSame(30, WorkerQueue::backoff(2));
        self::assertSame(WorkerQueue::BACKOFF_MAX, WorkerQueue::backoff(20));
    }

    public function testPermanentErrorDoesNotRetry(): void
    {
        $jid = self::enqueue(maxIntentos: 5);
        $q = new WorkerQueue($this->connect(), 'w');
        $job = $q->claim(self::TYPES);
        self::assertNotNull($job);
        self::assertSame('ERROR', $q->fail($job, 'PARAMETROS_INVALIDOS', 'x', false));
        self::assertSame(1, self::jobRow($jid)['intentos']);
    }

    public function testCompleteInDomainTransactionRollsBackTogether(): void
    {
        $jid = self::enqueue();
        $db = $this->connect();
        $q = new WorkerQueue($db, 'w');
        $job = $q->claim(self::TYPES);
        self::assertNotNull($job);
        try {
            $db->transaction(static function () use ($q, $job): void {
                self::assertTrue($q->complete($job, ['ok' => true]));
                throw new RuntimeException('fallo al escribir datos de dominio');
            });
            self::fail('Se esperaba RuntimeException');
        } catch (RuntimeException $e) {
            self::assertSame('fallo al escribir datos de dominio', $e->getMessage());
        }
        self::assertSame('EN_EJECUCION', self::jobRow($jid)['estado']);
    }

    public function testDatabaseRejectsInconsistentLeaseAndInfiniteAttempts(): void
    {
        $jid = self::enqueue();
        $this->assertSqlState('23514', static fn () => self::owner()->execute("UPDATE procesos SET estado = 'EN_EJECUCION' WHERE id = :id", ['id' => $jid]));
        $this->assertSqlState('23514', static fn () => self::owner()->execute('UPDATE procesos SET intentos = 99 WHERE id = :id', ['id' => $jid]));
    }

    public function testWorkerRolePrivileges(): void
    {
        $db = $this->connect();
        foreach (['SELECT * FROM usuarios', 'SELECT * FROM sesiones', 'SELECT * FROM auditoria', 'DELETE FROM procesos',
                  "INSERT INTO procesos (tipo) VALUES ('DIAGNOSTICO')"] as $sql) {
            $this->assertSqlState('42501', static fn () => $db->execute($sql));
        }
    }

    public function testClaimClearsPreviousErrorFields(): void
    {
        $jid = self::enqueue();
        $q = new WorkerQueue($this->connect(), 'w');
        $job = $q->claim(self::TYPES);
        self::assertNotNull($job);
        $q->fail($job, 'FALLO_TRANSITORIO', 'Falló', true, 'x');
        self::owner()->execute('UPDATE procesos SET disponible_desde = now() WHERE id = :id', ['id' => $jid]);
        $q->claim(self::TYPES);
        $row = self::jobRow($jid);
        self::assertSame('EN_EJECUCION', $row['estado']);
        self::assertSame([null, null, null], [$row['error_codigo'], $row['error_mensaje'], $row['error_detalle']]);
        $msgs = array_column(self::owner()->fetchAll('SELECT mensaje FROM proceso_eventos WHERE proceso_id = :id', ['id' => $jid]), 'mensaje');
        self::assertNotEmpty(array_filter($msgs, static fn (string $m): bool => str_contains($m, 'FALLO_TRANSITORIO')));
    }

    private function assertSqlState(string $state, callable $fn): void
    {
        try {
            $fn();
        } catch (PDOException $e) {
            self::assertSame($state, (string) ($e->errorInfo[0] ?? $e->getCode()), $e->getMessage());
            return;
        }
        self::fail("Se esperaba SQLSTATE $state");
    }
}
