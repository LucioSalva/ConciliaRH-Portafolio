<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Worker;

use ConciliaRH\Engine\Support\DbError;
use ConciliaRH\Core\Database;
use ConciliaRH\Core\Logger;
use ConciliaRH\Core\Maintenance;
use ConciliaRH\Domain\Jobs\JobTypes;
use ConciliaRH\Domain\ValidationException;
use PDO;
use PDOException;
use Throwable;

final class Worker
{
    private const CHANNEL = 'conciliarh_procesos';
    private const REAP_INTERVAL = 30.0;

    public readonly string $workerId;
    private bool $stopping = false;
    private float $lastReap = -INF;
    private float $lastAlive = -INF;

    /** @param array<string,JobHandler> $handlers */
    public function __construct(
        private readonly WorkerSettings $settings,
        private readonly array $handlers,
        private readonly Logger $log,
        ?string $workerId = null,
    ) {
        $this->workerId = $workerId ?? sprintf('%s:%d:%s', gethostname() ?: 'host', getmypid(), bin2hex(random_bytes(4)));
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->handlers);
    }

    public function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        $stop = function (int $signal): void {
            $this->log->info('Señal recibida; se detendrá al terminar el trabajo actual', ['signal' => $signal], 'motor');
            $this->stopping = true;
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    public function run(bool $once = false, ?int $jobId = null): int
    {
        $db = $this->settings->connect();
        $queue = new WorkerQueue($db, $this->workerId);
        $processed = 0;
        $this->register($db);
        if (!$once) {
            $db->pdo()->exec('LISTEN ' . self::CHANNEL);
        }
        $this->log->info('Procesador iniciado', ['worker' => $this->workerId, 'tipos' => $this->types()], 'motor');
        try {
            while (!$this->stopping) {
                $this->maybeReap($queue);
                $this->alive($db, null);
                if (Maintenance::status($this->settings->storagePath) !== null) {
                    if ($once) {
                        break;
                    }
                    $this->sleep($db);
                    continue;
                }
                $job = $queue->claim($this->types(), $jobId);
                if ($job !== null) {
                    $this->alive($db, $job->id, true);
                    $this->execute($db, $queue, $job);
                    $processed++;
                    $this->alive($db, null, true);
                }
                if ($once) {
                    break;
                }
                if ($job === null) {
                    $this->sleep($db);
                }
            }
        } finally {
            try {
                $db->execute('UPDATE workers SET detenido_at = now(), proceso_actual_id = NULL WHERE id = :id', ['id' => $this->workerId]);
            } catch (Throwable) {
                $this->log->warning('No se pudo marcar el procesador como detenido', [], 'motor');
            }
            $this->log->info('Procesador detenido', ['worker' => $this->workerId, 'procesados' => $processed], 'motor');
        }
        return $processed;
    }

    public function reapOnce(): int
    {
        return count((new WorkerQueue($this->settings->connect(), $this->workerId))->reap($this->settings->heartbeatTimeout));
    }

    private function sleep(Database $db): void
    {
        $pdo = $db->pdo();
        if (method_exists($pdo, 'pgsqlGetNotify')) {
            $pdo->pgsqlGetNotify(PDO::FETCH_ASSOC, $this->settings->pollInterval * 1000);
            return;
        }
        sleep($this->settings->pollInterval);
    }

    private function maybeReap(WorkerQueue $queue): void
    {
        $now = JobContext::now();
        if ($now - $this->lastReap < self::REAP_INTERVAL) {
            return;
        }
        $this->lastReap = $now;
        foreach ($queue->reap($this->settings->heartbeatTimeout) as $r) {
            $this->log->warning('Trabajo recuperado por el reaper', $r, 'motor');
        }
    }

    private function register(Database $db): void
    {
        $db->execute(
            'INSERT INTO workers (id, hostname, pid, version, hmac_key_ids) VALUES (:id, :h, :p, :v, CAST(:k AS text[]))
             ON CONFLICT (id) DO UPDATE SET ultimo_latido_at = now(), detenido_at = NULL, hmac_key_ids = EXCLUDED.hmac_key_ids',
            ['id' => $this->workerId, 'h' => gethostname() ?: 'host', 'p' => getmypid(), 'v' => $this->settings->version,
             'k' => '{' . implode(',', array_keys($this->settings->hmacRing)) . '}']);
    }

    private function alive(Database $db, ?int $jobId, bool $force = false): void
    {
        $now = JobContext::now();
        if (!$force && $now - $this->lastAlive < 10) {
            return;
        }
        $this->lastAlive = $now;
        $db->execute('UPDATE workers SET ultimo_latido_at = now(), proceso_actual_id = :j WHERE id = :id', ['j' => $jobId, 'id' => $this->workerId]);
    }

    private function execute(Database $db, WorkerQueue $queue, Job $job): void
    {
        $meta = ['proceso_id' => $job->id, 'tipo' => $job->type, 'intento' => $job->attempts];
        $this->log->info('Procesando trabajo', $meta, 'motor');
        $ctx = new JobContext($job, $queue, $db, $this->settings, $this->workerId, JobContext::now() + $job->timeoutSeconds,
            max(5.0, $this->settings->heartbeatTimeout / 4));
        set_time_limit($job->timeoutSeconds + 60);
        try {
            if ($job->contractVersion !== JobTypes::CONTRACT_VERSION) {
                throw JobFailure::contract('Versión de contrato no soportada: ' . $job->contractVersion);
            }
            try {
                JobTypes::validate($job->type, $job->params);
            } catch (ValidationException $e) {
                throw JobFailure::invalidParameters($e->getMessage());
            }
            $result = $this->handlers[$job->type]->run($ctx);
            $ctx->check();
            $state = $result['_estado'] ?? 'COMPLETADO';
            unset($result['_estado']);
            if (!in_array($state, ['COMPLETADO', 'COMPLETADO_CON_INCIDENCIAS'], true)) {
                throw JobFailure::permanent('El trabajo devolvió un estado final inválido: ' . $state, 'RESULTADO_INVALIDO');
            }
            if ($queue->complete($job, $result, $state)) {
                $this->log->info('Trabajo completado', [...$meta, 'estado' => $state], 'motor');
            } else {
                $this->log->warning('No se confirmó el trabajo: el lease se perdió', $meta, 'motor');
            }
        } catch (LeaseLost) {
            $this->log->warning('Lease perdido; se abandona el trabajo sin escribir resultados', $meta, 'motor');
        } catch (JobFailure $e) {
            $state = $queue->fail($job, $e->failureCode, $e->userMessage, $e->retriable, $e->detail ?? ($e::class . ': ' . $e->getMessage()));
            $this->log->warning('Trabajo con error controlado', [...$meta, 'codigo' => $e->failureCode, 'nuevo_estado' => $state], 'motor');
        } catch (PDOException $e) {
            $sqlstate = (string) ($e->errorInfo[0] ?? $e->getCode());
            $state = $queue->fail($job, 'ERROR_INTERNO', 'Ocurrió un error inesperado al procesar el trabajo.', true,
                DbError::detail($e));
            $this->log->error('Error de base de datos en el trabajo', [...$meta, 'nuevo_estado' => $state, 'sqlstate' => $sqlstate], 'motor');
        } catch (Throwable $e) {
            $where = basename($e->getFile()) . ':' . $e->getLine();
            $state = $queue->fail($job, 'ERROR_INTERNO', 'Ocurrió un error inesperado al procesar el trabajo.', true, $e::class . ' en ' . $where);
            $this->log->error('Error inesperado en el trabajo', [...$meta, 'nuevo_estado' => $state, 'error' => $e::class, 'en' => $where], 'motor');
        } finally {
            $ctx->close();
            set_time_limit(0);
        }
    }
}
