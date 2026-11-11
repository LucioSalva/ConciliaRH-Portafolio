<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Conciliation;

use ConciliaRH\Engine\Support\Dec;

final class PensionLevel
{
    public function __construct(private readonly State $st)
    {
    }

    /**
     * @param list<array<string,mixed>> $rrhh
     * @param list<array<string,mixed>> $tesPen
     * @param array<int,string> $grupoTes
     * @return list<PensionResult>
     */
    public function run(array $rrhh, array $tesPen, array $grupoTes = []): array
    {
        $st = $this->st;
        $tol = $st->tol;
        $ret = [];
        $regsPorEmp = [];
        foreach ($rrhh as $r) {
            if ($r['empleado_id'] !== null && in_array($r['asociacion'], Engine::ASOCIADO, true)) {
                $regsPorEmp[(int) $r['empleado_id']][] = $r;
            }
        }
        foreach ($regsPorEmp as $e => $regs) {
            $r = $regs[0];
            foreach ($regs as $x) {
                if ($x['id'] < $r['id']) {
                    $r = $x;
                }
            }
            foreach ($r['pensiones'] as $p) {
                $ret[$e] = Dec::add($ret[$e] ?? '0', (string) $p['retenido']);
                $st->fuentes[] = new Source($e, 'RRHH_PENSION', (int) $r['id'], (int) $r['archivo_id'], $r['hoja'], $r['fila'], null, (string) $p['codigo'],
                    (string) $p['importe_original'], (string) $p['retenido'], $r['asociacion']);
            }
        }
        $disp = [];
        $pend = [];
        foreach ($tesPen as $t) {
            if ($t['importe'] === null || $t['importe_estado'] !== 'OK') {
                continue;
            }
            if ($t['empleado_id'] !== null && in_array($t['asociacion'], Engine::ASOCIADO, true)) {
                $disp[(int) $t['empleado_id']][] = $t;
                $st->fuentes[] = new Source((int) $t['empleado_id'], 'TESORERIA_PENSION', (int) $t['id'], (int) $t['archivo_id'], $t['hoja'], $t['fila'],
                    $t['banco'], null, (string) $t['importe'], (string) $t['importe'], $t['asociacion']);
            } else {
                $pend[] = $t;
                $st->fuentes[] = new Source(null, 'PENDIENTE_PENSION', (int) $t['id'], (int) $t['archivo_id'], $t['hoja'], $t['fila'], $t['banco'], null,
                    (string) $t['importe'], (string) $t['importe'], $t['asociacion']);
            }
        }
        $ids = [];
        foreach ($ret as $e => $v) {
            if (!Dec::isZero($v)) {
                $ids[$e] = true;
            }
        }
        foreach (array_keys($disp) as $e) {
            $ids[$e] = true;
        }
        $ids = array_keys($ids);
        sort($ids);
        $out = [];
        foreach ($ids as $e) {
            $emp = $st->employee($e);
            $retenido = $ret[$e] ?? '0';
            $ds = $disp[$e] ?? [];
            $dispersado = Dec::sum(array_map(static fn ($t) => (string) $t['importe'], $ds));
            $dif = Dec::sub($dispersado, $retenido);
            $detalle = [];
            $alerta = null;
            if (Dec::le($retenido, $tol) && Dec::gt($dispersado, $tol)) {
                $estado = 'SIN_RETENCION_RRHH';
            } elseif (Dec::le(Dec::abs($dif), $tol)) {
                $estado = 'CORRECTA';
            } elseif (Dec::le($dispersado, $tol)) {
                $estado = 'NO_DISPERSADA';
            } elseif (Dec::lt($dif, Dec::neg($tol))) {
                $estado = 'PENSION_MENOR';
            } else {
                $estado = 'PENSION_MAYOR';
            }
            if (count($regsPorEmp[$e] ?? []) > 1) {
                $alerta = 'REGISTRO_EN_CONFLICTO';
                $detalle['registros_rrhh'] = count($regsPorEmp[$e]);
            }
            $out[] = new PensionResult($e, in_array($emp['grupo'], Engine::GRUPOS, true) ? (string) $emp['grupo'] : 'OTRO', (string) $emp['numero'],
                $retenido, $dispersado, count($ds), $estado, $alerta, $detalle);
        }
        $faltantes = [];
        foreach ($out as $x) {
            if (in_array($x->estado, ['NO_DISPERSADA', 'PENSION_MENOR'], true)) {
                $faltantes[$x->empleadoId] = Dec::sub($x->retenido, $x->dispersado);
            }
        }
        $porImportePend = [];
        foreach ($pend as $t) {
            $k = Dec::key((string) $t['importe']);
            $porImportePend[$k] = ($porImportePend[$k] ?? 0) + 1;
        }
        $porImporteFalta = [];
        foreach ($faltantes as $v) {
            $k = Dec::key($v);
            $porImporteFalta[$k] = ($porImporteFalta[$k] ?? 0) + 1;
        }
        $exclusivos = [];
        foreach ($pend as $t) {
            $c = array_values(array_unique(array_map('intval', $t['candidatos'])));
            if (count($c) === 1) {
                $exclusivos[(int) $t['candidatos'][0]][] = $t;
            }
        }
        foreach ($out as $x) {
            $falta = $faltantes[$x->empleadoId] ?? null;
            if ($falta === null) {
                continue;
            }
            $propios = $exclusivos[$x->empleadoId] ?? [];
            $k = Dec::key($falta);
            if ($propios !== [] && Dec::le(Dec::abs(Dec::sub(Dec::sum(array_map(static fn ($t) => (string) $t['importe'], $propios)), $falta)), $tol)) {
                $regla = 'Un pago de pensión pendiente cuyo único trabajador candidato es esta persona completa exactamente el faltante';
            } elseif (($porImportePend[$k] ?? 0) === 1 && ($porImporteFalta[$k] ?? 0) === 1) {
                $regla = 'Un único pago de pensión pendiente tiene exactamente el importe faltante y ninguna otra persona tiene ese faltante';
            } else {
                foreach ($pend as $t) {
                    if (Dec::le(Dec::abs(Dec::sub((string) $t['importe'], $falta)), $tol)) {
                        $x->alerta = $x->alerta ?? 'POSIBLE_PAGO_PENDIENTE';
                        break;
                    }
                }
                continue;
            }
            $x->detalle['estado_matematico'] = $x->estado;
            $x->detalle['regla'] = $regla;
            $x->estado = 'PENDIENTE_IDENTIDAD';
            $x->alerta = $x->alerta ?? 'POSIBLE_PAGO_PENDIENTE';
        }
        $this->coverByPopulation($out, $pend, $grupoTes);
        return $out;
    }

    /**
     * @param list<PensionResult> $out
     * @param list<array<string,mixed>> $pend
     * @param array<int,string> $grupoTes
     */
    private function coverByPopulation(array $out, array $pend, array $grupoTes): void
    {
        $tol = $this->st->tol;
        $pendiente = [];
        foreach ($pend as $t) {
            $g = $grupoTes[(int) $t['archivo_id']] ?? 'SIN_GRUPO';
            $pendiente[$g] = Dec::add($pendiente[$g] ?? '0', (string) $t['importe']);
        }
        foreach (Engine::GRUPOS as $g) {
            if (!isset($pendiente[$g])) {
                continue;
            }
            $faltan = array_values(array_filter($out, static fn (PensionResult $x) => $x->grupo === $g
                && in_array($x->estado, ['NO_DISPERSADA', 'PENSION_MENOR', 'PENDIENTE_IDENTIDAD'], true)));
            $falta = Dec::sum(array_map(static fn (PensionResult $x) => Dec::sub($x->retenido, $x->dispersado), $faltan));
            if ($faltan === [] || !Dec::le(Dec::abs(Dec::sub($falta, $pendiente[$g])), $tol)) {
                continue;
            }
            foreach ($faltan as $x) {
                $x->detalle['estado_matematico'] ??= $x->estado;
                $x->detalle['cubierta_por_total_poblacion'] = true;
                $x->detalle['regla'] = 'La pensión retenida a la población se dispersó completa; el pago de esta persona está en un archivo sin número '
                    . 'de empleado y falta identificar al beneficiario';
                $x->estado = 'PENDIENTE_IDENTIDAD';
                $x->alerta = $x->alerta ?? 'POSIBLE_PAGO_PENDIENTE';
            }
        }
    }
}
