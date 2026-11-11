<?php

declare(strict_types=1);

namespace ConciliaRH\Core;

use RuntimeException;

/**
 * Migraciones SQL versionadas: database/migrations/NNNN_nombre.up.sql (+ .down.sql).
 *  - Cada migración corre en su propia transacción (DDL transaccional de PostgreSQL).
 *  - Un advisory lock impide ejecuciones concurrentes.
 *  - Se guarda el SHA-256 del archivo: si una migración ya aplicada cambia, se detiene.
 *  - down() sólo se permite fuera de producción.
 */
final class Migrator
{
    private const LOCK_KEY = 'conciliarh.migraciones';

    public function __construct(
        private readonly Database $db,
        private readonly string $dir,
        private readonly bool $production,
    ) {
    }

    public function ensureTable(): void
    {
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS schema_migraciones (
                version     text        PRIMARY KEY,
                nombre      text        NOT NULL,
                checksum    text        NOT NULL,
                aplicada_at timestamptz NOT NULL DEFAULT now(),
                aplicada_por text       NOT NULL DEFAULT current_user
            )'
        );
    }

    /** @return array<string,array{version:string,name:string,up:string,down:?string}> */
    public function available(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*.up.sql') ?: [] as $file) {
            if (!preg_match('/^(\d{4})_([a-z0-9_]+)\.up\.sql$/', basename($file), $m)) {
                throw new RuntimeException('Nombre de migración inválido: ' . basename($file));
            }
            $down = substr($file, 0, -7) . '.down.sql';
            $out[$m[1]] = ['version' => $m[1], 'name' => $m[2], 'up' => $file, 'down' => is_file($down) ? $down : null];
        }
        ksort($out);
        return $out;
    }

    /** @return array<string,array<string,mixed>> */
    public function applied(): array
    {
        $this->ensureTable();
        $rows = [];
        foreach ($this->db->fetchAll('SELECT * FROM schema_migraciones ORDER BY version') as $r) {
            $rows[$r['version']] = $r;
        }
        return $rows;
    }

    /** @return list<string> versiones aplicadas en esta ejecución */
    public function up(?callable $out = null): array
    {
        $this->lock();
        try {
            return $this->runUp($out);
        } finally {
            $this->unlock();
        }
    }

    /** @return list<string> */
    private function runUp(?callable $out): array
    {
        $done = [];
        $applied = $this->applied();
        foreach ($this->available() as $v => $m) {
            $sum = hash_file('sha256', $m['up']);
            if (isset($applied[$v])) {
                if ($applied[$v]['checksum'] !== $sum) {
                    throw new RuntimeException("La migración $v ya aplicada fue modificada (checksum distinto). Cree una migración nueva en lugar de editarla.");
                }
                continue;
            }
            $sql = (string) file_get_contents($m['up']);
            $this->db->transaction(function () use ($sql, $v, $m, $sum): void {
                $this->db->pdo()->exec($sql);
                $this->db->execute(
                    'INSERT INTO schema_migraciones (version, nombre, checksum) VALUES (:v, :n, :c)',
                    ['v' => $v, 'n' => $m['name'], 'c' => $sum]
                );
            });
            $done[] = $v;
            if ($out) {
                $out("  ✔ $v {$m['name']}");
            }
        }
        return $done;
    }

    /** @return list<string> versiones revertidas */
    public function down(int $steps = 1, ?callable $out = null): array
    {
        if ($this->production) {
            throw new RuntimeException('La reversión de migraciones está deshabilitada en producción.');
        }
        $this->lock();
        try {
            return $this->runDown($steps, $out);
        } finally {
            $this->unlock();
        }
    }

    /** @return list<string> */
    private function runDown(int $steps, ?callable $out): array
    {
        $available = $this->available();
        $applied = array_reverse(array_keys($this->applied()));
        $done = [];
        foreach (array_slice($applied, 0, max(1, $steps)) as $v) {
            $down = $available[$v]['down'] ?? null;
            if ($down === null) {
                throw new RuntimeException("La migración $v no tiene script de reversión.");
            }
            $sql = (string) file_get_contents($down);
            $this->db->transaction(function () use ($sql, $v): void {
                $this->db->pdo()->exec($sql);
                $this->db->execute('DELETE FROM schema_migraciones WHERE version = :v', ['v' => $v]);
            });
            $done[] = $v;
            if ($out) {
                $out("  ↩ $v");
            }
        }
        return $done;
    }

    private function lock(): void
    {
        $this->ensureTable();
        $this->db->execute('SELECT pg_advisory_lock(hashtext(:k))', ['k' => self::LOCK_KEY]);
    }

    private function unlock(): void
    {
        $this->db->execute('SELECT pg_advisory_unlock(hashtext(:k))', ['k' => self::LOCK_KEY]);
    }
}
