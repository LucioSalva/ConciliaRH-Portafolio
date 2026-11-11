<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Analysis;

final class Mapping
{
    /** @var array<int,string> */
    public array $cols = [];
    /** @var array<int,string> */
    public array $conceptCols = [];
    /** @var array<int,string> */
    public array $otherCols = [];
    /** @var list<int> */
    public array $duplicateCols = [];
    public float $reqCov = 0.0;
    public float $optCov = 0.0;
    public float $unknownRatio = 0.0;
    public float $score = 0.0;

    public function __construct(public Profile $profile)
    {
    }

    public function colOf(?string $canon): ?int
    {
        if ($canon === null) {
            return null;
        }
        foreach ($this->cols as $c => $f) {
            if ($f === $canon) {
                return $c;
            }
        }
        return null;
    }

    public function withProfile(Profile $p): self
    {
        $m = clone $this;
        $m->profile = $p;
        return $m;
    }

    /** @return array<int,true> */
    public function moneyCols(): array
    {
        $out = [];
        foreach ($this->cols as $c => $f) {
            if ($this->profile->field($f)?->kind === 'money') {
                $out[$c] = true;
            }
        }
        foreach (array_keys($this->conceptCols) as $c) {
            $out[$c] = true;
        }
        return $out;
    }
}
