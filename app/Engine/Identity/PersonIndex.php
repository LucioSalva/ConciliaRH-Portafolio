<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Identity;

final class PersonIndex
{
    /** @var array<int,Person> */
    public array $people = [];
    /** @var array<string,int> */
    public array $byNum = [];
    /** @var array<string,array<int,true>> */
    public array $byRfc = [];
    /** @var array<string,array<int,true>> */
    public array $byCurp = [];
    /** @var array<string,array<int,true>> */
    public array $byName = [];
    /** @var array<string,array<int,true>> */
    public array $byToken = [];
    /** @var array<string,int> */
    public array $accounts = [];
    /** @var array<int,int> */
    public array $canon = [];
    /** @var array<int,array<int,true>> */
    public array $members = [];

    public function __construct(public float $fuzzyMin = Matcher::FUZZY_MIN)
    {
    }

    public static function accountKey(string $hmac, string $uso): string
    {
        return $hmac . '|' . $uso;
    }

    public function canonical(int $emp): int
    {
        return $this->canon[$emp] ?? $emp;
    }

    public function setAlias(int $alias, int $canonical): void
    {
        $this->canon[$alias] = $canonical;
        $this->members[$canonical] ??= [$canonical => true];
        $this->members[$canonical][$alias] = true;
        $p = $this->people[$alias] ?? null;
        if ($p !== null && $p->nameKey !== null && $p->nameKey !== '') {
            unset($this->byName[$p->nameKey][$alias]);
            foreach (self::tokens($p->nameKey) as $t) {
                unset($this->byToken[$t][$alias]);
            }
        }
    }

    /** @return list<Person> */
    public function group(int $emp): array
    {
        $c = $this->canonical($emp);
        $ids = array_keys($this->members[$c] ?? [$c => true]);
        sort($ids);
        $out = [];
        foreach ($ids as $i) {
            if (isset($this->people[$i])) {
                $out[] = $this->people[$i];
            }
        }
        return $out;
    }

    public function add(Person $p): void
    {
        $old = $this->people[$p->id] ?? null;
        if ($old !== null) {
            $this->drop($old);
        }
        $this->people[$p->id] = $p;
        $this->byNum[$p->numero] = $p->id;
        if ($p->rfc !== null && $p->rfc !== '' && $p->rfcValido) {
            $this->byRfc[$p->rfc][$p->id] = true;
        }
        if ($p->curp !== null && $p->curp !== '' && $p->curpValida) {
            $this->byCurp[$p->curp][$p->id] = true;
        }
        if ($p->nameKey !== null && $p->nameKey !== '') {
            $this->byName[$p->nameKey][$p->id] = true;
            foreach (self::tokens($p->nameKey) as $t) {
                $this->byToken[$t][$p->id] = true;
            }
        }
    }

    /** @return list<string> */
    private static function tokens(string $nameKey): array
    {
        $parts = explode(' ', $nameKey);
        return array_values(array_unique([$parts[0], $parts[count($parts) - 1]]));
    }

    private function drop(Person $p): void
    {
        if (($this->byNum[$p->numero] ?? null) === $p->id) {
            unset($this->byNum[$p->numero]);
        }
        if ($p->rfc !== null && $p->rfc !== '' && isset($this->byRfc[$p->rfc])) {
            unset($this->byRfc[$p->rfc][$p->id]);
        }
        if ($p->curp !== null && $p->curp !== '' && isset($this->byCurp[$p->curp])) {
            unset($this->byCurp[$p->curp][$p->id]);
        }
        if ($p->nameKey !== null && $p->nameKey !== '' && isset($this->byName[$p->nameKey])) {
            unset($this->byName[$p->nameKey][$p->id]);
        }
    }
}
