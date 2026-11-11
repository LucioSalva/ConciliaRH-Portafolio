<?php

declare(strict_types=1);

namespace ConciliaRH\Core;

use PDO;
use PDOStatement;
use Throwable;

final class Database
{
    private ?PDO $pdo = null;
    private int $depth = 0;

    /** @param array{host:string,port:int,database:string,username:string,password:string,sslmode?:string,application_name?:string} $cfg */
    public function __construct(private readonly array $cfg, private readonly string $timezone = 'America/Mexico_City')
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;application_name=%s',
                $this->cfg['host'],
                $this->cfg['port'],
                $this->cfg['database'],
                $this->cfg['sslmode'] ?? 'prefer',
                $this->cfg['application_name'] ?? 'conciliarh'
            );
            $this->pdo = new PDO($dsn, $this->cfg['username'], $this->cfg['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            $this->pdo->exec("SET TIME ZONE " . $this->pdo->quote($this->timezone));
        }
        return $this->pdo;
    }

    /** @param array<string|int,mixed> $params */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            if (is_array($value)) {
                $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            }
            $stmt->bindValue($name, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /** @return list<array<string,mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $v = $this->query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    public function bindActor(?string $sessionToken): void
    {
        $this->query("SELECT set_config('conciliarh.actor_token', :t, false)", ['t' => $sessionToken ?? '']);
    }

    /**
     * @template T
     * @param callable(self):T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();
        if ($this->depth === 0 && !$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $savepoint = null;
        } else {
            $savepoint = 'sp_' . ($this->depth + 1);
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }
        $this->depth++;
        try {
            $result = $fn($this);
        } catch (Throwable $e) {
            $this->depth--;
            if ($savepoint === null) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            }
            throw $e;
        }
        $this->depth--;
        if ($savepoint === null) {
            $pdo->commit();
        } else {
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
        return $result;
    }
}
