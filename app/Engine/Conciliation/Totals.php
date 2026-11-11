<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Conciliation;

use ConciliaRH\Engine\Support\Dec;
use ConciliaRH\Engine\Support\Json;

final class Totals
{
    public function __construct(private readonly State $st)
    {
    }

    /**
     * @param list<array<string,mixed>> $rrhh
     * @param list<array<string,mixed>> $tesNom
     * @param list<array<string,mixed>> $tesPen
     * @param array<int,string> $grupoTes
     * @param list<EmployeeResult> $empleados
     * @param list<PensionResult> $pensiones
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    public function build(array $rrhh, array $tesNom, array $tesPen, array $grupoTes, array $empleados, array $pensiones): array
    {
        $st = $this->st;
        $tol = $st->tol;
        $gOf = function (array $t) use ($grupoTes): string {
            if ($t['empleado_id'] !== null && in_array($t['asociacion'], Engine::ASOCIADO, true)) {
                return (string) $this->st->employee((int) $t['empleado_id'])['grupo'];
            }
            return $grupoTes[(int) $t['archivo_id']] ?? 'SIN_GRUPO';
        };
        $bloque = function (?string $grupo) use ($rrhh, $tesNom, $tesPen, $gOf, $empleados, $pensiones): array {
            $ok = static fn ($t) => $t['importe'] !== null && $t['importe_estado'] === 'OK';
            $rr = array_values(array_filter($rrhh, static fn ($r) => $grupo === null || $r['grupo'] === $grupo));
            $tn = array_values(array_filter($tesNom, static fn ($t) => $ok($t) && ($grupo === null || $gOf($t) === $grupo)));
            $tp = array_values(array_filter($tesPen, static fn ($t) => $ok($t) && ($grupo === null || $gOf($t) === $grupo)));
            $isIdent = static fn ($t) => $t['empleado_id'] !== null && in_array($t['asociacion'], Engine::ASOCIADO, true);
            $ident = array_values(array_filter($tn, $isIdent));
            $sumOf = static fn (array $rows, string $k = 'importe') => Dec::sum(array_map(static fn ($t) => (string) $t[$k], $rows));
            $porBanco = [];
            $porBancoIdent = [];
            foreach (['SANTANDER', 'BBVA', 'INTERBANCARIA', 'OTROS'] as $b) {
                $porBanco[$b] = $sumOf(array_filter($tn, static fn ($t) => Engine::bankKey($t['banco']) === $b));
                $porBancoIdent[$b] = $sumOf(array_filter($ident, static fn ($t) => Engine::bankKey($t['banco']) === $b));
            }
            $penIdent = array_values(array_filter($tp, $isIdent));
            $neto = Dec::sum(array_map(static fn ($r) => (string) $r['neto'], array_filter($rr, static fn ($r) => $r['neto'] !== null)));
            $total = $sumOf($tn);
            $retenido = '0';
            $retSinEmp = '0';
            foreach ($rr as $r) {
                foreach ($r['pensiones'] as $p) {
                    $retenido = Dec::add($retenido, (string) $p['retenido']);
                    if ($r['empleado_id'] === null || !in_array($r['asociacion'], Engine::ASOCIADO, true)) {
                        $retSinEmp = Dec::add($retSinEmp, (string) $p['retenido']);
                    }
                }
            }
            $emp = array_values(array_filter($empleados, static fn ($x) => $grupo === null || $x->grupo === $grupo));
            $pen = array_values(array_filter($pensiones, static fn ($x) => $grupo === null || $x->grupo === $grupo));
            $identTotal = $sumOf($ident);
            $penTotal = $sumOf($tp);
            $penIdentTotal = $sumOf($penIdent);
            $penBanco = [];
            foreach (Engine::BANCOS as $b) {
                $penBanco[$b] = $sumOf(array_filter($tp, static fn ($t) => $t['banco'] === $b));
            }
            return [
                'rrhh' => ['percepciones' => Dec::sum(array_map(static fn ($r) => (string) $r['percepciones'], array_filter($rr, static fn ($r) => $r['percepciones'] !== null))),
                           'deducciones' => Dec::sum(array_map(static fn ($r) => (string) $r['deducciones'], array_filter($rr, static fn ($r) => $r['deducciones'] !== null))),
                           'neto' => $neto, 'registros' => count($rr), 'pension_retenida' => $retenido],
                'tesoreria_nomina' => ['total' => $total, 'identificado' => $identTotal, 'pendiente_identidad' => Dec::sub($total, $identTotal),
                                       'por_banco' => $porBanco, 'por_banco_identificado' => $porBancoIdent, 'registros' => count($tn)],
                'pensiones' => ['retenido' => $retenido, 'retenido_sin_empleado' => $retSinEmp, 'dispersado' => $penTotal, 'identificado' => $penIdentTotal,
                                'pendiente_identidad' => Dec::sub($penTotal, $penIdentTotal), 'por_banco' => $penBanco],
                'diferencia_global_nomina' => Dec::sub($total, $neto),
                'empleados' => Json::obj(self::countSorted(array_map(static fn ($x) => $x->estado, $emp))),
                'pensiones_estado' => Json::obj(self::countSorted(array_map(static fn ($x) => $x->estado, $pen))),
            ];
        };
        $totales = ['TOTAL' => $bloque(null)];
        foreach (Engine::GRUPOS as $g) {
            $present = array_filter($rrhh, static fn ($r) => $r['grupo'] === $g) !== []
                || array_filter([...$tesNom, ...$tesPen], static fn ($t) => $gOf($t) === $g) !== [];
            if ($present) {
                $totales[$g] = $bloque($g);
            }
        }
        $t = $totales['TOTAL'];
        foreach ($totales as $grupo => $b) {
            $neto = $b['rrhh']['neto'];
            $pag = $b['tesoreria_nomina']['total'];
            $st->controles[] = new LevelControl(4, 'NOMINA_RRHH_VS_TESORERIA', 'Neto de RH contra lo pagado por Tesorería (incluye pagos sin persona identificada)',
                $rrhh === [] ? 'INFORMATIVO' : Engine::cmp($neto, $pag, $tol), grupo: (string) $grupo, valorA: $neto, etiquetaA: 'Neto de RH', valorB: $pag,
                etiquetaB: 'Pagado', valorC: $b['tesoreria_nomina']['pendiente_identidad'], etiquetaC: 'Sin persona identificada', diferencia: Dec::sub($pag, $neto));
            $st->controles[] = new LevelControl(5, 'PENSION_RETENIDA_VS_DISPERSADA', 'Pensión retenida por RH contra la dispersada (incluye pagos sin persona identificada)',
                $rrhh === [] ? 'INFORMATIVO' : Engine::cmp($b['pensiones']['retenido'], $b['pensiones']['dispersado'], $tol), grupo: (string) $grupo,
                valorA: $b['pensiones']['retenido'], etiquetaA: 'Retenido por RH', valorB: $b['pensiones']['dispersado'], etiquetaB: 'Dispersado',
                valorC: $b['pensiones']['pendiente_identidad'], etiquetaC: 'Sin persona identificada',
                diferencia: Dec::sub($b['pensiones']['dispersado'], $b['pensiones']['retenido']));
        }
        $pensionCubierta = $rrhh !== [];
        $pendPorGrupo = '0';
        foreach ($totales as $grupo => $b) {
            if ($grupo === 'TOTAL' || !Dec::gt($b['pensiones']['pendiente_identidad'], $tol)) {
                continue;
            }
            $pendPorGrupo = Dec::add($pendPorGrupo, $b['pensiones']['pendiente_identidad']);
            if (!in_array($grupo, Engine::GRUPOS, true) || !Dec::within($b['pensiones']['retenido'], $b['pensiones']['dispersado'], $tol)) {
                $pensionCubierta = false;
            }
        }
        if (!Dec::within($pendPorGrupo, $t['pensiones']['pendiente_identidad'], $tol)) {
            $pensionCubierta = false;
        }
        foreach ([['NOMINA', 'tesoreria_nomina'], ['PENSION', 'pensiones']] as [$cat, $clave]) {
            $pend = $t[$clave]['pendiente_identidad'];
            if (Dec::gt($pend, $tol)) {
                $st->inc("PENDIENTE_IDENTIDAD:$cat", $cat === 'NOMINA' ? 'IMPORTE_PENDIENTE_IDENTIDAD' : 'IMPORTE_PENSION_PENDIENTE_IDENTIDAD',
                    $cat === 'PENSION' && $pensionCubierta ? 'INFO' : 'ADVERTENCIA',
                    $cat === 'NOMINA' ? 4 : 5, 'Hay importes dispersados sin persona identificada: cuentan en el total del banco pero no se asignan a nadie.',
                    importe: $pend);
            }
        }
        $evaluables = array_values(array_filter($empleados, static fn ($x) => $x->estado !== 'NO_APLICA'));
        $correctos = array_values(array_filter($evaluables, static fn ($x) => $x->estado === 'CORRECTO'));
        $pendientes = array_values(array_filter($evaluables, static fn ($x) => in_array($x->estado, ['PENDIENTE_IDENTIDAD', 'CONFLICTO_IDENTIDAD'], true)));
        $conDif = array_values(array_filter($evaluables, static fn ($x) => !in_array($x->estado, ['CORRECTO', 'PENDIENTE_IDENTIDAD', 'CONFLICTO_IDENTIDAD'], true)));
        $esperadoEval = '0';
        foreach ($evaluables as $x) {
            if (Dec::gt($x->netoEsperado, '0')) {
                $esperadoEval = Dec::add($esperadoEval, $x->netoEsperado);
            }
        }
        $nEval = count($evaluables);
        $controlKeys = array_map(static fn ($k) => 'N' . $k->nivel . ':' . $k->estado, $st->controles);
        $resumen = [
            'empleados' => count($empleados), 'evaluables' => $nEval, 'correctos' => count($correctos), 'pendientes_identidad' => count($pendientes),
            'con_diferencia' => count($conDif),
            'pct_conciliado' => $nEval ? Dec::divToPlaces(Dec::mul('100', (string) count($correctos)), (string) $nEval) : null,
            'pct_pendiente_identidad' => $nEval ? Dec::divToPlaces(Dec::mul('100', (string) count($pendientes)), (string) $nEval) : null,
            'pct_monto_conciliado' => Dec::gt($esperadoEval, '0')
                ? Dec::divToPlaces(Dec::mul('100', Dec::sum(array_map(static fn ($x) => $x->netoEsperado, $correctos))), $esperadoEval) : null,
            'por_estado' => Json::obj(self::countSorted(array_map(static fn ($x) => $x->estado, $empleados))),
            'alertas' => Json::obj(self::countSorted(array_values(array_filter(array_map(static fn ($x) => $x->alerta, $empleados), static fn ($a) => $a !== null && $a !== '')))),
            'pensiones_por_estado' => Json::obj(self::countSorted(array_map(static fn ($x) => $x->estado, $pensiones))),
            'controles' => Json::obj(self::countSorted($controlKeys)),
        ];
        return [$totales, $resumen];
    }

    /** @param list<string> $values @return array<string,int> */
    private static function countSorted(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            $out[(string) $v] = ($out[(string) $v] ?? 0) + 1;
        }
        ksort($out, SORT_STRING);
        return $out;
    }
}
