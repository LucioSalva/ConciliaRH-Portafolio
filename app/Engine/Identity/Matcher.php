<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Identity;

use ConciliaRH\Engine\Support\Py;
use ConciliaRH\Engine\Support\SequenceMatcher;

final class Matcher
{
    public const PENDIENTES = ['SUGERENCIA', 'AMBIGUO', 'CONFLICTO', 'NO_IDENTIFICADO'];
    public const FUZZY_MIN = 0.85;
    private const FUZZY_MAX = 3;

    /** @return list<array{0:string,1:string,2:array<string,mixed>}> */
    public static function quality(Evidence $ev): array
    {
        $out = [];
        if ($ev->numero !== null && $ev->numero !== '' && !$ev->numeroValido) {
            $out[] = ['NUMERO_EMPLEADO_INVALIDO', 'ADVERTENCIA', []];
        }
        if ($ev->rfc !== null && $ev->rfc !== '' && !$ev->rfcValido) {
            $out[] = ['RFC_INVALIDO', 'ADVERTENCIA', []];
        }
        if ($ev->curp !== null && $ev->curp !== '' && !$ev->curpValida) {
            $out[] = ['CURP_INVALIDO', 'ADVERTENCIA', []];
        }
        return $out;
    }

    public static function match(Evidence $ev, PersonIndex $idx): MatchResult
    {
        $strong = [];
        $inc = self::quality($ev);
        $dupMaster = [];
        $has = static fn (?string $s): bool => $s !== null && $s !== '';
        if ($has($ev->numero) && $ev->numeroValido && isset($idx->byNum[$ev->numero])) {
            $strong[] = ['NUMERO_EMPLEADO', $idx->byNum[$ev->numero]];
        }
        foreach ([['RFC', $ev->rfc, $ev->rfcValido, $idx->byRfc], ['CURP', $ev->curp, $ev->curpValida, $idx->byCurp]] as [$metodo, $value, $valid, $table]) {
            if (!($has($value) && $valid)) {
                continue;
            }
            $raw = array_keys($table[$value] ?? []);
            $ids = [];
            foreach ($raw as $i) {
                $ids[$idx->canonical($i)] = true;
            }
            if (count($ids) === 1) {
                $strong[] = [$metodo, min($raw)];
            } elseif (count($ids) > 1) {
                $prior = [];
                foreach ($strong as [, $e]) {
                    $prior[$idx->canonical($e)] = true;
                }
                if ($prior !== [] && array_diff_key($prior, $ids) === []) {
                    $emps = array_keys($ids);
                    sort($emps);
                    $inc[] = ['IDENTIFICADOR_DUPLICADO_EN_MAESTRO', 'ADVERTENCIA', ['metodo' => $metodo, 'empleados' => $emps]];
                } else {
                    $dupMaster[] = $metodo;
                    $sortedRaw = $raw;
                    sort($sortedRaw);
                    foreach ($sortedRaw as $i) {
                        $strong[] = [$metodo, $i];
                    }
                }
            }
        }
        if ($has($ev->cuentaHmac) && isset($idx->accounts[PersonIndex::accountKey((string) $ev->cuentaHmac, $ev->uso)])) {
            $strong[] = ['CUENTA_CONFIRMADA', $idx->accounts[PersonIndex::accountKey((string) $ev->cuentaHmac, $ev->uso)]];
        }
        $cands = [];
        foreach ($strong as [, $e]) {
            $cands[$idx->canonical($e)] = true;
        }
        $cands = array_keys($cands);
        sort($cands);
        $evid = ['fuertes' => array_map(static fn ($s) => [$s[0], $s[1]], $strong)];
        $metodos = array_values(array_unique(array_map(static fn ($s) => $s[0], $strong)));
        sort($metodos, SORT_STRING);
        if ($cands !== [] && $has($ev->numero) && $ev->numeroValido && !isset($idx->byNum[$ev->numero])) {
            $inc[] = ['NUMERO_DESCONOCIDO_CON_OTRA_EVIDENCIA', 'ALTA', ['metodos' => $metodos, 'empleados' => $cands]];
            return new MatchResult('CONFLICTO', null, null, 'NINGUNA', [...$evid, 'candidatos' => $cands, 'motivo' => 'NUMERO_DESCONOCIDO_CON_OTRA_EVIDENCIA'], $inc);
        }
        if (count($cands) > 1) {
            $tipo = ($dupMaster !== [] && count($metodos) === count($dupMaster)) ? 'IDENTIFICADOR_DUPLICADO_EN_MAESTRO' : 'IDENTIFICADORES_CONTRADICTORIOS';
            $inc[] = [$tipo, 'ALTA', ['metodos' => $metodos, 'empleados' => $cands]];
            return new MatchResult('CONFLICTO', null, null, 'NINGUNA', [...$evid, 'candidatos' => $cands], $inc);
        }
        if (count($cands) === 1) {
            $canonical = $cands[0];
            [$metodo, $emp] = $strong[0];
            $grupo = $idx->group($canonical);
            $rfcs = $curps = $names = [];
            foreach ($grupo as $p) {
                if ($has($p->rfc) && $p->rfcValido) {
                    $rfcs[(string) $p->rfc] = true;
                }
                if ($has($p->curp) && $p->curpValida) {
                    $curps[(string) $p->curp] = true;
                }
                if ($has($p->nameKey)) {
                    $names[(string) $p->nameKey] = true;
                }
            }
            if ($has($ev->rfc) && $ev->rfcValido && $rfcs !== [] && !isset($rfcs[$ev->rfc])) {
                $inc[] = ['RFC_DISCREPANTE', 'ALTA', ['empleado' => $canonical, 'via' => $metodo]];
                return new MatchResult('CONFLICTO', null, null, 'NINGUNA', [...$evid, 'candidatos' => [$canonical]], $inc);
            }
            if ($has($ev->curp) && $ev->curpValida && $curps !== [] && !isset($curps[$ev->curp])) {
                $inc[] = ['CURP_DISCREPANTE', 'ALTA', ['empleado' => $canonical, 'via' => $metodo]];
                return new MatchResult('CONFLICTO', null, null, 'NINGUNA', [...$evid, 'candidatos' => [$canonical]], $inc);
            }
            if ($ev->allowName && $has($ev->nameKey) && $names !== [] && !isset($names[$ev->nameKey])) {
                $inc[] = ['NOMBRE_DIFERENTE', 'INFO', ['empleado' => $canonical]];
            }
            if ($canonical !== $emp) {
                $evid['canonico'] = $canonical;
            }
            return new MatchResult('ASOCIADO_AUTOMATICO', $emp, $metodo, 'ALTA', $evid, $inc);
        }
        if ($has($ev->numero) && $ev->numeroValido) {
            $inc[] = ['NUMERO_EMPLEADO_DESCONOCIDO', 'ADVERTENCIA', []];
        }
        $anyId = $has($ev->numero) || $has($ev->rfc) || $has($ev->curp) || $has($ev->cuentaHmac);
        if (!$ev->allowName) {
            $motivo = $ev->uso === 'PENSION_BENEFICIARIO' ? 'PENSION_SIN_TRABAJADOR' : 'SIN_IDENTIFICADOR_FUERTE';
            if (!$anyId) {
                $inc[] = ['IDENTIDAD_INCOMPLETA', 'ADVERTENCIA', []];
            }
            return new MatchResult('NO_IDENTIFICADO', null, null, 'NINGUNA', [...$evid, 'motivo' => $motivo], $inc);
        }
        if (!$has($ev->nameKey)) {
            if (!$anyId) {
                $inc[] = ['IDENTIDAD_INCOMPLETA', 'ALTA', []];
                return new MatchResult('INVALIDO', null, null, 'NINGUNA', [...$evid, 'motivo' => 'SIN_DATOS_DE_IDENTIDAD'], $inc);
            }
            return new MatchResult('NO_IDENTIFICADO', null, null, 'NINGUNA', [...$evid, 'motivo' => 'SIN_COINCIDENCIA'], $inc);
        }
        $rej = array_fill_keys($ev->rechazados, true);
        $ids = array_values(array_filter(array_keys($idx->byName[$ev->nameKey] ?? []), static fn ($i) => !isset($rej[$i])));
        sort($ids);
        if (count($ids) === 1) {
            return new MatchResult('SUGERENCIA', null, 'NOMBRE_EXACTO', 'MEDIA', [...$evid, 'candidatos' => $ids, 'motivo' => 'NOMBRE_EXACTO_UNICO'], $inc);
        }
        if (count($ids) > 1) {
            $inc[] = ['NOMBRE_AMBIGUO', 'ADVERTENCIA', ['candidatos' => $ids]];
            return new MatchResult('AMBIGUO', null, 'NOMBRE_EXACTO', 'BAJA', [...$evid, 'candidatos' => $ids, 'motivo' => 'NOMBRE_EXACTO_MULTIPLE'], $inc);
        }
        $inc[] = ['NOMBRE_SIN_COINCIDENCIA', 'ADVERTENCIA', []];
        return new MatchResult('NO_IDENTIFICADO', null, null, 'NINGUNA',
            [...$evid, 'motivo' => 'SIN_COINCIDENCIA', 'similares' => self::similar((string) $ev->nameKey, $idx, $ev->rechazados)], $inc);
    }

    /**
     * @param list<int> $excluded
     * @return list<array{empleado_id:int,parecido:float}>
     */
    public static function similar(string $nameKey, PersonIndex $idx, array $excluded = []): array
    {
        $tokens = explode(' ', $nameKey);
        $pool = ($idx->byToken[$tokens[0]] ?? []) + ($idx->byToken[$tokens[count($tokens) - 1]] ?? []);
        $ex = array_fill_keys($excluded, true);
        $ids = array_values(array_filter(array_keys($pool), static fn ($i) => !isset($ex[$i])));
        sort($ids);
        $scored = [];
        foreach ($ids as $pid) {
            $other = $idx->people[$pid]->nameKey ?? '';
            $score = SequenceMatcher::ratioOf($nameKey, $other);
            if ($score >= $idx->fuzzyMin) {
                $scored[] = [Py::round($score, 2), $pid];
            }
        }
        usort($scored, static fn ($a, $b) => [-$a[0], $a[1]] <=> [-$b[0], $b[1]]);
        return array_map(static fn ($s) => ['empleado_id' => $s[1], 'parecido' => $s[0]], array_slice($scored, 0, self::FUZZY_MAX));
    }

    public static function matchRrhh(Evidence $ev, PersonIndex $idx): ?MatchResult
    {
        if ($ev->numero !== null && $ev->numero !== '' && $ev->numeroValido && !isset($idx->byNum[$ev->numero])) {
            $others = [];
            if ($ev->rfc !== null && $ev->rfc !== '' && $ev->rfcValido) {
                foreach (array_keys($idx->byRfc[$ev->rfc] ?? []) as $i) {
                    $others[$idx->canonical($i)] = true;
                }
            }
            if ($ev->curp !== null && $ev->curp !== '' && $ev->curpValida) {
                foreach (array_keys($idx->byCurp[$ev->curp] ?? []) as $i) {
                    $others[$idx->canonical($i)] = true;
                }
            }
            if ($others !== []) {
                $cands = array_keys($others);
                sort($cands);
                $inc = [...self::quality($ev), ['NUMERO_NUEVO_IDENTIDAD_EXISTENTE', 'ALTA', ['empleados' => $cands]]];
                return new MatchResult('CONFLICTO', null, null, 'NINGUNA',
                    ['fuertes' => [], 'candidatos' => $cands, 'motivo' => 'NUMERO_NUEVO_IDENTIDAD_EXISTENTE'], $inc);
            }
            return null;
        }
        return self::match($ev, $idx);
    }
}
