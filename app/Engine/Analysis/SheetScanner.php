<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Analysis;

use ConciliaRH\Engine\Support\Py;
use ConciliaRH\Engine\Workbook\Coordinates;
use ConciliaRH\Engine\Workbook\ErrorValue;
use ConciliaRH\Engine\Workbook\Sheet;

final class SheetScanner
{
    public const AGG_FORMULA = '/^[' . Py::WS . ']*\+?[' . Py::WS . ']*(?:_XLFN\.)?(SUM|SUBTOTAL|AGGREGATE|SUMA)[' . Py::WS . ']*\(/iu';
    private const TOTAL_WORDS = ['TOTAL', 'TOTALES', 'SUMA', 'SUMAS', 'SUBTOTAL', 'GRAN TOTAL', 'TOTAL GENERAL', 'SUMAS IGUALES', 'TOTAL NETO'];
    private const RANGE = '/\$?([A-Z]{1,3})\$?(\d+):\$?([A-Z]{1,3})\$?(\d+)/';
    private const SUBTOTAL_FN = '/^[' . Py::WS . ']*\+?[' . Py::WS . ']*(?:_XLFN\.)?(SUBTOTAL|AGGREGATE)[' . Py::WS . ']*\([' . Py::WS . ']*(\d+)/iu';
    private const GLOBAL_FOOTER = ['TOTAL PERCEPCIONES' => 'TOTAL_PERCEPCIONES', 'TOTAL DEDUCCIONES' => 'TOTAL_DEDUCCIONES', 'NETO' => 'NETO',
                                   'CANTIDAD DE EMPLEADOS' => 'CANTIDAD_EMPLEADOS'];

    public static function isTotalWord(mixed $v): bool
    {
        if (!Values::isText($v)) {
            return false;
        }
        $k = Values::normKey($v);
        return in_array($k, self::TOTAL_WORDS, true) || str_starts_with($k, 'TOTAL ') || str_starts_with($k, 'SUMA ')
            || str_starts_with($k, 'SUMAS ') || str_starts_with($k, 'SUBTOTAL');
    }

    public static function verticalAggregate(?string $formula): bool
    {
        if ($formula === null || $formula === '' || preg_match(self::AGG_FORMULA, $formula) !== 1) {
            return false;
        }
        if (preg_match(self::SUBTOTAL_FN, $formula, $m) === 1 && !in_array($m[2], ['9', '109'], true)) {
            return false;
        }
        preg_match_all(self::RANGE, Py::upper($formula), $all, PREG_SET_ORDER);
        foreach ($all as $m) {
            if ($m[2] !== $m[4]) {
                return true;
            }
        }
        return false;
    }

    public static function matchTabular(HeaderInfo $header, Profile $profile): Mapping
    {
        $m = new Mapping($profile);
        $taken = [];
        foreach ($header->sortedColumns() as $col => [, $norm, $own]) {
            $fld = null;
            foreach ($profile->fields as $f) {
                if (in_array($norm, $f->headers, true)) {
                    $fld = $f;
                    break;
                }
            }
            if ($fld === null) {
                foreach ($profile->fields as $f) {
                    if (in_array($own, $f->headers, true)) {
                        $fld = $f;
                        break;
                    }
                }
            }
            if ($fld !== null) {
                if (isset($taken[$fld->canon])) {
                    $m->duplicateCols[] = $col;
                    $m->otherCols[$col] = $norm;
                } else {
                    $m->cols[$col] = $fld->canon;
                    $taken[$fld->canon] = true;
                }
                continue;
            }
            if ($profile->conceptColumns && preg_match(Profiles::CONCEPT_HEADER, $own, $cm) === 1) {
                if (in_array($cm[1], $m->conceptCols, true)) {
                    $m->duplicateCols[] = $col;
                    $m->otherCols[$col] = $norm;
                } else {
                    $m->conceptCols[$col] = $cm[1];
                }
                continue;
            }
            $m->otherCols[$col] = $norm;
        }
        $req = array_unique($profile->required);
        $all = array_unique(array_map(static fn (Field $f) => $f->canon, $profile->fields));
        $opt = array_values(array_diff($all, $req));
        $m->reqCov = $req !== [] ? count(array_filter($req, static fn ($r) => isset($taken[$r]))) / count($req) : 0.0;
        $m->optCov = $opt !== [] ? count(array_filter($opt, static fn ($r) => isset($taken[$r]))) / count($opt) : 1.0;
        $total = count($header->columns) ?: 1;
        $m->unknownRatio = count($m->otherCols) / $total;
        $m->score = Py::round(60 * $m->reqCov + 20 * $m->optCov + 20 * (1 - $m->unknownRatio), 1);
        return $m;
    }

    /** @return list<RowInfo> */
    public static function classifyRows(Sheet $sheet, HeaderInfo $header, Mapping $mapping): array
    {
        $prof = $mapping->profile;
        $idCols = [];
        foreach ($mapping->cols as $c => $f) {
            if (in_array($f, $prof->identifiers, true)) {
                $idCols[] = $c;
            }
        }
        $amountCol = $prof->amount !== null ? $mapping->colOf($prof->amount) : null;
        $moneyCols = $mapping->moneyCols();
        $headerNames = [];
        foreach ($header->columns as [, $norm]) {
            $headerNames[$norm] = true;
        }
        $codeCols = [];
        foreach ($mapping->cols as $c => $f) {
            if ($f === 'CUENTA' || $f === 'SCTA') {
                $codeCols[] = $c;
            }
        }
        $out = [];
        foreach ($sheet->rows as $row) {
            if ($row->index <= $header->rowIndex) {
                continue;
            }
            $cells = $row->cells;
            $inTable = false;
            foreach (array_keys($cells) as $c) {
                if (isset($header->columns[$c])) {
                    $inTable = true;
                    break;
                }
            }
            if (!$inTable) {
                $out[] = new RowInfo($row->index, $row->hidden, 'AUXILIAR', 'FUERA_DE_TABLA', cells: $cells);
                continue;
            }
            $texts = [];
            foreach ($cells as $cell) {
                if (Values::isText($cell->value)) {
                    $texts[Values::normHeader($cell->value)] = true;
                }
            }
            $common = count(array_intersect_key($texts, $headerNames));
            if (count($headerNames) >= 3 && $common >= 0.6 * count($headerNames)) {
                $out[] = new RowInfo($row->index, $row->hidden, 'AUXILIAR', 'ENCABEZADO_REPETIDO', cells: $cells);
                continue;
            }
            $keyword = false;
            foreach ($cells as $cell) {
                if (self::isTotalWord($cell->value)) {
                    $keyword = true;
                    break;
                }
            }
            $idsPresent = false;
            foreach ($idCols as $c) {
                if (isset($cells[$c])) {
                    $v = $cells[$c]->value;
                    if (!Values::isBlank($v) && !self::isTotalWord($v) && !$v instanceof ErrorValue) {
                        $idsPresent = true;
                        break;
                    }
                }
            }
            $amtCell = $amountCol !== null ? ($cells[$amountCol] ?? null) : null;
            $amount = $amtCell !== null ? Values::parseMoney($amtCell->value) : new MoneyValue('VACIO');
            $formula = $amtCell?->formula;
            $noCache = $amtCell !== null && $formula !== null && $amtCell->value === null;
            $vertical = [];
            $anyMoneyFormula = false;
            foreach ($cells as $c => $cell) {
                if (isset($moneyCols[$c])) {
                    if (self::verticalAggregate($cell->formula)) {
                        $vertical[] = $cell;
                    }
                    if ($cell->formula !== null) {
                        $anyMoneyFormula = true;
                    }
                }
            }
            $moneyOk = false;
            $moneyPresent = false;
            foreach (array_keys($moneyCols) as $c) {
                if (isset($cells[$c])) {
                    $moneyOk = $moneyOk || Values::parseMoney($cells[$c]->value)->ok();
                    $moneyPresent = $moneyPresent || !Values::isBlank($cells[$c]->value);
                }
            }
            $info = new RowInfo($row->index, $row->hidden, 'AUXILIAR', '', $amount, $formula, $noCache, $cells);
            if ($vertical !== []) {
                $info->kind = 'TOTAL';
                $info->subkind = 'SUMA';
                foreach ($vertical as $cell) {
                    if (preg_match('/^[' . Py::WS . ']*\+?[' . Py::WS . ']*SUBTOTAL/iu', (string) $cell->formula) === 1) {
                        $info->subkind = 'SUBTOTAL';
                        break;
                    }
                }
            } elseif ($idsPresent) {
                $accounting = false;
                foreach ($codeCols as $c) {
                    if (isset($cells[$c]) && preg_match('/^\d+$/Du', (string) (Values::identifier($cells[$c]->value) ?? '')) === 1) {
                        $accounting = true;
                        break;
                    }
                }
                if ($prof->detailRequiresAmount && !$moneyOk && !($moneyPresent && $accounting)) {
                    $info->subkind = 'SIN_IMPORTES';
                } else {
                    $info->kind = 'DETALLE';
                }
            } elseif ($anyMoneyFormula && !$keyword) {
                $info->subkind = 'CALCULO';
            } elseif (($amountCol !== null && $amount->ok()) || ($amountCol === null && $moneyOk)) {
                $info->kind = 'TOTAL';
                $info->subkind = $keyword ? 'PALABRA_CLAVE' : 'CONSTANTE';
            } elseif ($keyword && $amount->status === 'INVALIDO') {
                $info->kind = 'TOTAL';
                $info->subkind = 'PALABRA_CLAVE';
            } else {
                $anyFormula = false;
                foreach ($cells as $cell) {
                    if ($cell->formula !== null && $cell->formula !== '') {
                        $anyFormula = true;
                        break;
                    }
                }
                $info->subkind = $anyFormula ? 'CALCULO' : 'TEXTO';
            }
            $out[] = $info;
        }
        return $out;
    }

    /**
     * @param list<RowInfo> $rows
     * @return list<ColumnStats>
     */
    public static function columnStats(HeaderInfo $header, ?Mapping $mapping, array $rows): array
    {
        $detail = array_values(array_filter($rows, static fn (RowInfo $r) => $r->kind === 'DETALLE'));
        $n = count($detail) ?: 1;
        $limit = ($header->columns !== [] ? max(array_keys($header->columns)) : 0) + 5;
        $cols = [];
        foreach (array_keys($header->columns) as $c) {
            $cols[$c] = true;
        }
        foreach ($detail as $r) {
            foreach (array_keys($r->cells) as $c) {
                if ($c <= $limit) {
                    $cols[$c] = true;
                }
            }
        }
        ksort($cols);
        $out = [];
        foreach (array_keys($cols) as $c) {
            [$orig, $norm] = isset($header->columns[$c]) ? [$header->columns[$c][0], $header->columns[$c][1]] : [null, null];
            if ($orig !== null && self::looksPersonal($orig)) {
                [$orig, $norm] = ['[dato enmascarado]', 'DATO ENMASCARADO'];
            }
            $canon = null;
            if ($mapping !== null) {
                $canon = $mapping->cols[$c] ?? (isset($mapping->conceptCols[$c]) ? 'CONCEPTO_' . $mapping->conceptCols[$c] : null);
            }
            $nulls = $texts = $nums = $dates = $bools = 0;
            $sample = [];
            foreach ($detail as $r) {
                $cell = $r->cells[$c] ?? null;
                $v = $cell?->value;
                if (Values::isBlank($v) || $v instanceof ErrorValue) {
                    $nulls++;
                } elseif (is_bool($v)) {
                    $bools++;
                } elseif ($cell !== null && $cell->isDate) {
                    $dates++;
                } elseif (Values::isNumber($v)) {
                    $nums++;
                    if (count($sample) < 200) {
                        $sample[] = Values::identifier($v) ?? '';
                    }
                } else {
                    if (Values::parseMoney($v)->ok() && preg_match('/^0\d+$/Du', Py::strip(Py::str($v))) !== 1) {
                        $nums++;
                    } else {
                        $texts++;
                    }
                    if (count($sample) < 200) {
                        $sample[] = Values::identifier($v) ?? '';
                    }
                }
            }
            $filled = count($detail) - $nulls;
            if ($filled === 0) {
                $tipo = 'VACIA';
            } elseif ($nums / $filled >= 0.95) {
                $fld = ($mapping !== null && $canon !== null) ? $mapping->profile->field($canon) : null;
                $tipo = ($fld?->kind === 'money') || str_starts_with((string) $canon, 'CONCEPTO_') ? 'MONEDA' : 'NUMERO';
            } elseif ($dates / $filled >= 0.95) {
                $tipo = 'FECHA';
            } elseif ($texts / $filled >= 0.95) {
                $tipo = 'TEXTO';
            } elseif ($bools / $filled >= 0.95) {
                $tipo = 'BOOLEANO';
            } else {
                $tipo = 'MIXTO';
            }
            $ident = null;
            if ($sample !== []) {
                foreach (['CURP', 'RFC', 'CLABE', 'CUENTA'] as $kind) {
                    $hits = count(array_filter($sample, static fn ($s) => Values::looksLike($kind, $s)));
                    if ($hits / count($sample) >= 0.9) {
                        $ident = $kind;
                        break;
                    }
                }
                if ($ident === null && $canon === 'NUM_EMPLEADO'
                    && count(array_filter($sample, static fn ($s) => Values::looksLike('NUM_EMPLEADO', $s))) / count($sample) >= 0.9) {
                    $ident = 'NUM_EMPLEADO';
                }
            }
            $out[] = new ColumnStats($c, Coordinates::letter($c), $orig, $norm, $canon, $tipo, Py::round(100 * $nulls / $n, 2),
                Py::round(100 * $texts / $n, 2), Py::round(100 * $nums / $n, 2), Py::round(100 * $dates / $n, 2), $ident,
                !isset($header->columns[$c]) && $filled > 0);
        }
        return $out;
    }

    public static function matchGlobal(Sheet $sheet): ?GlobalData
    {
        $hdr = null;
        foreach (array_slice($sheet->rows, 0, 40) as $row) {
            $keys = [];
            foreach ($row->cells as $col => $c) {
                if (Values::isText($c->value)) {
                    $keys[Values::normKey($c->value)] = $col;
                }
            }
            if (isset($keys['PERCEPCIONES'], $keys['DEDUCCIONES'])) {
                $hdr = [$row->index, $keys['PERCEPCIONES'], $keys['DEDUCCIONES']];
                break;
            }
        }
        if ($hdr === null) {
            return null;
        }
        [$hrow, $cp, $cd] = $hdr;
        $lines = [];
        $footer = [];
        foreach ($sheet->rows as $row) {
            if ($row->index <= $hrow) {
                continue;
            }
            $labels = false;
            $sorted = $row->cells;
            ksort($sorted);
            foreach ($sorted as $col => $c) {
                if (!Values::isText($c->value)) {
                    continue;
                }
                $code = self::GLOBAL_FOOTER[Values::normKey($c->value)] ?? null;
                if ($code === null) {
                    continue;
                }
                $labels = true;
                $next = null;
                foreach ($sorted as $k => $x) {
                    if ($k > $col && !Values::isBlank($x->value)) {
                        $next = $x;
                        break;
                    }
                }
                $footer[$code] ??= [$next !== null ? Values::parseMoney($next->value) : new MoneyValue('VACIO'), $row->index];
            }
            if ($labels) {
                continue;
            }
            foreach ([['PERCEPCIONES', $cp], ['DEDUCCIONES', $cd]] as [$block, $start]) {
                $desc = $row->cells[$start + 1] ?? null;
                $amt = $row->cells[$start + 2] ?? null;
                if ($desc === null || $amt === null || !Values::isText($desc->value)) {
                    continue;
                }
                $codeCell = $row->cells[$start] ?? null;
                $lines[] = ['block' => $block, 'row' => $row->index, 'code' => $codeCell !== null ? Values::identifier($codeCell->value) : null,
                            'description' => $desc->value, 'amount' => Values::parseMoney($amt->value)];
            }
        }
        $score = 60;
        foreach (['TOTAL_PERCEPCIONES', 'TOTAL_DEDUCCIONES', 'NETO', 'CANTIDAD_EMPLEADOS'] as $k) {
            $score += isset($footer[$k]) ? 10 : 0;
        }
        return new GlobalData($hrow, $lines, $footer, (float) $score);
    }

    private static function looksPersonal(string $text): bool
    {
        $compact = (string) preg_replace('/[' . Py::WS . '-]/u', '', $text);
        return preg_match('/[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{2,3}|\d{8,}/iu', $compact) === 1;
    }
}
