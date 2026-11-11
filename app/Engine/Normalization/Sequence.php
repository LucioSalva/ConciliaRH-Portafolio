<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Normalization;

use ConciliaRH\Core\Database;

final class Sequence
{
    /** @var list<int> */
    private array $free = [];

    public function __construct(private readonly Database $db, private readonly string $table, private readonly int $block = 200)
    {
    }

    /** @return list<int> */
    public static function ids(Database $db, string $table, int $n): array
    {
        if ($n === 0) {
            return [];
        }
        return array_map(static fn ($r) => (int) $r['id'], $db->fetchAll(
            "SELECT nextval(pg_get_serial_sequence(:t, 'id')) AS id FROM generate_series(1, :n)", ['t' => $table, 'n' => $n]));
    }

    public function take(): int
    {
        if ($this->free === []) {
            $this->free = array_reverse(self::ids($this->db, $this->table, $this->block));
        }
        return (int) array_pop($this->free);
    }
}
