<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Conciliation;

use ConciliaRH\Engine\Support\Dec;

final class PayrollLevel
{
    public function __construct(private readonly State $st)
    {
    }

    /**
     * @param list<array<string,mixed>> $rrhh
     * @param list<array<string,mixed>> $tesNom
     * @return list<EmployeeResult>
     */
    public function run(array $rrhh, array $tesNom): array
    {
        $st = $this->st;
        $tol = $st->tol;
        $pagos = [];
        $pend = [];
        foreach ($tesNom as $t) {
            if ($t['importe'] === null || $t['importe_estado'] !== 'OK') {
                continue;
            }
            if ($t['empleado_id'] !== null && in_array($t['asociacion'], Engine::ASOCIADO, true)) {
                $pagos[(int) $t['empleado_id']][] = $t;
            } else {
                $pend[] = $t;
                $st->fuentes[] = new Source(null, 'PENDIENTE_NOMINA', (int) $t['id'], (int) $t['archivo_id'], $t['hoja'], $t['fila'], $t['banco'], null,
                    (string) $t['importe'], (string) $t['importe'], $t['asociacion']);
            }
        }
        $porEmp = [];
        $sinEmpleado = [];
        foreach ($rrhh as $r) {
            if ($r['empleado_id'] !== null && in_array($r['asociacion'], Engine::ASOCIADO, true)) {
                $porEmp[(int) $r['empleado_id']][] = $r;
            } else {
                $sinEmpleado[] = $r;
            }
        }
        foreach ($sinEmpleado as $r) {
            $st->inc("RRHH_SIN_EMPLEADO:{$r['id']}", 'RRHH_SIN_EMPLEADO', 'ERROR', 4,
                'Un registro del tabulado RRHH no está asociado a un empleado: su neto no puede conciliarse por persona.', grupo: $r['grupo'],
                importe: $r['neto'] !== null ? (string) $r['neto'] : null, detalle: ['registro' => (int) $r['id'], 'archivo' => (int) $r['archivo_id'], 'fila' => $r['fila']]);
        }
        $porNombre = [];
        foreach ($porEmp as $e => $regs) {
            foreach ($regs as $r) {
                if ($r['nombre_key'] !== null && $r['nombre_key'] !== '') {
                    $porNombre[(string) $r['nombre_key']][$e] = true;
                }
            }
        }
        [$posibles, $compartidos] = self::pendingByEmployee($pend, $porNombre);
        $ids = array_keys($porEmp + $pagos);
        sort($ids);
        $res = [];
        foreach ($ids as $e) {
            $emp = $st->employee($e);
            $regs = $porEmp[$e] ?? [];
            $ps = $pagos[$e] ?? [];
            usort($ps, static fn ($x, $y) => $x['id'] <=> $y['id']);
            $pagado = ['SANTANDER' => '0', 'BBVA' => '0', 'INTERBANCARIA' => '0', 'OTROS' => '0'];
            foreach ($ps as $t) {
                $b = Engine::bankKey($t['banco']);
                $pagado[$b] = Dec::add($pagado[$b], (string) $t['importe']);
                $st->fuentes[] = new Source($e, 'TESORERIA_NOMINA', (int) $t['id'], (int) $t['archivo_id'], $t['hoja'], $t['fila'], $t['banco'], null,
                    (string) $t['importe'], (string) $t['importe'], $t['asociacion']);
            }
            foreach ($regs as $r) {
                $st->fuentes[] = new Source($e, 'RRHH', (int) $r['id'], (int) $r['archivo_id'], $r['hoja'], $r['fila'], null, null,
                    $r['neto'] !== null ? (string) $r['neto'] : null, $r['neto'] !== null ? (string) $r['neto'] : '0', $r['asociacion']);
            }
            $total = Dec::sum(array_values($pagado));
            $r0 = $regs[0] ?? null;
            $detalle = ['regla' => ''];
            $alerta = null;
            if ($regs === []) {
                [$estado, $esperado] = ['PAGO_SIN_RESPALDO_RRHH', '0'];
                $detalle['regla'] = 'Pago identificado sin registro RRHH en la fuente del lote';
            } elseif (count($regs) > 1 || array_filter($regs, static fn ($r) => !$r['importes_validos'] || $r['neto'] === null) !== []) {
                $estado = 'ERROR_DATOS_RRHH';
                $esperado = '0';
                foreach ($regs as $r) {
                    if ($r['neto'] !== null) {
                        $esperado = Dec::add($esperado, (string) $r['neto']);
                    }
                }
                $detalle['regla'] = 'Registro RRHH duplicado o con importes inválidos';
            } else {
                $esperado = (string) $r0['neto'];
                $dif = Dec::sub($total, $esperado);
                if (Dec::lt($esperado, Dec::neg($tol))) {
                    $estado = 'ERROR_DATOS_RRHH';
                    $detalle['regla'] = 'Neto RRHH negativo';
                } elseif (Dec::le(Dec::abs($esperado), $tol) && Dec::le($total, $tol)) {
                    $estado = 'NO_APLICA';
                    $detalle['regla'] = 'Sin neto a pagar y sin pagos';
                } elseif (Dec::le(Dec::abs($dif), $tol)) {
                    $estado = 'CORRECTO';
                    $detalle['regla'] = '|pagado − esperado| ≤ tolerancia';
                    $bancos = array_unique(array_map(static fn ($t) => (string) $t['banco'], $ps));
                    if (count($bancos) > 1 || count($ps) > 1) {
                        $alerta = 'PAGOS_DIVIDIDOS';
                    }
                } elseif (Dec::gt($esperado, $tol) && Dec::le($total, $tol)) {
                    $estado = 'NO_PAGADO';
                    $detalle['regla'] = 'Esperado > tolerancia y sin pagos identificados';
                } elseif (Dec::le($esperado, $tol) && Dec::gt($total, $tol)) {
                    $estado = 'PAGO_SIN_RESPALDO_RRHH';
                    $detalle['regla'] = 'Esperado = 0 y pagado > tolerancia';
                } elseif (Dec::lt($dif, Dec::neg($tol))) {
                    $estado = 'PAGO_MENOR';
                    $detalle['regla'] = 'pagado − esperado < −tolerancia';
                } else {
                    $estado = 'PAGO_MAYOR';
                    $detalle['regla'] = 'pagado − esperado > tolerancia';
                    $dup = self::duplicate($ps, $total, $esperado, $tol);
                    if ($dup !== null) {
                        [$estado, $alerta] = ['POSIBLE_PAGO_DUPLICADO', 'POSIBLE_PAGO_DUPLICADO'];
                        $detalle['duplicado'] = $dup;
                    }
                }
                if (($r0['fecha_baja'] ?? null) !== null && $r0['fecha_baja'] !== '' && $estado !== 'CORRECTO') {
                    $alerta = $alerta ?? 'BAJA_EN_RRHH';
                }
            }
            $posible = '0';
            $candPend = $posibles[$e] ?? [];
            if (!empty($compartidos[$e]) && in_array($estado, ['NO_PAGADO', 'PAGO_MENOR'], true)) {
                $conflict = array_filter($compartidos[$e], static fn ($t) => $t['asociacion'] === 'CONFLICTO') !== [];
                $alerta = $alerta ?? ($conflict ? 'REGISTRO_EN_CONFLICTO' : 'POSIBLE_PAGO_PENDIENTE');
                $detalle['pendientes_compartidos'] = array_map(static fn ($t) => (int) $t['id'], $compartidos[$e]);
            }
            if ($candPend !== [] && in_array($estado, ['NO_PAGADO', 'PAGO_MENOR'], true)) {
                $posible = Dec::sum(array_map(static fn ($t) => (string) $t['importe'], $candPend));
                $conflicto = array_filter($candPend, static fn ($t) => $t['asociacion'] === 'CONFLICTO') !== [];
                if (Dec::le(Dec::abs(Dec::sub(Dec::add($total, $posible), $esperado)), $tol)) {
                    $detalle['estado_matematico'] = $estado;
                    $estado = $conflicto ? 'CONFLICTO_IDENTIDAD' : 'PENDIENTE_IDENTIDAD';
                    $detalle['regla'] .= '; un pago pendiente de identidad completaría exactamente el esperado';
                }
                $alerta = $conflicto ? 'REGISTRO_EN_CONFLICTO' : 'POSIBLE_PAGO_PENDIENTE';
                $detalle['pendientes'] = array_map(static fn ($t) => (int) $t['id'], $candPend);
            }
            $pens = false;
            foreach ($regs as $r) {
                foreach ($r['pensiones'] as $p) {
                    $pens = $pens || Dec::gt((string) $p['retenido'], '0');
                }
            }
            $res[] = new EmployeeResult($e, in_array($emp['grupo'], Engine::GRUPOS, true) ? (string) $emp['grupo'] : 'OTRO', (string) $emp['numero'],
                $r0['area'] ?? null, $r0 !== null ? (int) $r0['id'] : null, $r0['percepciones'] ?? null, $r0['deducciones'] ?? null, $esperado, $pagado,
                count($ps), $estado, $alerta, $posible, $pens, $detalle);
        }
        return $res;
    }

    /**
     * @param list<array<string,mixed>> $pend
     * @param array<string,array<int,true>> $porNombre
     * @return array{0:array<int,list<array<string,mixed>>>,1:array<int,list<array<string,mixed>>>}
     */
    private static function pendingByEmployee(array $pend, array $porNombre): array
    {
        $exclusivos = [];
        $compartidos = [];
        foreach ($pend as $t) {
            $cands = array_fill_keys(array_map('intval', $t['candidatos']), true);
            if ($cands === [] && !$t['manual'] && ($t['nombre_key'] ?? '') !== '' && count($porNombre[(string) $t['nombre_key']] ?? []) === 1) {
                $cands = $porNombre[(string) $t['nombre_key']];
            }
            foreach (array_keys($cands) as $e) {
                if (count($cands) === 1) {
                    $exclusivos[$e][] = $t;
                } else {
                    $compartidos[$e][] = $t;
                }
            }
        }
        return [$exclusivos, $compartidos];
    }

    /**
     * @param list<array<string,mixed>> $ps
     * @return array<string,mixed>|null
     */
    private static function duplicate(array $ps, string $total, string $esperado, string $tol): ?array
    {
        $n = count($ps);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                [$a, $b] = [$ps[$i], $ps[$j]];
                if (!Dec::eq((string) $a['importe'], (string) $b['importe'])) {
                    continue;
                }
                $mismaCuenta = $a['cuenta_hmac'] !== null && $a['cuenta_hmac'] === $b['cuenta_hmac'];
                $mismaRef = $a['referencia'] !== null && $a['referencia'] === $b['referencia'] && $a['banco'] === $b['banco'];
                if (($mismaCuenta || $mismaRef) && Dec::le(Dec::abs(Dec::sub(Dec::sub($total, (string) $a['importe']), $esperado)), $tol)) {
                    $senales = [];
                    if ($mismaCuenta) {
                        $senales[] = 'MISMA_CUENTA';
                    }
                    if ($mismaRef) {
                        $senales[] = 'MISMA_REFERENCIA';
                    }
                    return ['registros' => [(int) $a['id'], (int) $b['id']], 'importe' => (string) $a['importe'], 'senales' => $senales];
                }
            }
        }
        return null;
    }
}
