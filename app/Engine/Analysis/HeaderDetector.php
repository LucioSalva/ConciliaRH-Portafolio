<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Analysis;

use ConciliaRH\Engine\Support\Py;
use ConciliaRH\Engine\Workbook\Sheet;

final class HeaderDetector
{
    private const MAX_SCAN = 30;

    public static function isText(mixed $v): bool
    {
        if (!Values::isText($v)) {
            return false;
        }
        if (Values::parseMoney(is_string($v) ? $v : $v)->ok()) {
            return false;
        }
        return Py::strip((string) $v) !== '';
    }

    public static function detect(Sheet $sheet): ?HeaderInfo
    {
        $rows = array_slice($sheet->rows, 0, self::MAX_SCAN);
        $width = 0;
        foreach ($rows as $r) {
            if ($r->cells !== []) {
                $width = max($width, max(array_keys($r->cells)));
            }
        }
        $width++;
        $fullMerges = [];
        foreach ($sheet->merges as $m) {
            if ($m[0] === $m[2] && ($m[3] - $m[1] + 1) >= max(3, $width - 1)) {
                $fullMerges[$m[0]] = true;
            }
        }
        $best = null;
        foreach ($rows as $pos => $row) {
            $vals = array_map(static fn ($c) => $c->value, array_values($row->cells));
            if (count($vals) < 2) {
                continue;
            }
            $texts = array_values(array_filter($vals, self::isText(...)));
            if (count($texts) < 2 || count($texts) / count($vals) < 0.6) {
                continue;
            }
            $known = 0;
            foreach ($texts as $t) {
                $h = Values::normHeader($t);
                if (Profiles::inVocab($h) || preg_match(Profiles::CONCEPT_HEADER, $h) === 1) {
                    $known++;
                }
            }
            $score = 5 * $known + count($texts);
            $next = $rows[$pos + 1] ?? null;
            if ($next !== null) {
                foreach ($next->cells as $c) {
                    if (Values::isNumber($c->value)) {
                        $score += 3;
                        break;
                    }
                }
            }
            if (isset($fullMerges[$row->index])) {
                $score -= 2;
            }
            if ($best === null || $score > $best[0]) {
                $best = [$score, $pos];
            }
        }
        if ($best === null) {
            return null;
        }
        [$score, $pos] = $best;
        $row = $rows[$pos];
        $cells = $row->cells;
        ksort($cells);
        $columns = [];
        foreach ($cells as $c => $cell) {
            $t = Values::normText($cell->value);
            if ($t !== null) {
                $columns[$c] = [Py::sub($t, 300), Values::normHeader($cell->value), Values::normHeader($cell->value)];
            }
        }
        $info = new HeaderInfo($row->index, 1, $columns, (float) $score);
        for ($i = 0; $i < $pos; $i++) {
            $txt = [];
            foreach ($rows[$i]->cells as $c) {
                if (self::isText($c->value)) {
                    $txt[] = Values::normHeader($c->value);
                }
            }
            if ($txt !== []) {
                $info->titleRows[] = $rows[$i]->index;
                array_push($info->titleText, ...$txt);
            }
        }
        if ($pos > 0 && $rows[$pos - 1]->index === $row->index - 1) {
            $above = $rows[$pos - 1];
            $used = false;
            foreach ($sheet->merges as [$r1, $c1, $r2, $c2]) {
                if ($r1 !== $above->index || $r2 !== $above->index || $c2 <= $c1) {
                    continue;
                }
                $group = $above->cells[$c1] ?? null;
                if ($group === null || !self::isText($group->value) || ($c2 - $c1 + 1) >= max(3, $width - 1)) {
                    continue;
                }
                for ($c = $c1; $c <= $c2; $c++) {
                    if (isset($info->columns[$c])) {
                        [$orig, $norm, $own] = $info->columns[$c];
                        $info->columns[$c] = [Py::sub(Values::normText($group->value) . ' / ' . $orig, 300),
                            Values::normHeader($group->value) . ' ' . $norm, $own];
                        $used = true;
                    }
                }
            }
            foreach ($sheet->merges as [$r1, $c1, $r2]) {
                if ($r1 !== $above->index || $r2 < $row->index) {
                    continue;
                }
                $cell = $above->cells[$c1] ?? null;
                if (!isset($info->columns[$c1]) && $cell !== null && self::isText($cell->value)) {
                    $info->columns[$c1] = [Py::sub((string) Values::normText($cell->value), 300), Values::normHeader($cell->value),
                        Values::normHeader($cell->value)];
                    $used = true;
                }
            }
            if ($used) {
                $info->rows = 2;
                $k = array_search($above->index, $info->titleRows, true);
                if ($k !== false) {
                    array_splice($info->titleRows, (int) $k, 1);
                }
            }
        }
        return $info;
    }
}
