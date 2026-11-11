<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Engine;

use ConciliaRH\Engine\Conciliation\EmployeeResult;
use ConciliaRH\Engine\Conciliation\Engine;
use ConciliaRH\Engine\Conciliation\InputFingerprint;
use ConciliaRH\Engine\Conciliation\PolicyLevels;
use ConciliaRH\Engine\Conciliation\Result;
use ConciliaRH\Engine\Support\Dec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConciliationEngineTest extends TestCase
{
    private const TOL = '0.01';

    /** @return array<int,array<string,mixed>> */
    private static function employees(): array
    {
        $out = [];
        for ($i = 1; $i < 60; $i++) {
            $out[$i] = ['id' => $i, 'numero' => sprintf('9%04d', $i), 'grupo' => $i < 50 ? 'POLICIA' : 'BOMBEROS', 'nombre_key' => "PERSONA $i"];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function lot(): array
    {
        return ['archivos' => [], 'rrhh' => [], 'tes' => [], 'globales' => [], 'egreso' => [], 'rel' => [], 'id' => 1000, 'emp' => self::employees()];
    }

    private static function nid(array &$b): int
    {
        return ++$b['id'];
    }

    /** @param array<string,mixed> $kw */
    private static function archivo(array &$b, int $aid, string $tipo, array $kw = []): void
    {
        $b['archivos'][] = ['archivo_id' => $aid, 'importacion_id' => $aid + 5000, 'tipo_registro' => $tipo, 'estado_archivo' => $kw['estado'] ?? 'ANALIZADO',
                            'clasificacion' => $kw['clasif'] ?? 'NUEVO', 'relacionado_id' => $kw['rel'] ?? null, 'relacionado_mismo_lote' => $kw['mismo_lote'] ?? false,
                            'derivado' => $kw['derivado'] ?? false, 'grupo' => $kw['grupo'] ?? null, 'banco' => $kw['banco'] ?? null,
                            'categoria' => $kw['categoria'] ?? null, 'total_declarado' => $kw['total_declarado'] ?? null, 'poblacion' => $kw['poblacion'] ?? null];
    }

    /**
     * @param array<int,string|array{0:string,1:string}> $netos
     * @param array<string,mixed> $kw
     */
    private static function rrhhFile(array &$b, int $aid = 100, string $grupo = 'POLICIA', array $netos = [], bool $derivado = false, array $kw = []): void
    {
        self::archivo($b, $aid, 'RRHH', [...$kw, 'grupo' => $grupo, 'derivado' => $derivado]);
        foreach ($netos as $e => $neto) {
            $pens = [];
            if (is_array($neto)) {
                [$neto, $pen] = $neto;
                $pens = [['codigo' => '044', 'importe_original' => Dec::neg($pen), 'retenido' => $pen]];
            }
            $b['rrhh'][] = self::rrhhRow(self::nid($b), $aid, $e, 'ASOCIADO_AUTOMATICO', $grupo, $b['emp'][$e]['numero'], $b['emp'][$e]['nombre_key'],
                Dec::add($neto, '10'), '10', $neto, $e + 3, $pens);
        }
    }

    /** @param list<array<string,string>> $pens @return array<string,mixed> */
    private static function rrhhRow(int $id, int $aid, ?int $e, string $asoc, string $grupo, ?string $numero, ?string $nombre, string $perc, string $ded,
                                    string $neto, int $fila, array $pens): array
    {
        return ['id' => $id, 'archivo_id' => $aid, 'empleado_id' => $e, 'asociacion' => $asoc, 'grupo' => $grupo, 'numero' => $numero, 'nombre_key' => $nombre,
                'area' => 'AREA', 'percepciones' => $perc, 'deducciones' => $ded, 'neto' => $neto, 'importes_validos' => true, 'fecha_baja' => null,
                'hoja' => 'Tabla', 'fila' => $fila, 'pensiones' => $pens];
    }

    /**
     * @param list<array{0:int,1:string}> $pagos
     * @param list<array{0:string,1:string,2:list<int>,3:string}> $pendientes
     */
    private static function disp(array &$b, int $aid, string $banco = 'SANTANDER', array $pagos = [], string $categoria = 'NOMINA', ?string $declarado = 'auto',
                                 array $pendientes = [], array $kw = []): void
    {
        $regs = [];
        foreach ($pagos as [$e, $imp]) {
            $regs[] = ['id' => self::nid($b), 'archivo_id' => $aid, 'empleado_id' => $e, 'asociacion' => 'ASOCIADO_AUTOMATICO', 'candidatos' => [],
                       'categoria' => $categoria, 'banco' => $banco, 'importe' => $imp, 'importe_estado' => 'OK', 'cuenta_hmac' => "c$e", 'referencia' => null,
                       'nombre_key' => $b['emp'][$e]['nombre_key'], 'hoja' => 'Hoja', 'fila' => count($regs) + 8, 'manual' => false];
        }
        foreach ($pendientes as [$imp, $estado, $cands, $nombre]) {
            $regs[] = ['id' => self::nid($b), 'archivo_id' => $aid, 'empleado_id' => null, 'asociacion' => $estado, 'candidatos' => $cands,
                       'categoria' => $categoria, 'banco' => $banco, 'importe' => $imp, 'importe_estado' => 'OK', 'cuenta_hmac' => null, 'referencia' => null,
                       'nombre_key' => $nombre, 'hoja' => 'Hoja', 'fila' => count($regs) + 8, 'manual' => false];
        }
        $total = Dec::sum(array_map(static fn ($t) => $t['importe'], $regs));
        self::archivo($b, $aid, 'TESORERIA', [...$kw, 'banco' => $banco, 'categoria' => $categoria,
            'total_declarado' => $declarado === 'auto' ? $total : $declarado]);
        foreach ($regs as $r) {
            $b['tes'][] = $r;
        }
    }

    private static function globalPolicy(array &$b, int $aid = 200, string $grupo = 'POLICIA', string $neto = '0', ?int $empleados = null): void
    {
        self::archivo($b, $aid, 'POLIZA_GLOBAL', ['grupo' => $grupo]);
        $ded = Dec::mul('10', (string) count(array_filter($b['rrhh'], static fn ($r) => $r['grupo'] === $grupo)));
        $b['globales'][] = ['archivo_id' => $aid, 'grupo' => $grupo, 'percepciones' => Dec::add($neto, $ded), 'deducciones' => $ded, 'neto_declarado' => $neto,
                            'empleados_declarados' => $empleados];
    }

    /** @param list<array{0:string,1:?string}> $hojas */
    private static function egressPolicy(array &$b, int $aid = 300, array $hojas = []): void
    {
        self::archivo($b, $aid, 'POLIZA_EGRESO');
        foreach ($hojas as $i => [$nombre, $monto]) {
            $b['egreso'][] = ['archivo_id' => $aid, 'hoja_indice' => $i, 'hoja_nombre' => $nombre, 'monto_bancos' => $monto,
                              'monto_nombre' => PolicyLevels::amountFromName($nombre)];
        }
    }

    /** @return array<string,mixed> */
    private static function inputs(array $b, string $tol = self::TOL): array
    {
        return ['lote_id' => 1, 'tolerancia' => $tol, 'archivos' => $b['archivos'], 'rrhh' => $b['rrhh'], 'tesoreria' => $b['tes'], 'globales' => $b['globales'],
                'egreso' => $b['egreso'], 'empleados' => $b['emp'], 'relaciones_manuales' => $b['rel']];
    }

    private static function conciliate(array $b, string $tol = self::TOL): Result
    {
        return Engine::execute(self::inputs($b, $tol));
    }

    private static function emp(Result $r, int $e): EmployeeResult
    {
        foreach ($r->empleados as $x) {
            if ($x->empleadoId === $e) {
                return $x;
            }
        }
        self::fail("Sin resultado para el empleado $e");
    }

    /** @return list<string> */
    private static function tipos(Result $r): array
    {
        $t = array_map(static fn ($i) => $i->tipo, $r->incidencias);
        sort($t);
        return $t;
    }

    /** @return array<int,string> */
    private static function roles(Result $r): array
    {
        $out = [];
        foreach ($r->archivos as $a) {
            $out[$a->archivoId] = $a->rol;
        }
        return $out;
    }

    /** @return list<\ConciliaRH\Engine\Conciliation\LevelControl> */
    private static function controls(Result $r, string $codigo): array
    {
        return array_values(array_filter($r->controles, static fn ($k) => $k->codigo === $codigo));
    }

    private static function assertDec(?string $expected, ?string $actual, string $msg = ''): void
    {
        if ($expected === null || $actual === null) {
            self::assertSame($expected, $actual, $msg);
            return;
        }
        self::assertTrue(Dec::eq($expected, $actual), $msg !== '' ? $msg : "Se esperaba $expected y se obtuvo $actual");
    }

    private static function toArray(mixed $v): mixed
    {
        return json_decode((string) json_encode($v), true);
    }

    public function testCase1AllCorrect(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '1000.00', 2 => '2000.50']);
        self::globalPolicy($b, neto: '3000.50', empleados: 2);
        self::disp($b, 400, pagos: [[1, '1000.00'], [2, '2000.50']]);
        self::egressPolicy($b, hojas: [['SANTANDER $3,000.50', '3000.50']]);
        $r = self::conciliate($b);
        self::assertSame(['CORRECTO', 'CORRECTO'], array_map(static fn ($x) => $x->estado, $r->empleados));
        self::assertSame('COMPLETADA', $r->estado);
        self::assertSame([], $r->incidencias);
        self::assertSame('100.00', $r->resumen['pct_conciliado']);
        $ctl = [];
        foreach ($r->controles as $k) {
            if (in_array($k->nivel, [1, 2, 3], true)) {
                $ctl[$k->codigo] = $k->estado;
            }
        }
        ksort($ctl);
        $expected = ['RRHH_GLOBAL_PERCEPCIONES' => 'CUADRA', 'RRHH_GLOBAL_DEDUCCIONES' => 'CUADRA', 'RRHH_GLOBAL_NETO' => 'CUADRA',
                     'RRHH_GLOBAL_EMPLEADOS' => 'CUADRA', 'DISPERSION_TOTAL_ARCHIVO' => 'CUADRA', 'POLIZA_EGRESO_VS_DISPERSION' => 'CUADRA',
                     'GLOBAL_VS_EGRESO_NETO' => 'CUADRA'];
        ksort($expected);
        self::assertSame($expected, $ctl);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function lessMoreAndToleranceProvider(): array
    {
        return ['900.00' => ['900.00', 'PAGO_MENOR'], '1100.00' => ['1100.00', 'PAGO_MAYOR'], '1000.01' => ['1000.01', 'CORRECTO'],
                '1000.02' => ['1000.02', 'PAGO_MAYOR'], '999.99' => ['999.99', 'CORRECTO'], '999.98' => ['999.98', 'PAGO_MENOR']];
    }

    #[DataProvider('lessMoreAndToleranceProvider')]
    public function testCase2To25LessMoreAndTolerance(string $pagado, string $estado): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '1000.00']);
        self::disp($b, 400, pagos: [[1, $pagado]]);
        $x = self::emp(self::conciliate($b), 1);
        self::assertSame($estado, $x->estado);
        self::assertDec(Dec::sub($pagado, '1000.00'), $x->diferencia());
    }

    public function testToleranceIsConfigurable(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '1000.00']);
        self::disp($b, 400, pagos: [[1, '1000.05']]);
        self::assertSame('CORRECTO', self::emp(self::conciliate($b, '0.10'), 1)->estado);
        self::assertSame('PAGO_MAYOR', self::emp(self::conciliate($b, '0.01'), 1)->estado);
    }

    public function testCase4And5NotPaidAndNoRrhhBacking(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '1000.00', 2 => '0.00']);
        self::disp($b, 400, pagos: [[3, '700.00'], [2, '50.00']]);
        $r = self::conciliate($b);
        self::assertSame('NO_PAGADO', self::emp($r, 1)->estado);
        self::assertSame('PAGO_SIN_RESPALDO_RRHH', self::emp($r, 3)->estado);
        self::assertNull(self::emp($r, 3)->registroRrhhId);
        self::assertSame('PAGO_SIN_RESPALDO_RRHH', self::emp($r, 2)->estado, 'Esperado 0 y pagado > tolerancia');
        self::assertEmpty(array_diff(['NO_PAGADO', 'PAGO_SIN_RESPALDO_RRHH'], self::tipos($r)));
    }

    public function testCase6And7SplitAndMultiplePaymentsAreSummed(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '1000.00', 2 => '500.00']);
        self::disp($b, 400, 'SANTANDER', pagos: [[1, '600.00'], [2, '200.00'], [2, '300.00']]);
        self::disp($b, 401, 'INTERBANCARIA', pagos: [[1, '400.00']]);
        $r = self::conciliate($b);
        $a = self::emp($r, 1);
        self::assertSame('CORRECTO', $a->estado);
        self::assertDec('600.00', $a->pagado['SANTANDER']);
        self::assertDec('400.00', $a->pagado['INTERBANCARIA']);
        self::assertSame(2, $a->pagos);
        self::assertSame('PAGOS_DIVIDIDOS', $a->alerta);
        self::assertSame('CORRECTO', self::emp($r, 2)->estado, 'Dos pagos válidos del mismo banco no son duplicado');
        $fuentes = [];
        foreach ($r->fuentes as $f) {
            if ($f->empleadoId === 1 && $f->tipo === 'TESORERIA_NOMINA') {
                $fuentes[] = [$f->archivoId, $f->banco, Dec::key($f->importe)];
            }
        }
        sort($fuentes);
        self::assertSame([[400, 'SANTANDER', Dec::key('600.00')], [401, 'INTERBANCARIA', Dec::key('400.00')]], $fuentes);
    }

    public function testCase8PossibleDuplicatePayment(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '1000.00']);
        self::disp($b, 400, pagos: [[1, '1000.00'], [1, '1000.00']]);
        $x = self::emp(self::conciliate($b), 1);
        self::assertSame(['POSIBLE_PAGO_DUPLICADO', 'POSIBLE_PAGO_DUPLICADO'], [$x->estado, $x->alerta]);
        self::assertSame(['MISMA_CUENTA'], self::toArray($x->detalle)['duplicado']['senales']);
    }

    public function testCase9CompensationIsTwoIncidentsEvenIfTotalMatches(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '1000.00', 2 => '1000.00']);
        self::globalPolicy($b, neto: '2000.00');
        self::disp($b, 400, pagos: [[1, '1100.00'], [2, '900.00']]);
        $r = self::conciliate($b);
        $nivel4 = array_values(array_filter(self::controls($r, 'NOMINA_RRHH_VS_TESORERIA'), static fn ($k) => $k->grupo === 'TOTAL'))[0];
        self::assertSame('CUADRA', $nivel4->estado, 'El total global cuadra…');
        self::assertSame(['PAGO_MAYOR', 'PAGO_MENOR'], [self::emp($r, 1)->estado, self::emp($r, 2)->estado]);
        $n4 = array_map(static fn ($i) => $i->tipo, array_values(array_filter($r->incidencias, static fn ($i) => $i->nivel === 4)));
        sort($n4);
        self::assertSame(['PAGO_MAYOR', 'PAGO_MENOR'], $n4, '…pero son 2 incidencias');
        self::assertSame('COMPLETADA_CON_INCIDENCIAS', $r->estado);
    }

    public function testCase10PendingIdentityCountsInBankNotInPerson(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '1000.00']);
        self::disp($b, 400, 'INTERBANCARIA', pagos: [[1, '1000.00']], pendientes: [['5000.00', 'NO_IDENTIFICADO', [], 'OTRA PERSONA']]);
        $r = self::conciliate($b);
        $t = $r->totales['TOTAL']['tesoreria_nomina'];
        self::assertSame(['6000.00', '1000.00', '5000.00'], [$t['total'], $t['identificado'], $t['pendiente_identidad']]);
        self::assertDec('1000.00', self::emp($r, 1)->pagadoTotal());
        self::assertSame('CORRECTO', self::emp($r, 1)->estado);
        self::assertContains('IMPORTE_PENDIENTE_IDENTIDAD', self::tipos($r));
        $pend = array_values(array_map(static fn ($f) => Dec::key($f->importe), array_filter($r->fuentes, static fn ($f) => $f->tipo === 'PENDIENTE_NOMINA')));
        self::assertSame([Dec::key('5000.00')], $pend);
    }

    public function testCase11PendingThatLooksLikeTheMissingPayment(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '1000.00', 2 => '800.00', 3 => '700.00']);
        self::disp($b, 400, 'INTERBANCARIA', pendientes: [['1000.00', 'SUGERENCIA', [1], 'PERSONA 1'], ['800.00', 'NO_IDENTIFICADO', [], 'PERSONA 2'],
                                                          ['50.00', 'NO_IDENTIFICADO', [], 'PERSONA 3']]);
        $r = self::conciliate($b);
        [$a, $c, $d] = [self::emp($r, 1), self::emp($r, 2), self::emp($r, 3)];
        self::assertSame(['PENDIENTE_IDENTIDAD', 'NO_PAGADO'], [$a->estado, $a->detalle['estado_matematico']]);
        self::assertDec('0', $a->pagadoTotal());
        self::assertDec('1000.00', $a->posiblePendiente);
        self::assertSame('PENDIENTE_IDENTIDAD', $c->estado);
        self::assertSame(['NO_PAGADO', 'POSIBLE_PAGO_PENDIENTE'], [$d->estado, $d->alerta], 'El resultado matemático no cambia si no completa');
    }

    public function testConflictIdentity(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '1000.00']);
        self::disp($b, 400, pendientes: [['1000.00', 'CONFLICTO', [1], 'PERSONA 1']]);
        self::assertSame('CONFLICTO_IDENTIDAD', self::emp(self::conciliate($b), 1)->estado);
        $b2 = self::lot();
        self::rrhhFile($b2, netos: [1 => '1000.00']);
        self::disp($b2, 400, pendientes: [['1000.00', 'CONFLICTO', [1, 2], 'PERSONA 1']]);
        $x = self::emp(self::conciliate($b2), 1);
        self::assertSame(['NO_PAGADO', 'REGISTRO_EN_CONFLICTO'], [$x->estado, $x->alerta], 'Con dos candidatos no se reclasifica: sólo alerta');
    }

    public function testRrhhDataErrors(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '-50.00', 2 => '0.00']);
        $b['rrhh'][1]['fecha_baja'] = '2030-01-01';
        $r = self::conciliate($b);
        self::assertSame('ERROR_DATOS_RRHH', self::emp($r, 1)->estado);
        self::assertSame('NO_APLICA', self::emp($r, 2)->estado);
    }

    public function testCase12And13GlobalCorrectAndIncorrect(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '1000.00', 2 => '2000.00']);
        self::globalPolicy($b, neto: '3500.00', empleados: 3);
        $r = self::conciliate($b);
        $ctl = [];
        foreach ($r->controles as $k) {
            if ($k->nivel === 1) {
                $ctl[$k->codigo] = $k;
            }
        }
        self::assertSame('NO_CUADRA', $ctl['RRHH_GLOBAL_NETO']->estado);
        self::assertDec('-500.00', $ctl['RRHH_GLOBAL_NETO']->diferencia);
        self::assertSame('NO_CUADRA', $ctl['RRHH_GLOBAL_EMPLEADOS']->estado);
        self::assertEmpty(array_diff(['RRHH_POLIZA_NETO_NO_CUADRA', 'RRHH_POLIZA_EMPLEADOS_NO_CUADRA'], self::tipos($r)));
    }

    public function testCase14And15BankPolicyCorrectAndIncorrectWithInterbankFromSantanderSheet(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '1000.00', 2 => '300.00', 3 => '200.00']);
        self::disp($b, 400, 'SANTANDER', pagos: [[1, '1000.00']]);
        self::disp($b, 401, 'INTERBANCARIA', pagos: [[2, '300.00']]);
        self::disp($b, 402, 'BBVA', pagos: [[3, '200.00']]);
        self::egressPolicy($b, hojas: [['SANTANDER $1,000.00', '1000.00'], ['SANTANDER $ 300.00', '300.00'], ['BBVA $250.00', '250.00'], ['RETENCIONES ', '999.00']]);
        $eg = self::controls(self::conciliate($b), 'POLIZA_EGRESO_VS_DISPERSION');
        self::assertSame([['CUADRA', 400], ['CUADRA', 401], ['REVISION_MANUAL', null]],
            array_map(static fn ($k) => [$k->estado, self::toArray($k->detalle)['dispersion'] ?? null], $eg));
        self::assertSame([402], self::toArray($eg[2]->detalle)['candidatas'], 'Se propone la dispersión BBVA de otro importe, sin elegirla');
        $b['rel']['300:2'] = 402;
        $eg = self::controls(self::conciliate($b), 'POLIZA_EGRESO_VS_DISPERSION');
        self::assertSame('NO_CUADRA', $eg[2]->estado);
        self::assertDec('50.00', $eg[2]->diferencia);
        self::assertSame('MANUAL', self::toArray($eg[2]->detalle)['relacion']);
    }

    public function testAmbiguousPolicyGoesToManualReview(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '500.00', 2 => '500.00']);
        self::disp($b, 400, 'SANTANDER', pagos: [[1, '500.00']]);
        self::disp($b, 401, 'INTERBANCARIA', pagos: [[2, '500.00']]);
        self::egressPolicy($b, hojas: [['SANTANDER $500.00', '500.00']]);
        $k = self::controls(self::conciliate($b), 'POLIZA_EGRESO_VS_DISPERSION')[0];
        self::assertSame('REVISION_MANUAL', $k->estado);
        self::assertSame([400, 401], self::toArray($k->detalle)['candidatas']);
    }

    public function testCase16And17FileDeclaredTotal(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '100.00']);
        self::disp($b, 400, pagos: [[1, '100.00']], declarado: '100.00');
        self::disp($b, 401, 'BBVA', pagos: [[1, '5.00']], declarado: '7.00');
        $r = self::conciliate($b);
        $est = [];
        foreach (self::controls($r, 'DISPERSION_TOTAL_ARCHIVO') as $k) {
            $est[$k->referencia] = $k->estado;
        }
        self::assertSame(['archivo:400' => 'CUADRA', 'archivo:401' => 'NO_CUADRA'], $est);
        self::assertContains('DISPERSION_TOTAL_NO_CUADRA', self::tipos($r));
    }

    public function testLevel2GlobalVsEgresoExplainsTheGap(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '1000.00', 2 => '122.00']);
        self::globalPolicy($b, neto: '1122.00');
        self::disp($b, 400, pagos: [[1, '1000.00']]);
        self::egressPolicy($b, hojas: [['SANTANDER $1,000.00', '1000.00']]);
        $r = self::conciliate($b);
        $n2 = array_values(array_filter($r->controles, static fn ($k) => $k->nivel === 2))[0];
        self::assertSame('NO_CUADRA', $n2->estado);
        self::assertDec('122.00', $n2->diferencia);
        self::assertSame('NO_PAGADO', self::emp($r, 2)->estado, 'El nivel 4 dice quién explica la diferencia');
    }

    /** @return array<string,array{0:?string,1:string}> */
    public static function pensionsProvider(): array
    {
        return ['300.00' => ['300.00', 'CORRECTA'], '200.00' => ['200.00', 'PENSION_MENOR'], '400.00' => ['400.00', 'PENSION_MAYOR'],
                'None' => [null, 'NO_DISPERSADA']];
    }

    #[DataProvider('pensionsProvider')]
    public function testCase18To21Pensions(?string $disp, string $estado): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => ['1000.00', '300.00']]);
        self::disp($b, 400, pagos: [[1, '1000.00']]);
        if ($disp !== null) {
            self::disp($b, 401, 'SANTANDER', pagos: [[1, $disp]], categoria: 'PENSION');
        }
        $r = self::conciliate($b);
        $p = array_values(array_filter($r->pensiones, static fn ($x) => $x->empleadoId === 1))[0];
        self::assertSame($estado, $p->estado);
        self::assertSame('CORRECTO', self::emp($r, 1)->estado, 'La pensión NO se mezcla con el neto de nómina');
        self::assertDec('1000.00', self::emp($r, 1)->pagadoTotal());
    }

    public function testCase22PensionPendingIdentityAndWithoutRetention(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => ['1000.00', '300.00'], 2 => ['500.00', '100.00']]);
        self::disp($b, 401, 'INTERBANCARIA', categoria: 'PENSION', pagos: [[3, '80.00']], pendientes: [['300.00', 'NO_IDENTIFICADO', [], 'BENEFICIARIA']]);
        $r = self::conciliate($b);
        $pen = [];
        foreach ($r->pensiones as $p) {
            $pen[$p->empleadoId] = $p->estado;
        }
        ksort($pen);
        self::assertSame([1 => 'PENDIENTE_IDENTIDAD', 2 => 'NO_DISPERSADA', 3 => 'SIN_RETENCION_RRHH'], $pen);
        $t = $r->totales['TOTAL']['pensiones'];
        self::assertSame(['400.00', '380.00', '80.00', '300.00'], [$t['retenido'], $t['dispersado'], $t['identificado'], $t['pendiente_identidad']]);
        self::assertContains('IMPORTE_PENSION_PENDIENTE_IDENTIDAD', self::tipos($r));
    }

    public function testCase26And27ContentDuplicateAndAnnulledFileDoNotDoubleMoney(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '1000.00']);
        self::disp($b, 400, pagos: [[1, '1000.00']]);
        self::disp($b, 401, pagos: [[1, '1000.00']], kw: ['clasif' => 'DUPLICADO_CONTENIDO', 'rel' => 400, 'mismo_lote' => true]);
        self::disp($b, 402, pagos: [[1, '1000.00']], kw: ['estado' => 'ANULADO']);
        $r = self::conciliate($b);
        self::assertSame('CORRECTO', self::emp($r, 1)->estado);
        self::assertDec('1000.00', self::emp($r, 1)->pagadoTotal());
        $roles = self::roles($r);
        self::assertSame(['EXCLUIDO_DUPLICADO', 'EXCLUIDO_ANULADO'], [$roles[401], $roles[402]]);
    }

    public function testDuplicateOfAFileInAnotherLotIsStillThisLotsContent(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '1000.00']);
        self::disp($b, 400, pagos: [[1, '1000.00']], kw: ['clasif' => 'DUPLICADO_BINARIO', 'rel' => 9, 'mismo_lote' => false]);
        self::assertSame('CORRECTO', self::emp(self::conciliate($b), 1)->estado);
    }

    public function testDerivedAndSubsetTabuladosDoNotDuplicatePeople(): void
    {
        $b = self::lot();
        self::rrhhFile($b, 100, netos: [1 => '1000.00', 2 => '500.00']);
        self::rrhhFile($b, 101, netos: [1 => '1000.00'], derivado: true);
        self::rrhhFile($b, 102, netos: [2 => '500.00']);
        self::rrhhFile($b, 103, grupo: 'BOMBEROS', netos: [55 => '700.00']);
        self::disp($b, 400, pagos: [[1, '1000.00'], [2, '500.00'], [55, '700.00']]);
        $r = self::conciliate($b);
        $roles = self::roles($r);
        ksort($roles);
        self::assertSame([100 => 'FUENTE_RRHH', 101 => 'EXCLUIDO_DERIVADO', 102 => 'EXCLUIDO_SUBCONJUNTO', 103 => 'FUENTE_RRHH', 400 => 'TESORERIA_NOMINA'], $roles);
        self::assertSame(['CORRECTO', 'CORRECTO', 'CORRECTO'], array_map(static fn ($x) => $x->estado, $r->empleados));
        self::assertSame('1500.00', $r->totales['POLICIA']['rrhh']['neto']);
        self::assertSame('700.00', $r->totales['BOMBEROS']['rrhh']['neto']);
    }

    public function testPartialOverlapIsManualReview(): void
    {
        $b = self::lot();
        self::rrhhFile($b, 100, netos: [1 => '1000.00', 2 => '500.00']);
        self::rrhhFile($b, 101, netos: [2 => '500.00', 3 => '10.00']);
        $r = self::conciliate($b);
        self::assertSame('REVISION_MANUAL', $r->estado);
        self::assertContains('FUENTE_RRHH_AMBIGUA', self::tipos($r));
    }

    public function testLotWithoutRrhhIsNotConciliablePerPerson(): void
    {
        $b = self::lot();
        self::disp($b, 400, pagos: [[1, '1000.00']]);
        $r = self::conciliate($b);
        self::assertSame('REVISION_MANUAL', $r->estado);
        self::assertContains('LOTE_SIN_FUENTE_RRHH', self::tipos($r));
    }

    public function testCase34DeterminismAndOrderIndependence(): void
    {
        $b = self::lot();
        $netos = [];
        for ($i = 1; $i < 40; $i++) {
            $netos[$i] = sprintf('%d.%02d', 1000 + $i, $i);
        }
        self::rrhhFile($b, netos: $netos);
        $sant = [];
        for ($i = 1; $i < 40; $i += 2) {
            $sant[] = [$i, sprintf('%d.%02d', 1000 + $i, $i)];
        }
        self::disp($b, 400, pagos: $sant);
        $inter = [];
        for ($i = 2; $i < 40; $i += 2) {
            $inter[] = [$i, sprintf('%d.00', 900 + $i)];
        }
        self::disp($b, 401, 'INTERBANCARIA', pagos: $inter);
        $r1 = self::conciliate($b);
        $inp = self::inputs($b);
        mt_srand(7);
        shuffle($inp['rrhh']);
        mt_srand(8);
        shuffle($inp['tesoreria']);
        mt_srand();
        self::assertNotSame(self::inputs($b)['tesoreria'], $inp['tesoreria']);
        $r2 = Engine::execute($inp);
        $key = static fn (Result $r) => array_map(static fn ($x) => [$x->empleadoId, $x->estado, Dec::key($x->diferencia()), Dec::key($x->pagadoTotal())], $r->empleados);
        self::assertSame($key($r1), $key($r2));
        self::assertEquals($r1->totales, $r2->totales);
        self::assertEquals($r1->resumen, $r2->resumen);
        self::assertSame(array_map(static fn ($i) => $i->clave, $r1->incidencias), array_map(static fn ($i) => $i->clave, $r2->incidencias));
        self::assertSame(InputFingerprint::of(self::inputs($b)), InputFingerprint::of($inp), 'La huella no depende del orden');
        $inp['tesoreria'][0]['importe'] = Dec::add($inp['tesoreria'][0]['importe'], '0.01');
        self::assertNotSame(InputFingerprint::of(self::inputs($b)), InputFingerprint::of($inp), 'Cualquier cambio de entrada cambia la huella');
    }

    public function testPolicySheetParsing(): void
    {
        self::assertDec('1234567.89', PolicyLevels::amountFromName('SANTANDER $1,234,567.89'));
        self::assertNull(PolicyLevels::amountFromName('BBVA $'));
        self::assertSame(['SANTANDER', 'BBVA', null, 'PENSIONES', null, null],
            array_map([PolicyLevels::class, 'bankOfSheet'], ['SANTANDER $ 1.00', 'BBVA $', 'RETENCIONES ', 'PENSIONES', 'APORTACIONES', 'TRASPASOS']));
    }

    public function testGroupOfUnidentifiedDispersionComesFromMatchingEgresoPolicy(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '1000.00', 2 => '300.00']);
        self::globalPolicy($b, neto: '1300.00');
        self::disp($b, 400, 'SANTANDER', pagos: [[1, '1000.00']]);
        self::disp($b, 401, 'INTERBANCARIA', pendientes: [['300.00', 'SUGERENCIA', [2], 'PERSONA 2']]);
        self::egressPolicy($b, hojas: [['SANTANDER $1,000.00', '1000.00'], ['SANTANDER $300.00', '300.00']]);
        $r = self::conciliate($b);
        $grupos = [];
        foreach ($r->archivos as $a) {
            $grupos[$a->archivoId] = $a->grupo;
        }
        self::assertSame('POLICIA', $grupos[401]);
        $t = $r->totales['POLICIA']['tesoreria_nomina'];
        self::assertSame(['1300.00', '300.00'], [$t['total'], $t['pendiente_identidad']], 'Lo pendiente queda en el grupo correcto, sin asignarse a nadie');
        self::assertArrayNotHasKey('SIN_GRUPO', $r->totales);
        self::assertDec('0', self::emp($r, 2)->pagadoTotal());
        self::assertSame('PENDIENTE_IDENTIDAD', self::emp($r, 2)->estado);
        self::assertSame('CUADRA', array_values(array_filter($r->controles, static fn ($k) => $k->nivel === 2))[0]->estado);
    }

    public function testUnmatchedPolicySheetProposesCandidatesForManualReview(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => ['1000.00', '120.00']]);
        self::disp($b, 400, 'SANTANDER', pagos: [[1, '1000.00']]);
        self::disp($b, 401, 'SANTANDER', pagos: [[1, '100.00']], categoria: 'PENSION');
        self::egressPolicy($b, hojas: [['SANTANDER $1,000.00', '1000.00'], ['PENSIONES', '120.00']]);
        $r = self::conciliate($b);
        $k = array_values(array_filter(self::controls($r, 'POLIZA_EGRESO_VS_DISPERSION'), static fn ($k) => $k->categoria === 'PENSION'))[0];
        $d = self::toArray($k->detalle);
        self::assertSame(['REVISION_MANUAL', [401], ['401' => '20.00'], 'POLICIA'], [$k->estado, $d['candidatas'], $d['diferencias'], $k->grupo]);
        self::assertContains('POLIZA_BANCO_SIN_DISPERSION_IGUAL', self::tipos($r));
    }

    public function testLotWithoutRrhhReportsOneCriticalIncidentNotOnePerPerson(): void
    {
        $b = self::lot();
        $pagos = [];
        for ($i = 1; $i < 30; $i++) {
            $pagos[] = [$i, '100.00'];
        }
        self::disp($b, 400, pagos: $pagos);
        $r = self::conciliate($b);
        self::assertSame('REVISION_MANUAL', $r->estado);
        self::assertSame([], array_values(array_filter($r->incidencias, static fn ($i) => in_array($i->nivel, [4, 5], true))));
        self::assertContains('LOTE_SIN_FUENTE_RRHH', self::tipos($r));
        foreach ($r->empleados as $x) {
            self::assertSame('PAGO_SIN_RESPALDO_RRHH', $x->estado, 'Los resultados se conservan');
        }
    }

    public function testDuplicateWhoseOriginalWasAnnulledKeepsTheMoney(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '1000.00']);
        self::disp($b, 400, pagos: [[1, '1000.00']], kw: ['estado' => 'ANULADO']);
        self::disp($b, 401, pagos: [[1, '1000.00']], kw: ['clasif' => 'DUPLICADO_BINARIO', 'rel' => 400, 'mismo_lote' => true]);
        $r = self::conciliate($b);
        self::assertSame('TESORERIA_NOMINA', self::roles($r)[401]);
        self::assertSame('CORRECTO', self::emp($r, 1)->estado);
        self::assertContains('DUPLICADO_SIN_ORIGINAL_VIGENTE', self::tipos($r));
    }

    public function testNewVersionOfRrhhTabuladoInSameLotIsCriticalNotSilentlyIgnored(): void
    {
        $b = self::lot();
        self::rrhhFile($b, 100, netos: [1 => '1000.00']);
        self::rrhhFile($b, 101, netos: [1 => '1200.00'], kw: ['clasif' => 'POSIBLE_VERSION', 'rel' => 100, 'mismo_lote' => true]);
        self::disp($b, 400, pagos: [[1, '1000.00']]);
        $r = self::conciliate($b);
        self::assertSame('EXCLUIDO_VERSION_AMBIGUA', self::roles($r)[101]);
        self::assertSame('REVISION_MANUAL', $r->estado);
        self::assertContains('VERSION_AMBIGUA_EN_LOTE', self::tipos($r));
    }

    public function testFilesNotAnalyzedAreExcludedWithIncident(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '1000.00']);
        self::disp($b, 400, pagos: [[1, '1000.00']]);
        self::disp($b, 401, pagos: [[1, '1000.00']], kw: ['estado' => 'ERROR']);
        $r = self::conciliate($b);
        self::assertSame('EXCLUIDO_NO_NORMALIZADO', self::roles($r)[401]);
        self::assertSame('CORRECTO', self::emp($r, 1)->estado);
        self::assertContains('ARCHIVO_EXCLUIDO_NO_NORMALIZADO', self::tipos($r));
    }

    public function testOnePendingPaymentNeverExplainsTwoPeople(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '1000.00', 2 => '1000.00']);
        self::disp($b, 400, pendientes: [['1000.00', 'AMBIGUO', [1, 2], 'X']]);
        $r = self::conciliate($b);
        foreach ([1, 2] as $e) {
            $x = self::emp($r, $e);
            self::assertSame(['NO_PAGADO', 'POSIBLE_PAGO_PENDIENTE'], [$x->estado, $x->alerta]);
            self::assertDec('0', $x->posiblePendiente);
        }
    }

    public function testManualNoIdentificadoDoesNotFallBackToName(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '1000.00']);
        self::disp($b, 400, pendientes: [['1000.00', 'NO_IDENTIFICADO', [], 'PERSONA 1']]);
        $b['tes'][count($b['tes']) - 1]['manual'] = true;
        self::assertSame('NO_PAGADO', self::emp(self::conciliate($b), 1)->estado);
    }

    public function testPensionPendingOnlyWithUniqueOwnerOrUniqueAmount(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => ['1000.00', '300.00'], 2 => ['1000.00', '300.00'], 3 => ['1000.00', '450.00']]);
        self::disp($b, 401, 'INTERBANCARIA', categoria: 'PENSION', pendientes: [['300.00', 'NO_IDENTIFICADO', [], 'B1'], ['450.00', 'NO_IDENTIFICADO', [], 'B3']]);
        $r = self::conciliate($b);
        $pen = [];
        foreach ($r->pensiones as $p) {
            $pen[$p->empleadoId] = [$p->estado, $p->alerta];
        }
        self::assertSame(['NO_DISPERSADA', 'POSIBLE_PAGO_PENDIENTE'], $pen[1], 'Un pago de 300 no explica a dos personas con faltante de 300');
        self::assertSame($pen[1], $pen[2]);
        self::assertSame(['PENDIENTE_IDENTIDAD', 'POSIBLE_PAGO_PENDIENTE'], $pen[3], 'Importe único en ambos lados');
    }

    public function testManualRelationIsReservedBeforeAutomaticMatching(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '500.00', 2 => '500.00']);
        self::disp($b, 400, 'SANTANDER', pagos: [[1, '500.00']]);
        self::disp($b, 401, 'SANTANDER', pagos: [[2, '500.00']]);
        self::egressPolicy($b, hojas: [['SANTANDER $500.00', '500.00'], ['SANTANDER $500.00 B', '500.00']]);
        $b['rel']['300:1'] = 400;
        $eg = array_map(static function ($k) {
            $d = self::toArray($k->detalle);
            return [$d['hoja'], $d['dispersion'], $d['relacion']];
        }, self::controls(self::conciliate($b), 'POLIZA_EGRESO_VS_DISPERSION'));
        sort($eg);
        self::assertSame([[0, 401, 'IMPORTE_EXACTO'], [1, 400, 'MANUAL']], $eg);
    }

    public function testPensionTotalsIncludeUnassociatedRrhhAndDuplicatesDoNotDouble(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => ['1000.00', '300.00'], 2 => ['1000.00', '200.00']]);
        $b['rrhh'][1]['empleado_id'] = null;
        $b['rrhh'][1]['asociacion'] = 'NO_IDENTIFICADO';
        self::rrhhFile($b, 102, grupo: 'POLICIA', netos: []);
        $b['rrhh'][] = self::rrhhRow(9999, 100, 1, 'ASOCIADO_AUTOMATICO', 'POLICIA', '90001', 'PERSONA 1', '1010', '10', '1000', 99,
            [['codigo' => '044', 'importe_original' => '-300', 'retenido' => '300']]);
        self::disp($b, 401, 'SANTANDER', categoria: 'PENSION', pagos: [[1, '300.00']]);
        $r = self::conciliate($b);
        $p1 = array_values(array_filter($r->pensiones, static fn ($p) => $p->empleadoId === 1))[0];
        self::assertDec('300.00', $p1->retenido);
        self::assertSame(['CORRECTA', 'REGISTRO_EN_CONFLICTO'], [$p1->estado, $p1->alerta]);
        self::assertSame('200.00', $r->totales['TOTAL']['pensiones']['retenido_sin_empleado']);
    }

    public function testGroupOtherDoesNotBreakTheRun(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '1000.00']);
        $b['emp'][58] = ['id' => 58, 'numero' => '90058', 'grupo' => 'OTRO', 'nombre_key' => 'PERSONA 58'];
        self::disp($b, 400, pagos: [[58, '10.00']]);
        $r = self::conciliate($b);
        $grupos = [];
        foreach ($r->archivos as $a) {
            $grupos[$a->archivoId] = $a->grupo;
        }
        self::assertSame('SIN_GRUPO', $grupos[400]);
    }

    public function testFullTabuladoThatIsAVersionOfItsOwnDerivedViewIsTheSource(): void
    {
        $b = self::lot();
        self::rrhhFile($b, 100, netos: [1 => '1000.00'], derivado: true);
        self::rrhhFile($b, 101, netos: [1 => '1000.00', 2 => '500.00'], kw: ['clasif' => 'POSIBLE_VERSION', 'rel' => 100, 'mismo_lote' => true]);
        self::disp($b, 400, pagos: [[1, '1000.00'], [2, '500.00']]);
        $r = self::conciliate($b);
        self::assertSame('FUENTE_RRHH', self::roles($r)[101]);
        self::assertNotContains('VERSION_AMBIGUA_EN_LOTE', self::tipos($r));
        self::assertNotSame('REVISION_MANUAL', $r->estado);
        self::assertSame(['CORRECTO', 'CORRECTO'], array_map(static fn ($x) => $x->estado, $r->empleados));
    }

    /** @return array<int,\ConciliaRH\Engine\Conciliation\PensionResult> */
    private static function pensionsById(Result $r): array
    {
        $out = [];
        foreach ($r->pensiones as $p) {
            $out[$p->empleadoId] = $p;
        }
        ksort($out);
        return $out;
    }

    /** @return array<string,string> */
    private static function severities(Result $r): array
    {
        $out = [];
        foreach ($r->incidencias as $i) {
            $out[$i->clave] = $i->severidad;
        }
        ksort($out);
        return $out;
    }

    public function testDeclaredPopulationCoversUnidentifiedInterbankPensionsByTotal(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => ['1000.00', '300.00'], 2 => ['900.00', '100.00'], 3 => ['800.00', '50.00']]);
        self::disp($b, 400, pagos: [[1, '1000.00'], [2, '900.00'], [3, '800.00']]);
        self::disp($b, 401, 'SANTANDER', categoria: 'PENSION', pagos: [[1, '300.00']]);
        self::disp($b, 402, 'INTERBANCARIA', categoria: 'PENSION', pendientes: [['100.00', 'NO_IDENTIFICADO', [], 'BENEFICIARIA A'],
            ['50.00', 'NO_IDENTIFICADO', [], 'BENEFICIARIA B']], kw: ['poblacion' => 'POLICIA']);
        $r = self::conciliate($b);
        $p = self::pensionsById($r);
        self::assertSame(['CORRECTA', 'PENDIENTE_IDENTIDAD', 'PENDIENTE_IDENTIDAD'], [$p[1]->estado, $p[2]->estado, $p[3]->estado]);
        self::assertTrue($p[2]->detalle['cubierta_por_total_poblacion']);
        self::assertSame('NO_DISPERSADA', $p[3]->detalle['estado_matematico']);
        $sev = self::severities($r);
        self::assertSame('INFO', $sev['N5:2']);
        self::assertSame('INFO', $sev['N5:3']);
        self::assertSame('INFO', $sev['PENDIENTE_IDENTIDAD:PENSION']);
        self::assertSame('POLICIA', self::controls($r, 'PENSION_RETENIDA_VS_DISPERSADA')[1]->grupo);
        self::assertSame('CUADRA', self::controls($r, 'PENSION_RETENIDA_VS_DISPERSADA')[1]->estado);
        $roles = [];
        foreach ($r->archivos as $a) {
            $roles[$a->archivoId] = $a->grupo;
        }
        self::assertSame('POLICIA', $roles[402]);
        self::assertSame('COMPLETADA', $r->estado);
    }

    public function testPopulationTotalThatDoesNotMatchKeepsTheErrors(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [2 => ['900.00', '100.00'], 3 => ['800.00', '50.00']]);
        self::disp($b, 400, pagos: [[2, '900.00'], [3, '800.00']]);
        self::disp($b, 402, 'INTERBANCARIA', categoria: 'PENSION', pendientes: [['100.00', 'NO_IDENTIFICADO', [], 'BENEFICIARIA A']],
            kw: ['poblacion' => 'POLICIA']);
        $r = self::conciliate($b);
        $p = self::pensionsById($r);
        self::assertSame('NO_DISPERSADA', $p[3]->estado);
        self::assertArrayNotHasKey('cubierta_por_total_poblacion', $p[2]->detalle);
        $sev = self::severities($r);
        self::assertSame('ERROR', $sev['N5:3']);
        self::assertSame('ADVERTENCIA', $sev['PENDIENTE_IDENTIDAD:PENSION']);
    }

    public function testWithoutDeclaredPopulationUnidentifiedPensionIsNotAttributed(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [2 => ['900.00', '100.00']]);
        self::disp($b, 400, pagos: [[2, '900.00']]);
        self::disp($b, 402, 'INTERBANCARIA', categoria: 'PENSION', pendientes: [['100.00', 'NO_IDENTIFICADO', [], 'BENEFICIARIA A']]);
        $r = self::conciliate($b);
        self::assertArrayNotHasKey('cubierta_por_total_poblacion', self::pensionsById($r)[2]->detalle);
        self::assertSame('ADVERTENCIA', self::severities($r)['PENDIENTE_IDENTIDAD:PENSION']);
    }

    public function testWithoutGlobalPolicyThereAreNoLevelOneControlsNorWarnings(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '1000.00']);
        self::disp($b, 400, pagos: [[1, '1000.00']]);
        $r = self::conciliate($b);
        self::assertSame([], array_values(array_filter($r->controles, static fn ($k) => $k->nivel === 1)));
        self::assertNotContains('RRHH_POLIZA_NO_DETERMINABLE', self::tipos($r));
        self::assertSame('COMPLETADA', $r->estado);
    }

    public function testDeclaredPopulationContradictedByIdentifiedPeopleIsFlagged(): void
    {
        $b = self::lot();
        self::rrhhFile($b, netos: [1 => '1000.00']);
        self::disp($b, 400, pagos: [[1, '1000.00']], kw: ['poblacion' => 'BOMBEROS']);
        $r = self::conciliate($b);
        self::assertContains('POBLACION_ARCHIVO_NO_COINCIDE', self::tipos($r));
    }
}
