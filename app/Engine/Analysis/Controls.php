<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Analysis;

use ConciliaRH\Engine\Support\Dec;
use ConciliaRH\Engine\Support\Py;
use ConciliaRH\Engine\Workbook\Coordinates;
use ConciliaRH\Engine\Workbook\Sheet;

final class Controls
{
    private const MAX_EVIDENCE = 20;
    private const ZERO = '0.00';
    private const SUM_ONE = '/^[' . Py::WS . ']*\+?[' . Py::WS . ']*(?:_XLFN\.)?(SUM|SUBTOTAL)[' . Py::WS . ']*\([' . Py::WS . ']*(?:(\d+)[' . Py::WS
        . ']*,[' . Py::WS . ']*)?\$?([A-Z]{1,3})\$?(\d+):\$?([A-Z]{1,3})\$?(\d+)[' . Py::WS . ']*\)[' . Py::WS . ']*$/iu';

    /** @param iterable<MoneyValue> $values */
    public static function dsum(iterable $values): string
    {
        $t = '0';
        foreach ($values as $m) {
            if ($m->ok() && $m->value !== null) {
                $t = Dec::add($t, $m->raw ?? $m->value);
            }
        }
        return Dec::quantizeHalfUp($t, 2);
    }

    /**
     * @param list<array{0:int,1:bool,2:MoneyValue}> $detail
     * @param list<array{0:int,1:MoneyValue,2:string,3:bool}> $totals
     */
    public static function evaluateTotal(string $codigo, string $desc, array $detail, array $totals, string $tol, ?int $hoja): Control
    {
        $sumAll = self::dsum(array_map(static fn ($d) => $d[2], $detail));
        $sumVis = self::dsum(array_map(static fn ($d) => $d[2], array_filter($detail, static fn ($d) => !$d[1])));
        $hasHidden = false;
        foreach ($detail as $d) {
            $hasHidden = $hasHidden || $d[1];
        }
        $ctl = new Control($codigo, $desc, 'MONEDA', calculado: $sumAll, tolerancia: $tol, hoja: $hoja);
        if ($totals === []) {
            $ctl->estado = 'SIN_TOTAL';
            $ctl->mensaje = 'El archivo no declara fila TOTAL; se registra la suma del detalle.';
            return $ctl;
        }
        $valid = [];
        $invalid = [];
        foreach ($totals as [$row, $money, , $noCache]) {
            if ($noCache) {
                $invalid[] = [$row, 'FORMULA_SIN_VALOR_CACHE'];
            } elseif (!$money->ok() || $money->value === null) {
                $invalid[] = [$row, 'NO_NUMERICO'];
            } elseif (Dec::lt($money->value, '0') && Dec::le('0', $sumAll)) {
                $invalid[] = [$row, 'NEGATIVO'];
            } else {
                $valid[] = [$row, $money->value];
            }
        }
        $ctl->filas = [...array_slice(array_column($valid, 0), 0, self::MAX_EVIDENCE), ...array_slice(array_column($invalid, 0), 0, self::MAX_EVIDENCE)];
        if ($invalid !== []) {
            $ctl->detalle['totales_invalidos'] = array_map(static fn ($x) => ['fila' => $x[0], 'motivo' => $x[1]], array_slice($invalid, 0, self::MAX_EVIDENCE));
        }
        if ($valid === []) {
            $ctl->estado = 'TOTAL_INVALIDO';
            $motivos = array_values(array_unique(array_column($invalid, 1)));
            sort($motivos, SORT_STRING);
            $ctl->mensaje = 'La fila TOTAL no tiene un importe utilizable (' . implode(', ', $motivos) . ').';
            return $ctl;
        }
        $distinct = [];
        foreach ($valid as [, $v]) {
            $distinct[Dec::key($v)] = $v;
        }
        $distinct = array_values($distinct);
        usort($distinct, static fn ($a, $b) => Dec::cmp($a, $b));
        $subtotal = false;
        foreach ($totals as [, , $sub]) {
            $subtotal = $subtotal || $sub === 'SUBTOTAL';
        }
        if (count($distinct) === 1) {
            $declared = $distinct[0];
            $ctl->declarado = $declared;
            if (Dec::within($declared, $sumAll, $tol)) {
                $ctl->estado = 'CUADRA';
                $ctl->mensaje = 'El total declarado coincide con la suma del detalle.';
                $ctl->detalle['base'] = 'TODAS_LAS_FILAS';
            } elseif (($hasHidden || $subtotal) && Dec::within($declared, $sumVis, $tol)) {
                $ctl->calculado = $sumVis;
                $ctl->estado = 'CUADRA';
                $ctl->mensaje = 'El total declarado coincide con la suma de las filas VISIBLES (vista filtrada).';
                $ctl->detalle['base'] = 'FILAS_VISIBLES';
                $ctl->detalle['suma_todas'] = $sumAll;
            } else {
                $ctl->estado = 'NO_CUADRA';
                $ctl->mensaje = 'El total declarado no coincide con la suma del detalle.';
                if ($hasHidden) {
                    $ctl->detalle['suma_visibles'] = $sumVis;
                }
            }
            $ctl->diferencia = Dec::sub($declared, (string) $ctl->calculado);
            if (count($valid) > 1) {
                $ctl->detalle['filas_total_iguales'] = count($valid);
            }
            return $ctl;
        }
        $last = $valid[count($valid) - 1][1];
        $ctl->declarado = $last;
        $ctl->diferencia = Dec::sub($last, $sumAll);
        if (Dec::within($last, $sumAll, $tol)) {
            $ctl->estado = 'CUADRA';
            $ctl->mensaje = 'Hay subtotales intermedios; el último total coincide con la suma del detalle.';
        } else {
            $ctl->estado = 'MULTIPLES_TOTALES';
            $ctl->mensaje = 'Hay varias filas TOTAL con importes distintos; se requiere revisión.';
            $ctl->detalle['valores'] = array_slice($distinct, 0, 10);
        }
        return $ctl;
    }

    /**
     * @param list<RowInfo> $rows
     * @param array<int,true> $moneyCols
     */
    public static function formulaChecks(Sheet $sheet, array $rows, array $moneyCols, string $tol, int $hoja): ?Control
    {
        $grid = [];
        $hidden = [];
        foreach ($sheet->rows as $r) {
            $grid[$r->index] = $r->cells;
            if ($r->hidden) {
                $hidden[$r->index] = true;
            }
        }
        $checked = $matched = $unverifiable = 0;
        $bad = [];
        foreach ($rows as $r) {
            foreach ($r->cells as $col => $cell) {
                if (!isset($moneyCols[$col]) || !SheetScanner::verticalAggregate($cell->formula)) {
                    continue;
                }
                $ok = preg_match(self::SUM_ONE, (string) $cell->formula, $m) === 1;
                $cached = Values::parseMoney($cell->value);
                if (!$ok || str_contains((string) $cell->formula, '!') || !$cached->ok()
                    || Coordinates::index($m[3]) !== Coordinates::index($m[5])) {
                    $unverifiable++;
                    continue;
                }
                $c = Coordinates::index($m[3]) - 1;
                $r1 = min((int) $m[4], (int) $m[6]);
                $r2 = max((int) $m[4], (int) $m[6]);
                $skipHidden = strtoupper($m[1]) === 'SUBTOTAL';
                $vals = [];
                $noCache = false;
                for ($i = $r1; $i <= $r2; $i++) {
                    if ($skipHidden && isset($hidden[$i])) {
                        continue;
                    }
                    $cc = $grid[$i][$c] ?? null;
                    if ($cc === null) {
                        continue;
                    }
                    if ($cc->formula !== null && $cc->value === null) {
                        $noCache = true;
                        break;
                    }
                    if ($skipHidden && $cc->formula !== null && $cc->formula !== ''
                        && preg_match('/^[' . Py::WS . ']*\+?[' . Py::WS . ']*SUBTOTAL/iu', $cc->formula) === 1) {
                        continue;
                    }
                    if (Values::isNumber($cc->value)) {
                        $vals[] = Values::parseMoney($cc->value);
                    }
                }
                if ($noCache) {
                    $unverifiable++;
                    continue;
                }
                $checked++;
                $cv = $cached->value !== null && !Dec::isZero($cached->value) ? $cached->value : self::ZERO;
                if (Dec::within(self::dsum($vals), $cv, $tol)) {
                    $matched++;
                } else {
                    $bad[] = $r->index;
                }
            }
        }
        if ($checked === 0 && $unverifiable === 0) {
            return null;
        }
        return new Control('FORMULAS_SUMA_VERIFICADAS', 'Sumas del libro (SUM/SUBTOTAL) re-sumadas con Decimal vs. su valor cacheado', 'ENTERO',
            (string) $checked, (string) $matched, (string) ($matched - $checked), null,
            $checked === 0 ? 'NO_APLICA' : ($bad === [] ? 'CUADRA' : 'NO_CUADRA'), array_slice($bad, 0, self::MAX_EVIDENCE),
            $checked && $bad === [] ? 'Cada suma del libro coincide con la suma de su rango.'
                : ($bad !== [] ? 'Hay sumas del libro que no coinciden con su rango.' : 'No hay sumas verificables.'),
            ['no_verificables' => $unverifiable], $hoja);
    }

    /** @return array{0:MoneyValue,1:bool} */
    private static function cellMoney(RowInfo $r, ?int $col): array
    {
        if ($col === null || !isset($r->cells[$col])) {
            return [new MoneyValue('VACIO'), false];
        }
        $c = $r->cells[$col];
        return [Values::parseMoney($c->value), $c->formula !== null && $c->value === null];
    }

    /**
     * @param list<RowInfo> $rows
     * @return list<Control>
     */
    public static function tabular(Mapping $mapping, array $rows, string $tol, int $hoja, ?Sheet $sheet = null): array
    {
        $prof = $mapping->profile;
        $detail = array_values(array_filter($rows, static fn ($r) => $r->kind === 'DETALLE'));
        $totals = array_values(array_filter($rows, static fn ($r) => $r->kind === 'TOTAL'));
        $out = [
            new Control('CANTIDAD_FILAS_DETALLE', 'Filas de detalle (registros)', 'ENTERO', calculado: (string) count($detail), hoja: $hoja,
                mensaje: 'Registros tomados como detalle (sin TOTAL, vacías ni auxiliares).'),
            new Control('CANTIDAD_FILAS_TOTAL', 'Filas TOTAL detectadas', 'ENTERO', calculado: (string) count($totals), hoja: $hoja,
                filas: array_slice(array_map(static fn ($r) => $r->index, $totals), 0, self::MAX_EVIDENCE),
                mensaje: 'Filas de control excluidas del detalle.'),
        ];
        $hidden = array_values(array_map(static fn ($r) => $r->index, array_filter($detail, static fn ($r) => $r->hidden)));
        if ($hidden !== []) {
            $out[] = new Control('FILAS_DETALLE_OCULTAS', 'Filas de detalle ocultas', 'ENTERO', calculado: (string) count($hidden), hoja: $hoja,
                filas: array_slice($hidden, 0, self::MAX_EVIDENCE), mensaje: 'Filas ocultas en el libro (vista filtrada o manual).');
        }
        $moneyFields = [];
        foreach ($mapping->cols as $c => $f) {
            if ($prof->field($f)?->kind === 'money') {
                $moneyFields[] = [$c, $f];
            }
        }
        $labels = ['IMPORTE' => ['TOTAL_IMPORTE', 'Total declarado vs. suma del importe'], 'NETO' => ['TOTAL_NETO', 'Total declarado vs. suma del neto'],
                   'PERCEPCIONES' => ['TOTAL_PERCEPCIONES', 'Total de percepciones vs. suma'], 'DEDUCCIONES' => ['TOTAL_DEDUCCIONES', 'Total de deducciones vs. suma'],
                   'DEBE' => ['TOTAL_DEBE', 'Total DEBE vs. suma'], 'HABER' => ['TOTAL_HABER', 'Total HABER vs. suma']];
        $sums = [];
        $sortedFields = $moneyFields;
        usort($sortedFields, static fn ($a, $b) => strcmp($a[1], $b[1]));
        foreach ($sortedFields as [$col, $canon]) {
            $det = array_map(fn ($r) => [$r->index, $r->hidden, self::cellMoney($r, $col)[0]], $detail);
            $sums[$canon] = self::dsum(array_map(static fn ($d) => $d[2], $det));
            if (!$prof->rowTotals) {
                continue;
            }
            [$code, $desc] = $labels[$canon] ?? ['TOTAL_' . $canon, 'Total de ' . $canon];
            $tots = [];
            foreach ($totals as $r) {
                if (isset($r->cells[$col])) {
                    [$money, $nc] = self::cellMoney($r, $col);
                    $tots[] = [$r->index, $money, $r->subkind, $nc];
                }
            }
            $out[] = self::evaluateTotal($code, $desc, $det, $tots, $tol, $hoja);
            if ($canon === $prof->amount) {
                $inv = $neg = $empty = [];
                foreach ($det as [$ri, , $m]) {
                    if ($m->status === 'INVALIDO') {
                        $inv[] = $ri;
                    } elseif ($m->ok() && $m->negative) {
                        $neg[] = $ri;
                    } elseif ($m->status === 'VACIO') {
                        $empty[] = $ri;
                    }
                }
                $out[] = new Control('IMPORTES_INVALIDOS', 'Importes no interpretables en el detalle', 'ENTERO', '0', (string) count($inv),
                    (string) count($inv), null, $inv === [] ? 'CUADRA' : 'NO_CUADRA', array_slice($inv, 0, self::MAX_EVIDENCE),
                    'Un importe inválido nunca se toma como cero.', ['vacios' => count($empty)], $hoja);
                if ($neg !== []) {
                    $out[] = new Control('IMPORTES_NEGATIVOS', 'Importes negativos en el detalle', 'ENTERO', calculado: (string) count($neg),
                        filas: array_slice($neg, 0, self::MAX_EVIDENCE), hoja: $hoja, mensaje: 'Informativo: importes negativos en el detalle.');
                }
            }
        }
        if ($prof->amount === null && $moneyFields !== []) {
            $inv = [];
            foreach ($detail as $r) {
                foreach ($moneyFields as [$col]) {
                    if (self::cellMoney($r, $col)[0]->status === 'INVALIDO') {
                        $inv[$r->index] = true;
                    }
                }
            }
            $inv = array_keys($inv);
            sort($inv);
            $out[] = new Control('IMPORTES_INVALIDOS', 'Importes no interpretables en el detalle', 'ENTERO', '0', (string) count($inv),
                (string) count($inv), null, $inv === [] ? 'CUADRA' : 'NO_CUADRA', array_slice($inv, 0, self::MAX_EVIDENCE),
                'Un importe inválido nunca se toma como cero.', [], $hoja);
        }
        if ($mapping->conceptCols !== []) {
            $bad = [];
            $evaluated = 0;
            $cc = $mapping->conceptCols;
            ksort($cc);
            foreach ($cc as $col => $code) {
                $det = array_map(fn ($r) => [$r->index, $r->hidden, self::cellMoney($r, $col)[0]], $detail);
                $tots = [];
                foreach ($totals as $r) {
                    if (isset($r->cells[$col])) {
                        [$money, $nc] = self::cellMoney($r, $col);
                        $tots[] = [$r->index, $money, $r->subkind, $nc];
                    }
                }
                if ($tots === []) {
                    continue;
                }
                $evaluated++;
                if (self::evaluateTotal('X', '', $det, $tots, $tol, $hoja)->estado !== 'CUADRA') {
                    $bad[] = $code;
                }
            }
            if ($evaluated) {
                $out[] = new Control('TOTALES_CONCEPTOS', 'Totales por concepto vs. suma de cada columna', 'ENTERO', (string) $evaluated,
                    (string) ($evaluated - count($bad)), (string) (-count($bad)), null, $bad === [] ? 'CUADRA' : 'NO_CUADRA', [],
                    $bad !== [] ? 'Columnas de concepto cuyo total no coincide con su suma.' : 'Todas las columnas de concepto cuadran.',
                    ['conceptos_no_cuadran' => array_slice($bad, 0, 50)], $hoja);
            }
        }
        $values = array_values($mapping->cols);
        if (in_array('PERCEPCIONES', $values, true) && in_array('DEDUCCIONES', $values, true) && in_array('NETO', $values, true)) {
            [$cp, $cd, $cn] = [$mapping->colOf('PERCEPCIONES'), $mapping->colOf('DEDUCCIONES'), $mapping->colOf('NETO')];
            $mismatch = [];
            foreach ($detail as $r) {
                $p = self::cellMoney($r, $cp)[0];
                $d = self::cellMoney($r, $cd)[0];
                $n = self::cellMoney($r, $cn)[0];
                if ($p->value === null || $d->value === null || $n->value === null) {
                    continue;
                }
                if (Dec::gt(Dec::abs(Dec::sub(Dec::add($p->value, $d->value), $n->value)), $tol)
                    && Dec::gt(Dec::abs(Dec::sub(Dec::sub($p->value, $d->value), $n->value)), $tol)) {
                    $mismatch[] = $r->index;
                }
            }
            $out[] = new Control('CONSISTENCIA_NETO_FILAS', 'Filas donde percepciones y deducciones no dan el neto', 'ENTERO', '0',
                (string) count($mismatch), (string) count($mismatch), null, $mismatch === [] ? 'CUADRA' : 'NO_CUADRA',
                array_slice($mismatch, 0, self::MAX_EVIDENCE), 'Percepciones + deducciones (negativas) = neto, por fila.', [], $hoja);
        }
        if (isset($sums['DEBE'], $sums['HABER'])) {
            [$cdeb, $chab] = [$mapping->colOf('DEBE'), $mapping->colOf('HABER')];
            $final = null;
            foreach (array_reverse($totals) as $r) {
                if (isset($r->cells[$cdeb], $r->cells[$chab]) && SheetScanner::verticalAggregate($r->cells[$cdeb]->formula)
                    && SheetScanner::verticalAggregate($r->cells[$chab]->formula)) {
                    $final = $r;
                    break;
                }
            }
            if ($final !== null) {
                $deb = Values::parseMoney($final->cells[$cdeb]->value);
                $hab = Values::parseMoney($final->cells[$chab]->value);
                [$debe, $haber, $base, $filas] = [$deb->ok() ? $deb->value : null, $hab->ok() ? $hab->value : null, 'FILA_SUMAS', [$final->index]];
            } else {
                [$debe, $haber, $base, $filas] = [$sums['DEBE'], $sums['HABER'], 'DETALLE', []];
            }
            if ($base === 'DETALLE' && count($totals) >= 2) {
                $out[] = new Control('CUADRE_DEBE_HABER', 'Suma DEBE vs. suma HABER', 'MONEDA', $debe, $haber, estado: 'NO_APLICA', hoja: $hoja,
                    filas: array_slice(array_map(static fn ($t) => $t->index, $totals), 0, self::MAX_EVIDENCE), detalle: ['base' => 'NO_DETERMINABLE'],
                    mensaje: 'Hoja compuesta por bloques sin fila de sumas final: el cuadre global no es determinable '
                        . '(se verifican las sumas de cada bloque en FORMULAS_SUMA_VERIFICADAS).');
            } elseif ($debe === null || $haber === null) {
                $out[] = new Control('CUADRE_DEBE_HABER', 'Suma DEBE vs. suma HABER', 'MONEDA', estado: 'TOTAL_INVALIDO', hoja: $hoja, filas: $filas,
                    mensaje: 'La fila de sumas no tiene importes utilizables (¿fórmula sin valor cacheado?).');
            } else {
                $diff = Dec::sub($debe, $haber);
                $ok = Dec::le(Dec::abs($diff), $tol);
                $out[] = new Control('CUADRE_DEBE_HABER', 'Suma DEBE vs. suma HABER', 'MONEDA', $debe, $haber, $diff, $tol, $ok ? 'CUADRA' : 'NO_CUADRA',
                    $filas, $ok ? 'La póliza cuadra (DEBE = HABER).' : 'La póliza no cuadra: DEBE ≠ HABER.', ['base' => $base], $hoja);
            }
        }
        if ($sheet !== null) {
            $fc = self::formulaChecks($sheet, $rows, $mapping->moneyCols(), $tol, $hoja);
            if ($fc !== null) {
                $out[] = $fc;
            }
        }
        return $out;
    }

    /** @return list<Control> */
    public static function global(GlobalData $g, string $tol, int $hoja): array
    {
        $out = [];
        $sums = [];
        foreach ([['PERCEPCIONES', 'TOTAL_PERCEPCIONES', 'Total de percepciones vs. suma de conceptos'],
                  ['DEDUCCIONES', 'TOTAL_DEDUCCIONES', 'Total de deducciones vs. suma de conceptos']] as [$block, $code, $desc]) {
            $det = [];
            foreach ($g->lines as $ln) {
                if ($ln['block'] === $block) {
                    $det[] = [$ln['row'], false, $ln['amount']];
                }
            }
            $declared = $g->footer[$code] ?? null;
            $negative = false;
            if ($block === 'DEDUCCIONES' && $det !== []) {
                $allNonPos = true;
                foreach ($det as [, , $m]) {
                    $allNonPos = $allNonPos && ($m->value === null || Dec::le($m->value, '0'));
                }
                $negative = $allNonPos && Dec::lt(self::dsum(array_map(static fn ($d) => $d[2], $det)), '0');
            }
            if ($negative) {
                $det = array_map(static fn ($d) => [$d[0], $d[1], self::absMoney($d[2])], $det);
                $declared = $declared !== null ? [self::absMoney($declared[0]), $declared[1]] : null;
            }
            $tots = $declared !== null ? [[$declared[1], $declared[0], 'CONSTANTE', false]] : [];
            $ctl = self::evaluateTotal($code, $desc, $det, $tots, $tol, $hoja);
            if ($negative) {
                $ctl->detalle['convencion_signo'] = 'DEDUCCIONES_NEGATIVAS';
            }
            $out[] = $ctl;
            $sums[$block] = $ctl->calculado !== null && !Dec::isZero($ctl->calculado) ? $ctl->calculado : self::ZERO;
        }
        $neto = $g->footer['NETO'] ?? null;
        $calc = Dec::sub($sums['PERCEPCIONES'], Dec::abs($sums['DEDUCCIONES']));
        $ctl = self::evaluateTotal('NETO', 'Neto declarado vs. percepciones − deducciones', [[0, false, new MoneyValue('OK', $calc)]],
            $neto !== null ? [[$neto[1], $neto[0], 'CONSTANTE', false]] : [], $tol, $hoja);
        $ctl->calculado = $calc;
        $out[] = $ctl;
        $emp = $g->footer['CANTIDAD_EMPLEADOS'] ?? null;
        $out[] = new Control('CANTIDAD_EMPLEADOS_DECLARADOS', 'Cantidad de empleados declarada', 'ENTERO',
            $emp !== null && $emp[0]->ok() ? $emp[0]->value : null, filas: $emp !== null ? [$emp[1]] : [],
            estado: $emp !== null && $emp[0]->ok() ? 'NO_APLICA' : ($emp === null ? 'SIN_TOTAL' : 'TOTAL_INVALIDO'), hoja: $hoja,
            mensaje: 'Dato declarado por la póliza; se contrasta con el tabulado cuando está en el mismo libro.');
        $out[] = new Control('CANTIDAD_FILAS_DETALLE', 'Líneas de concepto', 'ENTERO', calculado: (string) count($g->lines), hoja: $hoja);
        return $out;
    }

    /** @return list<Control> */
    public static function cross(string $detailSum, int $detailCount, GlobalData $g, string $tol, int $hojaGlobal, ?string $visibleSum = null,
                                 ?int $visibleCount = null): array
    {
        $out = [];
        $neto = $g->footer['NETO'] ?? null;
        if ($neto !== null && $neto[0]->ok() && $neto[0]->value !== null) {
            [$base, $calc] = ['TODAS_LAS_FILAS', $detailSum];
            if (Dec::gt(Dec::abs(Dec::sub($neto[0]->value, $detailSum)), $tol) && $visibleSum !== null
                && Dec::le(Dec::abs(Dec::sub($neto[0]->value, $visibleSum)), $tol)) {
                [$base, $calc] = ['FILAS_VISIBLES', $visibleSum];
            }
            $d = Dec::sub($neto[0]->value, $calc);
            $out[] = new Control('NETO_VS_POLIZA_GLOBAL', 'Neto de la póliza GLOBAL del libro vs. suma del neto del tabulado', 'MONEDA',
                $neto[0]->value, $calc, $d, $tol, Dec::le(Dec::abs($d), $tol) ? 'CUADRA' : 'NO_CUADRA', [$neto[1]],
                $base === 'FILAS_VISIBLES' ? 'La póliza del libro corresponde a las filas visibles (vista filtrada).' : '', ['base' => $base], $hojaGlobal);
        }
        $emp = $g->footer['CANTIDAD_EMPLEADOS'] ?? null;
        if ($emp !== null && $emp[0]->ok() && $emp[0]->value !== null) {
            $count = $detailCount;
            if (!Dec::eq($emp[0]->value, (string) $detailCount) && $visibleCount !== null && Dec::eq($emp[0]->value, (string) $visibleCount)) {
                $count = $visibleCount;
            }
            $d = Dec::sub($emp[0]->value, (string) $count);
            $out[] = new Control('EMPLEADOS_VS_POLIZA_GLOBAL', 'Empleados declarados en la póliza GLOBAL vs. registros del tabulado', 'ENTERO',
                $emp[0]->value, (string) $count, $d, '0.00', Dec::isZero($d) ? 'CUADRA' : 'NO_CUADRA', [$emp[1]], hoja: $hojaGlobal);
        }
        return $out;
    }

    private static function absMoney(MoneyValue $m): MoneyValue
    {
        if (!$m->ok() || $m->value === null) {
            return $m;
        }
        return new MoneyValue('OK', Dec::abs($m->value), false, $m->rawKind, $m->raw !== null ? Dec::abs($m->raw) : null);
    }
}
