<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Worker;

use ConciliaRH\Core\Config;
use ConciliaRH\Core\Database;

final class WorkerSettings
{
    /**
     * @param array<string,mixed> $db
     * @param array<string,string> $hmacRing
     * @param array<string,string> $encRing
     */
    public function __construct(
        public readonly array $db,
        public readonly string $timezone,
        public readonly int $heartbeatTimeout,
        public readonly int $pollInterval,
        public readonly string $storagePath,
        public readonly string $appEnv,
        public readonly array $hmacRing,
        public readonly array $encRing,
        public readonly string $encKeyId,
        public readonly string $version,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        $db = [...(array) $config->get('db'), 'username' => (string) $config->get('worker.username'),
               'password' => (string) $config->get('worker.password'), 'application_name' => 'conciliarh_worker'];
        return new self(
            $db,
            (string) $config->get('app.timezone'),
            max(30, (int) $config->get('worker.heartbeat_timeout', 120)),
            min(60, max(1, (int) $config->get('worker.poll_interval', 5))),
            (string) $config->get('storage.path'),
            (string) $config->get('app.env'),
            Config::parseHmacRing((string) $config->get('app.hmac_keys'), (string) $config->get('app.hmac_key')) ?? [],
            Config::parseKeyRing((string) $config->get('app.enc_keys')) ?? [],
            (string) $config->get('app.enc_key_id', 'k1'),
            (string) $config->get('app.version'),
        );
    }

    /** @return list<string> */
    public function problems(): array
    {
        $out = [];
        if (!in_array($this->appEnv, ['development', 'testing', 'production'], true)) {
            $out[] = 'APP_ENV inválido';
        }
        if ((string) ($this->db['password'] ?? '') === '') {
            $out[] = 'WORKER_DB_PASSWORD vacío (rol conciliarh_worker)';
        }
        if (!isset($this->encRing[$this->encKeyId])) {
            $out[] = 'ACCOUNT_ENC_KEYS debe ser "id:64hex[,id:64hex]" e incluir ACCOUNT_ENC_KEY_ID';
        }
        if ($this->hmacRing === []) {
            $out[] = 'Falta ACCOUNT_HMAC_KEYS (o ACCOUNT_HMAC_KEY)';
        }
        if (!is_dir($this->storagePath)) {
            $out[] = 'STORAGE_PATH no existe o no es un directorio';
        }
        return $out;
    }

    public function connect(): Database
    {
        return new Database($this->db, $this->timezone);
    }
}
