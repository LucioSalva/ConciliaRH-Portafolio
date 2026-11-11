<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Support;

final class SequenceMatcher
{
    /** @var list<string> */
    private array $a;
    /** @var list<string> */
    private array $b;
    /** @var array<string,list<int>> */
    private array $b2j = [];

    public function __construct(string $a, string $b)
    {
        $this->a = $a === '' ? [] : mb_str_split($a, 1, 'UTF-8');
        $this->b = $b === '' ? [] : mb_str_split($b, 1, 'UTF-8');
        foreach ($this->b as $j => $ch) {
            $this->b2j[$ch][] = $j;
        }
        $n = count($this->b);
        if ($n >= 200) {
            $ntest = intdiv($n, 100) + 1;
            foreach ($this->b2j as $ch => $idx) {
                if (count($idx) > $ntest) {
                    unset($this->b2j[$ch]);
                }
            }
        }
    }

    public static function ratioOf(string $a, string $b): float
    {
        return (new self($a, $b))->ratio();
    }

    public function ratio(): float
    {
        $total = count($this->a) + count($this->b);
        if ($total === 0) {
            return 1.0;
        }
        return 2.0 * $this->matches() / $total;
    }

    private function matches(): int
    {
        $sum = 0;
        $queue = [[0, count($this->a), 0, count($this->b)]];
        while ($queue !== []) {
            [$alo, $ahi, $blo, $bhi] = array_pop($queue);
            [$i, $j, $k] = $this->longest($alo, $ahi, $blo, $bhi);
            if ($k > 0) {
                $sum += $k;
                if ($alo < $i && $blo < $j) {
                    $queue[] = [$alo, $i, $blo, $j];
                }
                if ($i + $k < $ahi && $j + $k < $bhi) {
                    $queue[] = [$i + $k, $ahi, $j + $k, $bhi];
                }
            }
        }
        return $sum;
    }

    /** @return array{int,int,int} */
    private function longest(int $alo, int $ahi, int $blo, int $bhi): array
    {
        $a = $this->a;
        $b = $this->b;
        [$besti, $bestj, $bestsize] = [$alo, $blo, 0];
        $j2len = [];
        for ($i = $alo; $i < $ahi; $i++) {
            $new = [];
            foreach ($this->b2j[$a[$i]] ?? [] as $j) {
                if ($j < $blo) {
                    continue;
                }
                if ($j >= $bhi) {
                    break;
                }
                $k = $new[$j] = ($j2len[$j - 1] ?? 0) + 1;
                if ($k > $bestsize) {
                    [$besti, $bestj, $bestsize] = [$i - $k + 1, $j - $k + 1, $k];
                }
            }
            $j2len = $new;
        }
        while ($besti > $alo && $bestj > $blo && $a[$besti - 1] === $b[$bestj - 1]) {
            [$besti, $bestj, $bestsize] = [$besti - 1, $bestj - 1, $bestsize + 1];
        }
        while ($besti + $bestsize < $ahi && $bestj + $bestsize < $bhi && $a[$besti + $bestsize] === $b[$bestj + $bestsize]) {
            $bestsize++;
        }
        return [$besti, $bestj, $bestsize];
    }
}
