<?php

declare(strict_types=1);

namespace ConciliaRH\Core;

/** Paginación del lado del servidor. */
final class Paginator
{
    public readonly int $pages;

    /** @param array<string,mixed> $query */
    public function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total,
        private readonly string $path,
        private readonly array $query,
    ) {
        $this->pages = max(1, (int) ceil($total / max(1, $perPage)));
    }

    public static function pageFrom(Request $request): int
    {
        $p = $request->query('page', '1');
        return is_string($p) && preg_match('/^[1-9]\d{0,5}$/', $p) ? (int) $p : 1;
    }

    public function url(int $page): string
    {
        $q = array_filter($this->query, static fn ($v) => is_scalar($v) && $v !== '');
        $q['page'] = $page;
        return $this->path . '?' . http_build_query($q);
    }

    public function from(): int
    {
        return $this->total === 0 ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->page * $this->perPage);
    }

    /** @return list<int|null> números de página con null como separador */
    public function window(): array
    {
        $out = [];
        $prev = 0;
        for ($i = 1; $i <= $this->pages; $i++) {
            if ($i === 1 || $i === $this->pages || abs($i - $this->page) <= 2) {
                if ($prev && $i - $prev > 1) {
                    $out[] = null;
                }
                $out[] = $i;
                $prev = $i;
            }
        }
        return $out;
    }
}
