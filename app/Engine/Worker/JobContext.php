<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Worker;

use ConciliaRH\Core\Database;
use Throwable;

final class JobContext
{
    private ?Database $side = null;
    private ?WorkerQueue $sideQueue = null;
    private float $lastBeat;
    private float $lastOk;
    private bool $leaseLost = false;

    public function __construct(
        public readonly Job $job,
        public readonly WorkerQueue $queue,
        public readonly Database $db,
        public readonly WorkerSettings $settings,
        public readonly string $workerId,
        private readonly float $deadline,
        private readonly float $beatInterval,
    ) {
        $this->lastBeat = $this->lastOk = self::now();
    }

    public static function now(): float
    {
        return hrtime(true) / 1e9;
    }

    /** @return array<string,mixed> */
    public function params(): array
    {
        return $this->job->params;
    }

    public function check(): void
    {
        if ($this->leaseLost) {
            throw new LeaseLost();
        }
        if (self::now() > $this->deadline) {
            throw JobFailure::timeout();
        }
        if (self::now() - $this->lastBeat >= $this->beatInterval) {
            $this->beat();
        }
    }

    public function progress(float $pct, ?string $stage = null, ?string $message = null): void
    {
        $this->check();
        try {
            $ok = $this->side()->progress($this->job, $pct, $stage, $message);
            $this->lastBeat = $this->lastOk = self::now();
        } catch (Throwable) {
            $this->dropSide();
            $ok = self::now() - $this->lastOk <= $this->settings->heartbeatTimeout / 2;
        }
        if (!$ok) {
            $this->leaseLost = true;
            throw new LeaseLost();
        }
    }

    public function close(): void
    {
        $this->dropSide();
    }

    private function beat(): void
    {
        $this->lastBeat = self::now();
        try {
            if (!$this->side()->heartbeat($this->job)) {
                $this->leaseLost = true;
                throw new LeaseLost();
            }
            $this->side?->execute('UPDATE workers SET ultimo_latido_at = now() WHERE id = :id', ['id' => $this->workerId]);
            $this->lastOk = self::now();
        } catch (LeaseLost $e) {
            throw $e;
        } catch (Throwable) {
            $this->dropSide();
            if (self::now() - $this->lastOk > $this->settings->heartbeatTimeout / 2) {
                $this->leaseLost = true;
                throw new LeaseLost();
            }
        }
    }

    private function side(): WorkerQueue
    {
        if ($this->sideQueue === null) {
            $this->side = $this->settings->connect();
            $this->sideQueue = new WorkerQueue($this->side, $this->workerId);
        }
        return $this->sideQueue;
    }

    private function dropSide(): void
    {
        $this->side = null;
        $this->sideQueue = null;
    }
}
