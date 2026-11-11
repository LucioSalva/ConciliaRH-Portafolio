<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Conciliation;

use ConciliaRH\Engine\Support\Dec;
use ConciliaRH\Engine\Support\Py;

final class PolicyLevels
{
    private const HOJAS_NO_BANCO = ['RETENCION', 'APORTACION', 'TRASPASO'];

    public function __construct(private readonly State $st)
    {
    }

    public static function amountFromName(?string $nombre): ?string
    {
        if (preg_match('/\$[' . Py::WS . ']*([\d,]+\.\d{2})/u', (string) $nombre, $m) === 1) {
            return Dec::of(str_replace(',', '', $m[1]));
        }
        return null;
    }

    public static function bankOfSheet(?string $nombre): ?string
    {
        $n = Py::upper((string) $nombre);
        foreach (self::HOJAS_NO_BANCO as $w) {
            if (str_contains($n, $w)) {
                return null;
            }
        }
        return match (true) {
            str_contains($n, 'PENSION') => 'PENSIONES',
            str_contains($n, 'BBVA') || str_contains($n, 'BANCOMER') => 'BBVA',
            str_contains($n, 'SANTANDER') => 'SANTANDER',
            str_contains($n, 'INTERBANC') => 'INTERBANCARIA',
            default => null,
        };
    }

    private static function compatible(string $hojaBanco, string $dispBanco): bool
    {
        if ($hojaBanco === 'SANTANDER') {
            return in_array($dispBanco, ['SANTANDER', 'INTERBANCARIA'], true);
        }
        return $hojaBanco === $dispBanco;
    }

    /**
     * @param list<array<string,mixed>> $rrhh
     * @param list<array<string,mixed>> $globales
     */
    public function level1(array $rrhh, array $globales): void
    {
        if ($globales === []) {
            return;
        }
        $st = $this->st;
        $grupos = [];
        foreach ($rrhh as $r) {
            $grupos[(string) $r['grupo']] = true;
        }
        foreach ($globales as $g) {
            $grupos[(string) $g['grupo']] = true;
        }
        $grupos = array_map('strval', array_keys($grupos));
        sort($grupos, SORT_STRING);
        foreach ($grupos as $grupo) {
            $regs = array_values(array_filter($rrhh, static fn ($r) => $r['grupo'] === $grupo));
            $gl = array_values(array_filter($globales, static fn ($g) => $g['grupo'] === $grupo));
            $fuentes = [];
            foreach ($regs as $r) {
                $fuentes[(int) $r['archivo_id']] = true;
            }
            foreach ($gl as $g) {
                $fuentes[(int) $g['archivo_id']] = true;
            }
            $fuentes = array_keys($fuentes);
            sort($fuentes);
            if (count($gl) > 1) {
                $st->controles[] = new LevelControl(1, 'RRHH_POLIZA_GLOBAL', 'Hay más de una póliza GLOBAL del grupo en el lote', 'REVISION_MANUAL',
                    grupo: $grupo, fuentes: $fuentes);
                $st->inc("GLOBAL_MULTIPLE:$grupo", 'POLIZA_GLOBAL_MULTIPLE', 'CRITICA', 1, 'El lote tiene más de una póliza GLOBAL del mismo grupo.', grupo: $grupo);
                continue;
            }
            $g = $gl[0] ?? null;
            $perc = $ded = $neto = '0';
            $emps = [];
            foreach ($regs as $r) {
                $perc = $r['percepciones'] !== null ? Dec::add($perc, (string) $r['percepciones']) : $perc;
                $ded = $r['deducciones'] !== null ? Dec::add($ded, (string) $r['deducciones']) : $ded;
                $neto = $r['neto'] !== null ? Dec::add($neto, (string) $r['neto']) : $neto;
                $emps[$r['empleado_id'] !== null ? 'E' . $r['empleado_id'] : 'N:' . $r['numero']] = true;
            }
            $gNeto = $g !== null ? ($g['neto_declarado'] ?? Dec::sub((string) $g['percepciones'], (string) $g['deducciones'])) : null;
            $defs = [['PERCEPCIONES', 'Suma de percepciones RRHH vs percepciones de la póliza GLOBAL', $perc, $g['percepciones'] ?? null],
                     ['DEDUCCIONES', 'Suma de deducciones RRHH vs deducciones de la póliza GLOBAL', $ded, $g['deducciones'] ?? null],
                     ['NETO', 'Suma de netos RRHH vs neto de la póliza GLOBAL', $neto, $gNeto]];
            foreach ($defs as [$cod, $desc, $a, $b]) {
                $b = $b !== null ? (string) $b : null;
                $aa = $regs !== [] ? $a : null;
                $est = Engine::cmp($aa, $b, $st->tol);
                $st->controles[] = new LevelControl(1, "RRHH_GLOBAL_$cod", $desc, $est, grupo: $grupo, valorA: $aa, etiquetaA: 'RRHH detalle', valorB: $b,
                    etiquetaB: 'Póliza GLOBAL', diferencia: ($regs !== [] && $b !== null) ? Dec::sub($a, $b) : null, fuentes: $fuentes);
                if ($est === 'NO_CUADRA') {
                    $st->inc("N1:$grupo:$cod", "RRHH_POLIZA_{$cod}_NO_CUADRA", 'ERROR', 1,
                        'El detalle RRHH no coincide con la póliza GLOBAL (' . strtolower($cod) . ').', grupo: $grupo, importe: Dec::sub($a, (string) $b));
                } elseif ($est === 'NO_DETERMINABLE') {
                    $st->inc("N1:$grupo:$cod", 'RRHH_POLIZA_NO_DETERMINABLE', 'ADVERTENCIA', 1, 'Falta el tabulado RRHH o la póliza GLOBAL del grupo en este lote.',
                        grupo: $grupo);
                }
            }
            if ($g !== null && $g['empleados_declarados'] !== null && $regs !== []) {
                $n = count($emps);
                $declared = (int) $g['empleados_declarados'];
                $est = $n === $declared ? 'CUADRA' : 'NO_CUADRA';
                $st->controles[] = new LevelControl(1, 'RRHH_GLOBAL_EMPLEADOS', 'Personas en el detalle RRHH vs cantidad declarada en la póliza GLOBAL', $est,
                    grupo: $grupo, valorA: (string) $n, etiquetaA: 'RRHH detalle', valorB: (string) $declared, etiquetaB: 'Póliza GLOBAL',
                    diferencia: (string) ($n - $declared), fuentes: $fuentes);
                if ($est === 'NO_CUADRA') {
                    $st->inc("N1:$grupo:EMPLEADOS", 'RRHH_POLIZA_EMPLEADOS_NO_CUADRA', 'ERROR', 1,
                        'La cantidad de personas del detalle RRHH no coincide con la póliza GLOBAL.', grupo: $grupo);
                }
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $tesNom
     * @param list<array<string,mixed>> $tesPen
     * @param array<int,string> $grupoTes
     * @param list<int> $egresoIds
     * @return array<int,list<array{0:array<string,mixed>,1:Dispersion}>>
     */
    public function level3(array $tesNom, array $tesPen, array &$grupoTes, array $egresoIds): array
    {
        $st = $this->st;
        $tol = $st->tol;
        $disp = [];
        foreach ([...$tesNom, ...$tesPen] as $t) {
            $aid = (int) $t['archivo_id'];
            $disp[$aid] ??= new Dispersion($aid, (string) $t['banco'], (string) $t['categoria'], $grupoTes[$aid] ?? 'SIN_GRUPO', '0');
            if ($t['importe'] !== null && $t['importe_estado'] === 'OK') {
                $disp[$aid]->total = Dec::add($disp[$aid]->total, (string) $t['importe']);
            }
        }
        $sortedDisp = $disp;
        ksort($sortedDisp);
        foreach ($sortedDisp as $aid => $d) {
            $decl = $st->roles[$aid]->totalDeclarado;
            $est = $decl !== null ? Engine::cmp($d->total, $decl, $tol) : 'NO_DETERMINABLE';
            $st->controles[] = new LevelControl(3, 'DISPERSION_TOTAL_ARCHIVO', 'Detalle de la dispersión vs total declarado dentro del archivo', $est,
                grupo: $d->grupo, banco: $d->banco, categoria: $d->categoria, referencia: "archivo:$aid", valorA: $d->total, etiquetaA: 'Detalle',
                valorB: $decl, etiquetaB: 'Total del archivo', diferencia: $decl !== null ? Dec::sub($d->total, $decl) : null, fuentes: [$aid],
                detalle: ['archivo' => $aid]);
            if ($est === 'NO_CUADRA') {
                $st->inc("N3:TOTAL:$aid", 'DISPERSION_TOTAL_NO_CUADRA', 'ERROR', 3, 'El detalle de la dispersión no suma el total declarado en el archivo.',
                    grupo: $d->grupo, banco: $d->banco, importe: Dec::sub($d->total, (string) $decl), detalle: ['archivo' => $aid]);
            }
        }
        $egreso = array_fill_keys($egresoIds, true);
        $hojas = array_values(array_filter($st->inp['egreso'], static fn ($h) => isset($egreso[(int) $h['archivo_id']])));
        $usados = [];
        $rel = [];
        $sinRel = [];
        $manuales = [];
        foreach ($st->inp['relaciones_manuales'] as $k => $v) {
            [$ea, $eh] = array_map('intval', explode(':', (string) $k));
            if (!isset($disp[(int) $v])) {
                continue;
            }
            foreach ($hojas as $h) {
                if ((int) $h['archivo_id'] === $ea && (int) $h['hoja_indice'] === $eh) {
                    $manuales["$ea:$eh"] = (int) $v;
                    break;
                }
            }
        }
        foreach ($manuales as $v) {
            $usados[$v] = true;
        }
        usort($hojas, static fn ($x, $y) => [$x['archivo_id'], $x['hoja_indice']] <=> [$y['archivo_id'], $y['hoja_indice']]);
        foreach ($hojas as $h) {
            $bh = self::bankOfSheet($h['hoja_nombre']);
            if ($bh === null) {
                continue;
            }
            $monto = $h['monto_bancos'] ?? $h['monto_nombre'];
            if ($h['monto_nombre'] !== null && $h['monto_bancos'] !== null && Dec::gt(Dec::abs(Dec::sub($h['monto_nombre'], $h['monto_bancos'])), $tol)) {
                $st->inc("N3:HOJA:{$h['archivo_id']}:{$h['hoja_indice']}", 'POLIZA_HOJA_MONTO_INCONSISTENTE', 'ERROR', 3,
                    'El monto del nombre de la hoja de la póliza de egreso no coincide con su línea de bancos.',
                    importe: Dec::sub($h['monto_bancos'], $h['monto_nombre']), detalle: ['archivo' => (int) $h['archivo_id'], 'hoja' => (int) $h['hoja_indice']]);
            }
            $categoria = $bh === 'PENSIONES' ? 'PENSION' : 'NOMINA';
            $manual = $manuales[$h['archivo_id'] . ':' . $h['hoja_indice']] ?? null;
            if ($manual !== null) {
                [$cand, $origen] = [[$disp[$manual]], 'MANUAL'];
            } else {
                $compat = array_values(array_filter($disp, static fn ($d) => !isset($usados[$d->archivoId]) && $d->categoria === $categoria
                    && ($bh === 'PENSIONES' || self::compatible($bh, $d->banco))));
                $exactos = array_values(array_filter($compat, static fn ($d) => $monto !== null && Dec::le(Dec::abs(Dec::sub($d->total, (string) $monto)), $tol)));
                [$cand, $origen] = $exactos !== [] ? [$exactos, 'IMPORTE_EXACTO'] : [[], 'SIN_RELACION'];
            }
            $base = ['banco' => $bh !== 'PENSIONES' ? $bh : null, 'categoria' => $categoria, 'valorA' => $monto,
                     'etiquetaA' => 'Póliza de egreso «' . Py::sub(Py::strip((string) $h['hoja_nombre']), 60) . '»',
                     'referencia' => "hoja:{$h['archivo_id']}:{$h['hoja_indice']}"];
            if (count($cand) === 1) {
                $d = $cand[0];
                $usados[$d->archivoId] = true;
                $rel[(int) $h['archivo_id']][] = [$h, $d];
                $est = Engine::cmp($monto, $d->total, $tol);
                $st->controles[] = new LevelControl(3, 'POLIZA_EGRESO_VS_DISPERSION', 'Hoja de póliza de egreso vs total de la dispersión relacionada', $est,
                    grupo: $d->grupo, banco: $base['banco'], categoria: $categoria, referencia: $base['referencia'], valorA: $monto, etiquetaA: $base['etiquetaA'],
                    valorB: $d->total, etiquetaB: 'Dispersión', valorC: $st->roles[$d->archivoId]->totalDeclarado, etiquetaC: 'Total del archivo',
                    diferencia: $monto !== null ? Dec::sub($monto, $d->total) : null, fuentes: [(int) $h['archivo_id'], $d->archivoId],
                    detalle: ['hoja' => (int) $h['hoja_indice'], 'dispersion' => $d->archivoId, 'relacion' => $origen, 'banco_dispersion' => $d->banco]);
                if ($est === 'NO_CUADRA') {
                    $st->inc("N3:EGRESO:{$h['archivo_id']}:{$h['hoja_indice']}", 'POLIZA_BANCO_NO_CUADRA', 'ERROR', 3,
                        'La póliza de egreso del banco no coincide con la dispersión relacionada.', grupo: $d->grupo, banco: $d->banco,
                        importe: $monto !== null ? Dec::sub($monto, $d->total) : null, detalle: ['hoja' => (int) $h['hoja_indice'], 'dispersion' => $d->archivoId]);
                }
            } elseif (count($cand) > 1) {
                $ids = array_map(static fn ($d) => $d->archivoId, $cand);
                $st->controles[] = new LevelControl(3, 'POLIZA_EGRESO_VS_DISPERSION', 'Varias dispersiones podrían corresponder a la hoja de la póliza',
                    'REVISION_MANUAL', banco: $base['banco'], categoria: $categoria, referencia: $base['referencia'], valorA: $monto, etiquetaA: $base['etiquetaA'],
                    fuentes: [(int) $h['archivo_id'], ...$ids], detalle: ['hoja' => (int) $h['hoja_indice'], 'candidatas' => $ids]);
                $st->inc("N3:AMBIGUA:{$h['archivo_id']}:{$h['hoja_indice']}", 'POLIZA_BANCO_RELACION_AMBIGUA', 'ADVERTENCIA', 3,
                    'Varias dispersiones coinciden con la hoja de la póliza de egreso: relaciónela manualmente.',
                    detalle: ['hoja' => (int) $h['hoja_indice'], 'candidatas' => $ids]);
            } elseif ($monto !== null && Dec::isZero((string) $monto)) {
                $st->controles[] = new LevelControl(3, 'POLIZA_EGRESO_VS_DISPERSION', 'Hoja de póliza de egreso sin movimiento', 'INFORMATIVO',
                    banco: $base['banco'], categoria: $categoria, referencia: $base['referencia'], valorA: $monto, etiquetaA: $base['etiquetaA'],
                    fuentes: [(int) $h['archivo_id']], detalle: ['hoja' => (int) $h['hoja_indice']]);
            } else {
                $sinRel[] = [$h, $bh, $categoria, $monto, $base];
            }
        }
        $grupoEgreso = self::egressGroups($rel);
        foreach ($rel as $eid => $pares) {
            $g = $grupoEgreso[$eid] ?? null;
            if (!in_array($g, Engine::GRUPOS, true)) {
                continue;
            }
            foreach ($pares as [, $d]) {
                if ($d->grupo === 'SIN_GRUPO') {
                    $d->grupo = $grupoTes[$d->archivoId] = $g;
                    $st->roles[$d->archivoId]->grupo = $g;
                    $st->roles[$d->archivoId]->motivo = "Grupo tomado de la póliza de egreso #$eid (sin personas identificadas en el archivo)";
                    foreach ($st->controles as $k) {
                        if ($k->nivel === 3 && $k->grupo === 'SIN_GRUPO' && in_array($d->archivoId, $k->fuentes, true)) {
                            $k->grupo = $g;
                            $k->detalle['grupo_origen'] = 'POLIZA_EGRESO';
                        }
                    }
                }
            }
        }
        foreach ($sinRel as [$h, $bh, $categoria, $monto, $base]) {
            $g = $grupoEgreso[(int) $h['archivo_id']] ?? null;
            $libres = array_values(array_filter($disp, static fn ($d) => !isset($usados[$d->archivoId]) && $d->categoria === $categoria
                && ($bh === 'PENSIONES' || self::compatible($bh, $d->banco)) && ($g === null || in_array($d->grupo, [$g, 'SIN_GRUPO'], true))));
            usort($libres, static fn ($x, $y) => $x->archivoId <=> $y->archivoId);
            if ($libres !== []) {
                $ids = array_map(static fn ($d) => $d->archivoId, $libres);
                $difs = [];
                foreach ($libres as $d) {
                    if ($monto !== null) {
                        $difs[(string) $d->archivoId] = Dec::sub((string) $monto, $d->total);
                    }
                }
                $st->controles[] = new LevelControl(3, 'POLIZA_EGRESO_VS_DISPERSION', 'Hoja de póliza de egreso sin dispersión de igual importe; hay candidatas',
                    'REVISION_MANUAL', grupo: $g, banco: $base['banco'], categoria: $categoria, referencia: $base['referencia'], valorA: $monto,
                    etiquetaA: $base['etiquetaA'], fuentes: [(int) $h['archivo_id'], ...$ids],
                    detalle: ['hoja' => (int) $h['hoja_indice'], 'candidatas' => $ids, 'diferencias' => (object) $difs]);
                $st->inc("N3:SINREL:{$h['archivo_id']}:{$h['hoja_indice']}", 'POLIZA_BANCO_SIN_DISPERSION_IGUAL', 'ADVERTENCIA', 3,
                    'Ninguna dispersión tiene el importe de la hoja de la póliza de egreso; hay dispersiones candidatas con otro importe: relaciónela manualmente para cuantificar la diferencia.',
                    grupo: $g, importe: $monto, detalle: ['hoja' => (int) $h['hoja_indice'], 'candidatas' => $ids]);
            } else {
                $st->controles[] = new LevelControl(3, 'POLIZA_EGRESO_VS_DISPERSION', 'Hoja de póliza de egreso sin dispersión demostrablemente relacionada',
                    'NO_DETERMINABLE', grupo: $g, banco: $base['banco'], categoria: $categoria, referencia: $base['referencia'], valorA: $monto,
                    etiquetaA: $base['etiquetaA'], fuentes: [(int) $h['archivo_id']], detalle: ['hoja' => (int) $h['hoja_indice']]);
                $st->inc("N3:SINREL:{$h['archivo_id']}:{$h['hoja_indice']}", 'POLIZA_BANCO_SIN_DISPERSION', 'ADVERTENCIA', 3,
                    'Ninguna dispersión del lote coincide con la hoja de la póliza de egreso; relaciónela manualmente si corresponde.', grupo: $g, importe: $monto,
                    detalle: ['hoja' => (int) $h['hoja_indice']]);
            }
        }
        foreach ($sortedDisp as $aid => $d) {
            if (!isset($usados[$aid]) && $d->categoria === 'NOMINA' && $egresoIds !== []) {
                $st->controles[] = new LevelControl(3, 'DISPERSION_SIN_POLIZA_EGRESO', 'Dispersión de nómina sin hoja de póliza de egreso relacionada',
                    'NO_DETERMINABLE', grupo: $d->grupo, banco: $d->banco, categoria: $d->categoria, referencia: "archivo:$aid", valorA: $d->total,
                    etiquetaA: 'Dispersión', fuentes: [$aid]);
            }
        }
        return $rel;
    }

    /**
     * @param array<int,list<array{0:array<string,mixed>,1:Dispersion}>> $rel
     * @return array<int,string>
     */
    public static function egressGroups(array $rel): array
    {
        $out = [];
        foreach ($rel as $eid => $pares) {
            $k = [];
            foreach ($pares as [, $d]) {
                if (in_array($d->grupo, Engine::GRUPOS, true)) {
                    $k[$d->grupo] = ($k[$d->grupo] ?? 0) + 1;
                }
            }
            if ($k !== []) {
                $g = null;
                $n = -1;
                foreach ($k as $grp => $cnt) {
                    if ($cnt > $n) {
                        [$g, $n] = [(string) $grp, $cnt];
                    }
                }
                $out[$eid] = $n === array_sum($k) ? $g : 'MIXTO';
            }
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $globales
     * @param array<int,list<array{0:array<string,mixed>,1:Dispersion}>> $rel
     */
    public function level2(array $globales, array $rel): void
    {
        $st = $this->st;
        $grupoEgreso = self::egressGroups($rel);
        $grupos = array_values(array_unique(array_map(static fn ($g) => (string) $g['grupo'], $globales)));
        sort($grupos, SORT_STRING);
        foreach ($grupos as $g) {
            $gl = array_values(array_filter($globales, static fn ($x) => $x['grupo'] === $g));
            if (count($gl) !== 1) {
                continue;
            }
            $neto = $gl[0]['neto_declarado'] ?? Dec::sub((string) $gl[0]['percepciones'], (string) $gl[0]['deducciones']);
            $eids = array_keys(array_filter($grupoEgreso, static fn ($x) => $x === $g));
            if ($eids === []) {
                $st->controles[] = new LevelControl(2, 'GLOBAL_VS_EGRESO_NETO', 'Neto de la póliza GLOBAL vs pólizas de egreso de nómina (bancos)', 'NO_DETERMINABLE',
                    grupo: $g, valorA: (string) $neto, etiquetaA: 'Póliza GLOBAL', fuentes: [(int) $gl[0]['archivo_id']],
                    detalle: ['motivo' => 'No hay póliza de egreso relacionable con este grupo']);
                continue;
            }
            $monto = '0';
            $fuentes = [(int) $gl[0]['archivo_id']];
            foreach ($eids as $eid) {
                $fuentes[] = (int) $eid;
                foreach ($rel[$eid] as [$h, $d]) {
                    if ($d->categoria === 'NOMINA' && $h['monto_bancos'] !== null) {
                        $monto = Dec::add($monto, (string) $h['monto_bancos']);
                    }
                }
            }
            sort($fuentes);
            $est = Engine::cmp((string) $neto, $monto, $st->tol);
            $st->controles[] = new LevelControl(2, 'GLOBAL_VS_EGRESO_NETO', 'Neto de la póliza GLOBAL vs pólizas de egreso de nómina (bancos)', $est, grupo: $g,
                valorA: (string) $neto, etiquetaA: 'Póliza GLOBAL', valorB: $monto, etiquetaB: 'Pólizas de egreso', diferencia: Dec::sub((string) $neto, $monto),
                fuentes: $fuentes);
            if ($est === 'NO_CUADRA') {
                $st->inc("N2:$g", 'GLOBAL_EGRESO_NO_CUADRA', 'ERROR', 2,
                    'El neto de la póliza GLOBAL no coincide con lo que registran las pólizas de egreso de los bancos.', grupo: $g,
                    importe: Dec::sub((string) $neto, $monto));
            }
        }
    }
}
