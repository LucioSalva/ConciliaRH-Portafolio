<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Engine;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\Config;
use ConciliaRH\Domain\Identity\AccountVault;
use ConciliaRH\Engine\Identity\AccountCrypto;
use ConciliaRH\Engine\Identity\PersonIds;
use ConciliaRH\Engine\Support\Dec;
use ConciliaRH\Tests\Fixtures\IdentityFixtures as FX;
use ConciliaRH\Tests\Support\DatabaseTestCase;
use ConciliaRH\Tests\Support\EngineTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class NormalizeRecordsTest extends EngineTestCase
{
    use NormalizationEnvironment;

    protected function setUp(): void
    {
        parent::setUp();
        self::cleanNormalization();
        $this->startEnvironment();
    }

    protected function tearDown(): void
    {
        if (CONCILIARH_DB_AVAILABLE) {
            self::cleanNormalization();
        }
        parent::tearDown();
    }

    private static function assertDecimal(string $expected, mixed $actual): void
    {
        self::assertNotNull($actual);
        self::assertSame(0, bccomp($expected, (string) $actual, 10), "$actual != $expected");
    }

    private function confirm(int $regId, int $empId, bool $confirmar = true, ?int $user = null): ?int
    {
        $w = $this->web();
        return $w->transaction(function (Database $w) use ($regId, $empId, $confirmar, $user): ?int {
            $w->execute("UPDATE registros_tesoreria SET empleado_id = ?, asociacion_estado = 'ASOCIADO_MANUAL', asociacion_metodo = 'MANUAL',
                                asociacion_confianza = 'MANUAL', asociado_por = ?, asociado_at = now(), asociacion_version = asociacion_version + 1
                          WHERE id = ?", [$empId, $this->user, $regId]);
            if ($confirmar) {
                return (int) $w->fetchValue("SELECT fn_confirmar_cuenta(?, ?, 'Confirmada con el expediente ficticio') AS id",
                    [$regId, $user ?? $this->user]);
            }
            return null;
        });
    }

    public function testCase1AltaFromRrhhWithHistoryAuditAndSnapshot(): void
    {
        $people = [FX::person(1), FX::person(2, ['pension' => '300.00']), FX::person(3)];
        $aid = $this->add(self::tab($people), 'xlsx', 'RRHH_TABULADO');
        $imp = $this->imp($aid);
        self::assertSame(['RRHH', true, 'POLICIA'], [$imp['tipo_registro'], $imp['puede_actualizar_maestro'], $imp['grupo']]);
        $m = self::jsonField($imp['metricas']);
        self::assertSame(3, $m['altas']);
        self::assertSame(['ALTA_RRHH' => 3], $m['por_metodo']);
        $e = $this->emp('90001');
        self::assertSame(['FICTICIO1 APELLIDO1 NOMBRE1', FX::rfc(1), true, true, 'POLICIA', 'RRHH_TABULADO'],
            [$e['nombre_completo'], $e['rfc'], $e['rfc_valido'], $e['curp_valida'], $e['grupo'], $e['fuente_creacion']]);
        $regs = $this->regs($aid);
        self::assertCount(3, $regs);
        self::assertSame(['ASOCIADO_AUTOMATICO'], array_values(array_unique(array_column($regs, 'asociacion_estado'))));
        self::assertSame($e['id'], $regs[0]['empleado_id']);
        self::assertSame($regs[0]['id'], $e['origen_registro_id']);
        self::assertSame(['Tabla', 4, true], [$regs[0]['hoja_nombre'], $regs[0]['fila_origen'], $regs[0]['hoja_id'] !== null], 'Trazabilidad a hoja y fila');
        self::assertDecimal('10000.00', $regs[0]['percepciones']);
        self::assertDecimal('8500.00', $regs[0]['neto']);
        self::assertSame(3, $this->intValue("SELECT count(*) FROM empleado_historial WHERE campo = 'alta'"));
        $ids = array_map(static fn (array $x): string => (string) $x['id'], $this->all('SELECT id FROM empleados'));
        self::assertGreaterThanOrEqual(3, $this->intValue(
            "SELECT count(*) FROM auditoria WHERE accion = 'empleados.alta_automatica' AND entidad_id = ANY(CAST(? AS text[]))
             AND creado_at > now() - interval '5 minutes'", '{' . implode(',', $ids) . '}'));
        $conc = [];
        foreach ($this->all('SELECT c.codigo, k.tipo, k.es_pension FROM registros_rrhh_conceptos c
                             JOIN conceptos_nomina k ON k.id = c.concepto_id WHERE c.registro_id = ?', $regs[1]['id']) as $r) {
            $conc[$r['codigo']] = $r;
        }
        self::assertSame('PERCEPCION', $conc['001']['tipo']);
        self::assertSame('DEDUCCION', $conc['070']['tipo']);
        self::assertTrue($conc['044']['es_pension']);
        self::assertSame('INFERIDA', $this->one("SELECT clasificacion FROM conceptos_nomina WHERE codigo = '070'")['clasificacion']);
        self::assertSame('CONFIGURADA', $this->one("SELECT clasificacion FROM conceptos_nomina WHERE codigo = '044'")['clasificacion']);
        $txt = (string) json_encode($this->one("SELECT contexto FROM auditoria WHERE accion = 'importacion.normalizar' ORDER BY secuencia DESC LIMIT 1"),
            JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('FICTICIO', $txt, 'Sin PII en la auditoría del resumen');
        self::assertStringNotContainsString(FX::rfc(1), $txt, 'Sin PII en la auditoría del resumen');
    }

    public function testCase2And3NextQuincenaSameEmployeeNameChangeKeepsSnapshot(): void
    {
        $a1 = $this->add(self::tab([FX::person(1), FX::person(2)]), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 1));
        $a2 = $this->add(self::tab([FX::person(1, ['nombre' => 'FICTICIO1 CAMBIADO1 NOMBRE1', 'cargo' => 'POLICIA TERCERO']), FX::person(2)]), 'xlsx',
            'RRHH_TABULADO', $this->lote(1, 2));
        self::assertSame(2, $this->intValue('SELECT count(*) FROM empleados'), 'No duplica empleados');
        $r2 = $this->regs($a2);
        $pairs = array_values(array_unique(array_map(static fn (array $r): string => $r['asociacion_estado'] . '|' . $r['asociacion_metodo'], $r2)));
        self::assertSame(['ASOCIADO_AUTOMATICO|NUMERO_EMPLEADO'], $pairs);
        $e = $this->emp('90001');
        self::assertSame('FICTICIO1 CAMBIADO1 NOMBRE1', $e['nombre_completo']);
        self::assertSame('POLICIA TERCERO', $e['cargo']);
        self::assertSame($this->periodo(1, 2), $e['ultimo_periodo_id']);
        $h = array_map(static fn (array $x): array => [$x['campo'], $x['valor_anterior'], $x['valor_nuevo'], $x['fuente']], $this->all(
            "SELECT campo, valor_anterior, valor_nuevo, fuente FROM empleado_historial WHERE empleado_id = ? AND campo <> 'alta' ORDER BY id", $e['id']));
        self::assertContains(['nombre_completo', 'FICTICIO1 APELLIDO1 NOMBRE1', 'FICTICIO1 CAMBIADO1 NOMBRE1', 'RRHH_TABULADO'], $h);
        $r1 = $this->regs($a1);
        self::assertSame('FICTICIO1 APELLIDO1 NOMBRE1', $r1[0]['nombre_original']);
        self::assertSame('POLICIA', $r1[0]['cargo']);
        self::assertContains('NOMBRE_DIFERENTE', array_column($this->all('SELECT tipo FROM incidencias_identidad WHERE registro_id = ?', $r2[0]['id']), 'tipo'));
    }

    public function testOlderPeriodNeverOverwritesNewerMasterData(): void
    {
        $this->add(self::tab([FX::person(1, ['cargo' => 'COMANDANTE'])]), 'xlsx', 'RRHH_TABULADO', $this->lote(2, 1));
        $this->add(self::tab([FX::person(1, ['cargo' => 'POLICIA'])]), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 1));
        self::assertSame('COMANDANTE', $this->emp('90001')['cargo']);
    }

    /** @return array<string,array{string}> */
    public static function identityFields(): array
    {
        return ['rfc' => ['rfc'], 'curp' => ['curp']];
    }

    #[DataProvider('identityFields')]
    public function testCase4And5RfcOrCurpChangeGoesToReview(string $field): void
    {
        $this->add(self::tab([FX::person(1)]), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 1));
        $changed = FX::person(1, [$field => $field === 'rfc' ? FX::rfc(77) : FX::curp(77)]);
        $a2 = $this->add(self::tab([$changed]), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 2));
        $r = $this->regs($a2)[0];
        self::assertSame(['CONFLICTO', null], [$r['asociacion_estado'], $r['empleado_id']]);
        $tipos = array_column($this->all("SELECT tipo FROM incidencias_identidad WHERE registro_id = ? AND registro_tipo = 'RRHH'", $r['id']), 'tipo');
        self::assertContains(strtoupper($field) . '_DISCREPANTE', $tipos);
        self::assertSame($field === 'rfc' ? FX::rfc(1) : FX::curp(1), $this->emp('90001')[$field], 'El maestro no se modifica ante un conflicto');
    }

    public function testCase6DuplicateNumberInFile(): void
    {
        $aid = $this->add(self::tab([FX::person(1), FX::person(1, ['nombre' => 'OTRA FICTICIA PERSONA'])]), 'xlsx', 'RRHH_TABULADO');
        $regs = $this->regs($aid);
        self::assertSame(1, $this->intValue('SELECT count(*) FROM empleados'));
        self::assertSame($regs[0]['empleado_id'], $regs[1]['empleado_id']);
        self::assertContains('NUMERO_DUPLICADO_EN_ARCHIVO', array_column($this->all('SELECT tipo FROM incidencias_identidad WHERE registro_id = ?', $regs[1]['id']), 'tipo'));
    }

    #[DataProvider('identityFields')]
    public function testCase7And8DuplicateRfcOrCurpNeverCreatesSecondEmployee(string $field): void
    {
        $twin = FX::person(2, [$field => $field === 'rfc' ? FX::rfc(1) : FX::curp(1)]);
        $aid = $this->add(self::tab([FX::person(1), $twin]), 'xlsx', 'RRHH_TABULADO');
        $regs = $this->regs($aid);
        self::assertSame(1, $this->intValue('SELECT count(*) FROM empleados'));
        self::assertSame('CONFLICTO', $regs[1]['asociacion_estado']);
        self::assertSame('NUMERO_NUEVO_IDENTIDAD_EXISTENTE',
            $this->one("SELECT tipo FROM incidencias_identidad WHERE registro_id = ? AND severidad = 'ALTA'", $regs[1]['id'])['tipo'] ?? null);
    }

    public function testDerivedTabuladoNeverCreatesEmployees(): void
    {
        $aid = $this->add(self::tab([FX::person(1), FX::person(2), FX::person(3)], true), 'xlsx', 'RRHH_TABULADO');
        $imp = $this->imp($aid);
        self::assertFalse($imp['puede_actualizar_maestro']);
        self::assertSame('VISTA_DERIVADA', $imp['motivo_sin_maestro']);
        self::assertSame(0, $this->intValue('SELECT count(*) FROM empleados'));
        self::assertSame(['NO_IDENTIFICADO'], array_values(array_unique(array_column($this->regs($aid), 'asociacion_estado'))));
    }

    public function testInvalidNumberIsNotUsedButRfcAssociates(): void
    {
        $this->add(self::tab([FX::person(1)]), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 1));
        $aid = $this->add(self::tab([FX::person(1, ['num' => 'NUMERO MAL ESCRITO'])]), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 2));
        $r = $this->regs($aid)[0];
        self::assertSame(['ASOCIADO_AUTOMATICO', 'RFC', false], [$r['asociacion_estado'], $r['asociacion_metodo'], $r['numero_valido']]);
        self::assertSame(1, $this->intValue('SELECT count(*) FROM empleados'));
    }

    public function testCase10And19And20TesoreriaNumberCreatesAccountThenHistory(): void
    {
        $ps = [FX::person(1), FX::person(2)];
        $this->add(self::tab($ps), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 1));
        $s1 = $this->add(self::sant($ps), 'xlsx', 'TESORERIA_DISPERSION', $this->lote(1, 1));
        $regs = $this->regs($s1, 'registros_tesoreria');
        $set = array_values(array_unique(array_map(static fn (array $r): string => implode('|',
            [$r['asociacion_estado'], $r['asociacion_metodo'], $r['banco'], $r['categoria']]), $regs)));
        self::assertSame(['ASOCIADO_AUTOMATICO|NUMERO_EMPLEADO|SANTANDER|NOMINA'], $set);
        $ctas = $this->all('SELECT * FROM cuentas_bancarias ORDER BY id');
        self::assertCount(2, $ctas);
        self::assertSame(['EVIDENCIA_FUERTE'], array_values(array_unique(array_column($ctas, 'origen'))));
        self::assertSame('NOMINA', $ctas[0]['uso']);
        self::assertSame($regs[0]['cuenta_ultimos4'], $ctas[0]['cuenta_ultimos4']);
        self::assertSame(2, $this->intValue('SELECT count(*) FROM empleados'), 'Tesorería no crea empleados');
        $this->add(self::sant($ps), 'xlsx', 'TESORERIA_DISPERSION', $this->lote(1, 2));
        self::assertSame(2, $this->intValue('SELECT count(*) FROM cuentas_bancarias'));
        self::assertSame(['veces_vista' => 2, 'ultimo_periodo_id' => $this->periodo(1, 2)],
            $this->one('SELECT veces_vista, ultimo_periodo_id FROM cuentas_bancarias WHERE id = ?', $ctas[0]['id']));
        $this->add(self::sant($ps, ['90001' => '60099999999']), 'xlsx', 'TESORERIA_DISPERSION', $this->lote(2, 1));
        $e1 = $this->emp('90001');
        $act = $this->all("SELECT cuenta_ultimos4 FROM cuentas_bancarias WHERE empleado_id = ? AND estado = 'ACTIVA' ORDER BY id", $e1['id']);
        self::assertSame([$ctas[0]['cuenta_ultimos4'], '9999'], array_column($act, 'cuenta_ultimos4'));
        self::assertSame(1, $this->intValue("SELECT count(*) FROM incidencias_identidad WHERE tipo = 'CUENTA_NUEVA' AND empleado_id = ?", $e1['id']));
    }

    public function testAccountOfOtherPersonIsConflict(): void
    {
        $ps = [FX::person(1), FX::person(2)];
        $this->add(self::tab($ps), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 1));
        $this->add(self::sant($ps), 'xlsx', 'TESORERIA_DISPERSION', $this->lote(1, 1));
        $aid = $this->add(self::sant([FX::person(2)], ['90002' => '60000090001']), 'xlsx', 'TESORERIA_DISPERSION', $this->lote(1, 2));
        self::assertSame('CONFLICTO', $this->regs($aid, 'registros_tesoreria')[0]['asociacion_estado']);
    }

    public function testCase11BbvaRfcOnly(): void
    {
        $this->add(self::tab([FX::person(1), FX::person(2)]), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 1));
        $aid = $this->add(FX::bbva([[null, 'NOMBRE DISTINTO FICTICIO', FX::rfc(2), '100.00', '0100000001'],
                                    ['90001', 'FICTICIO1 APELLIDO1 NOMBRE1', FX::rfc(1), '200.00', '0100000002']]),
            'xlsx', 'TESORERIA_DISPERSION', $this->lote(1, 1));
        $regs = $this->regs($aid, 'registros_tesoreria');
        self::assertSame([['BBVA', 'RFC'], ['BBVA', 'NUMERO_EMPLEADO']], array_map(static fn (array $r): array => [$r['banco'], $r['asociacion_metodo']], $regs));
        self::assertSame($this->empId('90002'), $regs[0]['empleado_id']);
    }

    public function testTesoreriaBeforeRrhhIsReassociated(): void
    {
        $ps = [FX::person(1), FX::person(2)];
        $s = $this->add(self::sant($ps), 'xlsx', 'TESORERIA_DISPERSION', $this->lote(1, 1));
        self::assertSame(['NO_IDENTIFICADO'], array_values(array_unique(array_column($this->regs($s, 'registros_tesoreria'), 'asociacion_estado'))));
        self::assertSame(0, $this->intValue('SELECT count(*) FROM empleados'));
        $this->add(self::tab($ps), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 1));
        $regs = $this->regs($s, 'registros_tesoreria');
        self::assertSame(['ASOCIADO_AUTOMATICO|NUMERO_EMPLEADO'],
            array_values(array_unique(array_map(static fn (array $r): string => $r['asociacion_estado'] . '|' . $r['asociacion_metodo'], $regs))));
        self::assertSame(2, $this->intValue("SELECT count(*) FROM asociaciones_historial WHERE accion = 'REASOCIACION'"));
        self::assertSame(2, $this->intValue('SELECT count(*) FROM cuentas_bancarias'));
        self::assertSame(['ASOCIADO_AUTOMATICO' => 2], self::jsonField($this->imp($s)['metricas'])['por_estado']);
    }

    public function testCase13And14And15InterbancariaSuggestionConfirmationAndFutureMatch(): void
    {
        $ps = [FX::person(1), FX::person(2)];
        $this->add(self::tab($ps), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 1));
        $clabe = '002180000000012345';
        $i1 = $this->add(FX::interbancaria([[$clabe, 'FICTICIO1 APELLIDO1 NOMBRE1', '700.00']]), 'xls', 'TESORERIA_DISPERSION', $this->lote(1, 1));
        $r = $this->regs($i1, 'registros_tesoreria')[0];
        self::assertSame(['SUGERENCIA', 'MEDIA', null, 'INTERBANCARIA', 'CLABE'],
            [$r['asociacion_estado'], $r['asociacion_confianza'], $r['empleado_id'], $r['banco'], $r['tipo_cuenta']]);
        self::assertSame([$this->empId('90001')], self::jsonField($r['asociacion_evidencia'])['candidatos']);
        self::assertSame(0, $this->intValue('SELECT count(*) FROM cuentas_bancarias'));
        self::assertSame(2, $this->intValue('SELECT count(*) FROM empleados'));
        $cid = $this->confirm($r['id'], $this->empId('90001'));
        $c = $this->one('SELECT * FROM cuentas_bancarias WHERE id = ?', $cid);
        self::assertSame(['CONFIRMACION_MANUAL', 'CLABE', $this->user, '2345'], [$c['origen'], $c['tipo'], $c['confirmado_por'], $c['cuenta_ultimos4']]);
        $i2 = $this->add(FX::interbancaria([[$clabe, 'NOMBRE ESCRITO DISTINTO', '700.00']]), 'xls', 'TESORERIA_DISPERSION', $this->lote(1, 2));
        $r2 = $this->regs($i2, 'registros_tesoreria')[0];
        self::assertSame(['ASOCIADO_AUTOMATICO', 'CUENTA_CONFIRMADA', $this->empId('90001')],
            [$r2['asociacion_estado'], $r2['asociacion_metodo'], $r2['empleado_id']]);
        $i3 = $this->add(FX::interbancaria([[$clabe, 'FICTICIO2 APELLIDO2 NOMBRE2', '1.00']]), 'xls', 'TESORERIA_DISPERSION', $this->lote(2, 1));
        $r3 = $this->regs($i3, 'registros_tesoreria')[0];
        self::assertSame(['ASOCIADO_AUTOMATICO', $this->empId('90001')], [$r3['asociacion_estado'], $r3['empleado_id']]);
        self::assertSame('23505', self::sqlState(fn () => $this->confirm($r3['id'], $this->empId('90002'))));
        self::assertSame($this->empId('90001'), $this->regs($i3, 'registros_tesoreria')[0]['empleado_id']);
        $i4 = $this->add(FX::interbancaria([['002180000000044444', 'FICTICIO2 APELLIDO2 NOMBRE2', '1.00']]), 'xls', 'TESORERIA_DISPERSION',
            $this->lote(2, 1));
        $r4 = $this->regs($i4, 'registros_tesoreria')[0];
        $this->confirm($r4['id'], $this->empId('90002'), false);
        self::assertSame('23514', self::sqlState(fn () => $this->web()->execute("SELECT fn_confirmar_cuenta(?, ?, 'Otra transacción')",
            [$r4['id'], $this->user])));
        $sinPermiso = (int) self::owner()->fetchValue(
            "INSERT INTO usuarios (username, nombre, password_hash) VALUES ('eng.sinpermiso', 'Sin permiso', repeat('x', 30))
             ON CONFLICT (username) DO UPDATE SET nombre = EXCLUDED.nombre RETURNING id");
        self::assertSame('42501', self::sqlState(fn () => $this->confirm($r4['id'], $this->empId('90002'), true, $sinPermiso)));
    }

    public function testConfirmationRequiresAssociation(): void
    {
        $this->add(self::tab([FX::person(1)]), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 1));
        $i1 = $this->add(FX::interbancaria([['002180000000012345', 'FICTICIO1 APELLIDO1 NOMBRE1', '7.00']]), 'xls', 'TESORERIA_DISPERSION',
            $this->lote(1, 1));
        $r = $this->regs($i1, 'registros_tesoreria')[0];
        self::assertSame('23514', self::sqlState(fn () => $this->web()->execute("SELECT fn_confirmar_cuenta(?, ?, 'Motivo suficiente')",
            [$r['id'], $this->user])));
    }

    public function testCase16And17SameNameTwoEmployeesIsAmbiguous(): void
    {
        $ps = [FX::person(1), FX::person(2, ['nombre' => 'FICTICIO1 APELLIDO1 NOMBRE1']), FX::person(3)];
        $this->add(self::tab($ps), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 1));
        $aid = $this->add(FX::interbancaria([['002180000000011111', 'Ficticio1 Apellido1 Nombre1', '1.00'],
                                             ['002180000000022222', 'FICTICIO3 APELLIDO3 NOMBRE3', '1.00'],
                                             ['002180000000033333', 'FICTICIO3 APELIDO3 NOMBRE3', '1.00']]),
            'xls', 'TESORERIA_DISPERSION', $this->lote(1, 1));
        $r = $this->regs($aid, 'registros_tesoreria');
        self::assertSame('AMBIGUO', $r[0]['asociacion_estado']);
        self::assertCount(2, self::jsonField($r[0]['asociacion_evidencia'])['candidatos']);
        self::assertSame('SUGERENCIA', $r[1]['asociacion_estado']);
        self::assertSame('NO_IDENTIFICADO', $r[2]['asociacion_estado']);
        self::assertSame($this->empId('90003'), self::jsonField($r[2]['asociacion_evidencia'])['similares'][0]['empleado_id']);
    }

    public function testCase21PensionWorkerVsBeneficiary(): void
    {
        $ps = [FX::person(1), FX::person(2)];
        $this->add(self::tab($ps), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 1));
        $data = FX::santander([['90001', 'FICTICIO2', 'APELLIDO2', 'NOMBRE2', '60055555555', '300.00']], true);
        $aid = $this->add($data, 'xlsx', 'TESORERIA_PENSION', $this->lote(1, 1));
        $r = $this->regs($aid, 'registros_tesoreria')[0];
        self::assertSame(['PENSION', 'ASOCIADO_AUTOMATICO', $this->empId('90001')], [$r['categoria'], $r['asociacion_estado'], $r['empleado_id']]);
        self::assertSame(['uso' => 'PENSION_BENEFICIARIO', 'beneficiario_nombre' => 'FICTICIO2 APELLIDO2 NOMBRE2'],
            $this->one('SELECT uso, beneficiario_nombre FROM cuentas_bancarias'));
        $i = $this->add(FX::interbancaria([['002180000000077777', 'FICTICIO2 APELLIDO2 NOMBRE2', '300.00']], true), 'xls', 'TESORERIA_PENSION',
            $this->lote(1, 1));
        $r = $this->regs($i, 'registros_tesoreria')[0];
        $ev = self::jsonField($r['asociacion_evidencia']);
        self::assertSame(['PENSION', 'SUGERENCIA', null, 'BENEFICIARIO_CONOCIDO'],
            [$r['categoria'], $r['asociacion_estado'], $r['empleado_id'], $ev['motivo'] ?? null]);
        self::assertSame([$this->empId('90001')], $ev['candidatos'], 'Nunca el empleado 90002 cuyo nombre coincide');
    }

    public function testCase22RepeatedImportIsIdempotentAndKeepsManualDecisions(): void
    {
        $ps = [FX::person(1), FX::person(2)];
        $this->add(self::tab($ps), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 1));
        $i1 = $this->add(FX::interbancaria([['002180000000012345', 'FICTICIO1 APELLIDO1 NOMBRE1', '7.00'],
                                            ['002180000000054321', 'DESCONOCIDO FICTICIO', '8.00']]), 'xls', 'TESORERIA_DISPERSION',
            $this->lote(1, 1));
        $regs = $this->regs($i1, 'registros_tesoreria');
        $this->confirm($regs[0]['id'], $this->empId('90001'), false);
        $tables = ['empleados', 'registros_rrhh', 'registros_tesoreria', 'cuentas_bancarias', 'incidencias_identidad', 'asociaciones_historial'];
        $counts = fn (): array => array_combine($tables, array_map(fn (string $t): int => $this->intValue("SELECT count(*) FROM $t"), $tables));
        $before = $counts();
        $this->requeue($i1, false);
        self::assertSame($before, $counts(), 'Re-procesar el mismo análisis no duplica nada');
        $this->requeue($i1, true);
        $imps = $this->all('SELECT numero, estado FROM importaciones_normalizadas WHERE archivo_id = ? ORDER BY numero', $i1);
        self::assertSame([[1, 'SUSTITUIDA'], [2, 'VIGENTE']], array_map(static fn (array $x): array => [$x['numero'], $x['estado']], $imps));
        $new = $this->regs($i1, 'registros_tesoreria');
        self::assertSame(['ASOCIADO_MANUAL', $this->empId('90001'), $this->user],
            [$new[0]['asociacion_estado'], $new[0]['empleado_id'], $new[0]['asociado_por']]);
        self::assertSame(2, $this->intValue('SELECT count(*) FROM empleados'));
        self::assertSame(1, $this->intValue("SELECT count(*) FROM asociaciones_historial WHERE accion = 'TRASLADO'"));
    }

    public function testCase23NewAnalysisInvalidatesPreviousImport(): void
    {
        $aid = $this->add(self::tab([FX::person(1), FX::person(2)]), 'xlsx', 'RRHH_TABULADO');
        $first = $this->imp($aid);
        $job = (int) self::owner()->fetchValue("INSERT INTO procesos (tipo, parametros, max_intentos) VALUES ('ANALIZAR_ARCHIVO', CAST(? AS jsonb), 2) RETURNING id",
            [json_encode(['archivo_id' => $aid])]);
        self::owner()->execute('UPDATE archivos_importados SET proceso_id = ? WHERE id = ?', [$job, $aid]);
        $this->drain();
        $imps = $this->all('SELECT id, estado, estado_motivo FROM importaciones_normalizadas WHERE archivo_id = ? ORDER BY numero', $aid);
        self::assertSame([$first['id'], 'INVALIDADA', 'NUEVO_ANALISIS'], [$imps[0]['id'], $imps[0]['estado'], $imps[0]['estado_motivo']]);
        self::assertSame('VIGENTE', $imps[1]['estado']);
        self::assertSame(2, $this->intValue('SELECT count(*) FROM empleados'));
        self::assertCount(2, $this->regs($aid), 'Los registros de la importación invalidada se conservan pero no cuentan');
        self::assertSame(4, $this->intValue('SELECT count(*) FROM registros_rrhh WHERE archivo_id = ?', $aid));
        self::assertSame(['NUMERO_EMPLEADO'], array_values(array_unique(array_column($this->regs($aid), 'asociacion_metodo'))));
    }

    public function testAnnulledFileInvalidatesImport(): void
    {
        $aid = $this->add(self::tab([FX::person(1)]), 'xlsx', 'RRHH_TABULADO');
        self::owner()->execute("UPDATE archivos_importados SET estado = 'ANULADO', anulado_at = now(), anulado_por = ? WHERE id = ?", [$this->user, $aid]);
        self::assertSame(['estado' => 'INVALIDADA', 'estado_motivo' => 'ARCHIVO_ANULADO'],
            $this->one('SELECT estado, estado_motivo FROM importaciones_normalizadas WHERE archivo_id = ?', $aid));
    }

    public function testPolizasImportAndNeverCreateEmployees(): void
    {
        $g = $this->add(FX::polizaGlobal([['1', 'SUELDO BASE', 5000.0], ['3', 'COMPENSACION', 1000.0]], [['70', 'ISR', 800.0], ['44', 'PENSION', 200.0]]),
            'xlsx', 'RRHH_POLIZA_GLOBAL');
        $imp = $this->imp($g);
        self::assertSame(['POLIZA_GLOBAL', 'POLICIA'], [$imp['tipo_registro'], $imp['grupo']]);
        $pg = $this->one('SELECT * FROM polizas_globales WHERE importacion_id = ?', $imp['id']);
        self::assertDecimal('6000.00', $pg['total_percepciones']);
        self::assertDecimal('1000.00', $pg['total_deducciones']);
        self::assertSame(4, $pg['lineas']);
        $e = $this->add(FX::polizaEgreso(), 'xlsx', 'POLIZA_EGRESO_BANCARIA');
        $lines = $this->all('SELECT banco, debe, haber FROM polizas_egreso_lineas WHERE archivo_id = ? ORDER BY hoja_indice, fila_origen', $e);
        self::assertSame(['SANTANDER', 'SANTANDER', 'BBVA', 'BBVA'], array_column($lines, 'banco'));
        self::assertDecimal('1250.00', Dec::sum(array_map(static fn (array $x): string => (string) ($x['debe'] ?? '0'), $lines)));
        self::assertSame(0, $this->intValue('SELECT count(*) FROM empleados'));
    }

    public function testWebRoleCannotReadAccountSecretsNorCreateEmployees(): void
    {
        $this->add(self::tab([FX::person(1)]), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 1));
        $this->add(self::sant([FX::person(1)]), 'xlsx', 'TESORERIA_DISPERSION', $this->lote(1, 1));
        $w = $this->web();
        foreach (['SELECT cuenta_hmac FROM registros_tesoreria', 'SELECT cuenta_cifrada FROM registros_tesoreria',
                  'SELECT cuenta_hmac FROM cuentas_bancarias', 'SELECT datos FROM staging_registros',
                  'SELECT cuenta_cifrada FROM staging_registros'] as $sql) {
            self::assertSame('42501', self::sqlState(fn () => $w->fetchAll($sql)), $sql);
        }
        self::assertSame('42501', self::sqlState(fn () => $w->execute("INSERT INTO empleados (numero_empleado) VALUES ('1')")));
        self::assertSame('42501', self::sqlState(fn () => $w->execute('DELETE FROM registros_rrhh')));
        self::assertSame(0, (int) $w->fetchValue('SELECT count(*) AS n FROM v_asociaciones_pendientes'));
    }

    public function testHistoryIsAppendOnlyAndWorkerCannotDelete(): void
    {
        $this->add(self::tab([FX::person(1)]), 'xlsx', 'RRHH_TABULADO');
        $w = self::workerDb();
        self::assertSame('42501', self::sqlState(fn () => $w->execute('DELETE FROM registros_rrhh')));
        self::assertNotNull(self::sqlState(fn () => $w->execute("UPDATE empleado_historial SET campo = 'x'")));
        self::assertSame('42501', self::sqlState(fn () => self::owner()->execute('DELETE FROM empleado_historial')));
        self::assertSame('23514', self::sqlState(fn () => self::owner()->execute("UPDATE empleados SET numero_empleado = '99999'")));
    }

    public function testStagingAccountIsEncryptedAndDecryptableWithRing(): void
    {
        $aid = $this->add(self::sant([FX::person(1)]), 'xlsx', 'TESORERIA_DISPERSION');
        $st = $this->one('SELECT cuenta_cifrada, cuenta_key_id, cuenta_hmac, datos FROM staging_registros WHERE archivo_id = ?', $aid);
        $blob = is_resource($st['cuenta_cifrada']) ? (string) stream_get_contents($st['cuenta_cifrada']) : (string) $st['cuenta_cifrada'];
        $env = DatabaseTestCase::env();
        $ring = Config::parseKeyRing($env->string('ACCOUNT_ENC_KEYS')) ?? [];
        self::assertSame('60000090001', AccountVault::decrypt($blob, (string) $st['cuenta_key_id'], $ring[$st['cuenta_key_id']]));
        self::assertSame(AccountCrypto::accountHmac($env->string('ACCOUNT_HMAC_KEY'), '60000090001'), $st['cuenta_hmac']);
        self::assertStringNotContainsString('60000090001', (string) $st['datos']);
    }

    public function testSqlValidityColumnsAgreeWithEngine(): void
    {
        $samples = [FX::rfc(1), 'XYZ', 'FICT000001AB', FX::curp(3), 'FAXT900101HZZBCDA1', 'FAXT900101HMCAEIA1', null];
        $o = self::owner();
        $o->execute('CREATE TEMP TABLE t_emp (LIKE empleados INCLUDING GENERATED INCLUDING DEFAULTS)');
        try {
            foreach ($samples as $v) {
                $o->execute("INSERT INTO t_emp (id, numero_empleado, nombre_completo, nombre_normalizado, rfc, curp, origen_importacion_id,
                                 origen_registro_id, primer_periodo_id, ultimo_periodo_id, ultimo_periodo_fecha)
                             VALUES (1, '1', 'x', 'x', ?, ?, 1, 1, 1, 1, now())", [$v, $v]);
                $row = $o->fetchOne('SELECT rfc_valido, curp_valida FROM t_emp');
                self::assertSame($v !== null ? PersonIds::rfc($v)[1] : false, $row['rfc_valido'], (string) $v);
                self::assertSame($v !== null ? PersonIds::curp($v)[1] : false, $row['curp_valida'], (string) $v);
                $o->execute('DELETE FROM t_emp');
            }
        } finally {
            $o->execute('DROP TABLE t_emp');
        }
    }

    public function testPlaceholderRfcDoesNotAbortAlta(): void
    {
        $aid = $this->add(self::tab([FX::person(1, ['rfc' => '-', 'curp' => '.']), FX::person(2, ['rfc' => 'N/A'])]), 'xlsx', 'RRHH_TABULADO');
        self::assertSame(2, $this->intValue('SELECT count(*) FROM empleados'));
        $e1 = $this->emp('90001');
        $e2 = $this->emp('90002');
        self::assertSame([null, null, null], [$e1['rfc'], $e1['curp'], $e2['rfc']], 'Sólo RFC/CURP válidos entran al maestro');
        $r = $this->regs($aid);
        self::assertSame(['N/A', false], [$r[1]['rfc_original'], $r[1]['rfc_valido']], 'El original se conserva en el registro');
    }

    public function testManualDecisionPreventsDuplicateAlta(): void
    {
        $aid = $this->add(self::tab([FX::person(5), FX::person(6), FX::person(7)], true), 'xlsx', 'RRHH_TABULADO');
        $r = $this->regs($aid)[0];
        $this->web()->execute("UPDATE registros_rrhh SET asociacion_estado = 'NO_IDENTIFICADO_CONFIRMADO', asociacion_metodo = 'MANUAL',
                                      asociacion_confianza = 'MANUAL', asociado_por = ?, asociado_at = now() WHERE id = ?", [$this->user, $r['id']]);
        self::owner()->execute("UPDATE analisis_archivo SET advertencias = '[]'::jsonb WHERE archivo_id = ? AND vigente", [$aid]);
        $this->requeue($aid, true);
        $new = $this->regs($aid);
        self::assertSame('NO_IDENTIFICADO_CONFIRMADO', $new[0]['asociacion_estado']);
        self::assertNull($this->emp('90005'));
        self::assertNotNull($this->emp('90006'));
    }

    public function testManualMasterCorrectionPrevailsOverRrhh(): void
    {
        $this->add(self::tab([FX::person(1)]), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 1));
        $id = $this->empId('90001');
        $this->web()->transaction(function (Database $w) use ($id): void {
            $w->execute("UPDATE empleados SET issemym = 'MANUAL1', activo = false WHERE id = ?", [$id]);
            $w->execute("INSERT INTO empleado_historial (empleado_id, campo, valor_anterior, valor_nuevo, fuente, usuario_id, motivo)
                         VALUES (?, 'issemym', NULL, 'MANUAL1', 'MANUAL', ?, 'Corrección ficticia'),
                                (?, 'activo', 'true', 'false', 'MANUAL', ?, 'Baja ficticia')", [$id, $this->user, $id, $this->user]);
        });
        $this->add(self::tab([FX::person(1, ['nombre' => 'FICTICIO1 NUEVO1 NOMBRE1'])]), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 2));
        $e = $this->emp('90001');
        self::assertSame(['MANUAL1', false], [$e['issemym'], $e['activo']], 'RRHH no pisa correcciones manuales');
        self::assertSame(['FICTICIO1 NUEVO1 NOMBRE1', 'FICTICIO1 NUEVO1 NOMBRE1'], [$e['nombre_completo'], $e['nombre_normalizado']], 'Clave de nombre al día');
        self::assertSame(1, $this->intValue("SELECT count(*) FROM incidencias_identidad WHERE tipo = 'DATOS_RRHH_CONTRADICTORIOS' AND empleado_id = ?", $e['id']));
    }

    public function testUnknownNumberWithOtherStrongEvidenceIsConflict(): void
    {
        $this->add(self::tab([FX::person(1), FX::person(2)]), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 1));
        $aid = $this->add(FX::bbva([['97777', 'OTRO NOMBRE FICTICIO', FX::rfc(2), '10.00', '0100000009']]), 'xlsx', 'TESORERIA_DISPERSION',
            $this->lote(1, 1));
        $r = $this->regs($aid, 'registros_tesoreria')[0];
        self::assertSame(['CONFLICTO', 'NUMERO_DESCONOCIDO_CON_OTRA_EVIDENCIA'],
            [$r['asociacion_estado'], self::jsonField($r['asociacion_evidencia'])['motivo'] ?? null]);
    }

    public function testSameAccountTwoPeopleInOneFileIsConflict(): void
    {
        $this->add(self::tab([FX::person(1), FX::person(2)]), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 1));
        $aid = $this->add(self::sant([FX::person(1), FX::person(2)], ['90001' => '60055500001', '90002' => '60055500001']), 'xlsx',
            'TESORERIA_DISPERSION', $this->lote(1, 1));
        $regs = $this->regs($aid, 'registros_tesoreria');
        self::assertSame(['ASOCIADO_AUTOMATICO', 'CONFLICTO'], array_column($regs, 'asociacion_estado'));
        self::assertSame(1, $this->intValue("SELECT count(*) FROM incidencias_identidad WHERE tipo = 'CLABE_CONFLICTO'"));
        self::assertSame(1, $this->intValue('SELECT count(*) FROM cuentas_bancarias'));
    }

    public function testAccountViewsAreIdempotentAndAnnulledOriginIsNotEvidence(): void
    {
        $ps = [FX::person(1)];
        $this->add(self::tab($ps), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 1));
        $s1 = $this->add(self::sant($ps), 'xlsx', 'TESORERIA_DISPERSION', $this->lote(1, 1));
        $this->requeue($s1, true);
        $this->requeue($s1, true);
        self::assertSame(1, $this->intValue('SELECT veces_vista FROM cuentas_bancarias'), 'Re-normalizar no suma vistas');
        self::owner()->execute("UPDATE archivos_importados SET estado = 'ANULADO', anulado_at = now(), anulado_por = ? WHERE id = ?", [$this->user, $s1]);
        $aid = $this->add(self::sant([FX::person(9)], ['90009' => '60000090001']), 'xlsx', 'TESORERIA_DISPERSION', $this->lote(1, 2));
        self::assertSame('NO_IDENTIFICADO', $this->regs($aid, 'registros_tesoreria')[0]['asociacion_estado']);
    }

    public function testClosedPeriodIsNotReassociated(): void
    {
        $ps = [FX::person(1)];
        $s = $this->add(self::sant($ps), 'xlsx', 'TESORERIA_DISPERSION', $this->lote(1, 1));
        $this->add(self::tab($ps), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 2));
        $per = $this->periodo(1, 1);
        self::owner()->execute("UPDATE periodos_nomina SET estado = 'CERRADO', cerrado_por = ?, cerrado_at = now() WHERE id = ?", [$this->user, $per]);
        $this->requeue($s, false);
        self::assertSame('NO_IDENTIFICADO', $this->regs($s, 'registros_tesoreria')[0]['asociacion_estado'], 'El periodo cerrado queda congelado');
        self::owner()->execute("UPDATE periodos_nomina SET estado = 'ABIERTO', cerrado_por = NULL, cerrado_at = NULL WHERE id = ?", [$per]);
        $this->requeue($s, false);
        self::assertSame('ASOCIADO_AUTOMATICO', $this->regs($s, 'registros_tesoreria')[0]['asociacion_estado']);
    }

    public function testUserClosedIncidentIsNotReopened(): void
    {
        $aid = $this->add(self::tab([FX::person(1), FX::person(1, ['nombre' => 'OTRA FICTICIA PERSONA'])]), 'xlsx', 'RRHH_TABULADO');
        $inc = $this->one("SELECT id FROM incidencias_identidad WHERE tipo = 'NUMERO_DUPLICADO_EN_ARCHIVO'");
        self::owner()->execute("UPDATE incidencias_identidad SET estado = 'DESCARTADA', resuelto_por = ?, resuelto_at = now(), resolucion = 'Revisado'
                                WHERE id = ?", [$this->user, $inc['id']]);
        $this->requeue($aid, false);
        self::assertSame('DESCARTADA', $this->one('SELECT estado FROM incidencias_identidad WHERE id = ?', $inc['id'])['estado']);
    }

    public function testDbErrorDetailNeverContainsRowData(): void
    {
        $o = self::owner();
        $o->execute("ALTER TABLE empleados ADD CONSTRAINT zz_prueba CHECK (nombre_completo <> 'FICTICIO1 APELLIDO1 NOMBRE1') NOT VALID");
        try {
            $aid = $this->add(self::tab([FX::person(1)]), 'xlsx', 'RRHH_TABULADO', null, false);
            $w = self::worker(self::settings(), 'phpunit-err');
            for ($i = 0; $i < 8; $i++) {
                $w->run(true);
            }
            $err = $this->one("SELECT error_codigo, error_detalle FROM procesos WHERE tipo = 'NORMALIZAR_REGISTROS' AND parametros->>'archivo_id' = ?",
                (string) $aid);
            self::assertSame('NORMALIZACION_INCONSISTENTE', $err['error_codigo']);
            self::assertStringNotContainsString('FICTICIO1', (string) $err['error_detalle']);
            self::assertStringContainsString('zz_prueba', (string) $err['error_detalle']);
        } finally {
            $o->execute('ALTER TABLE empleados DROP CONSTRAINT zz_prueba');
            $o->execute("UPDATE procesos SET estado = 'CANCELADO', finalizado_at = now() WHERE estado IN ('PENDIENTE', 'ERROR') AND tipo = 'NORMALIZAR_REGISTROS'");
        }
    }
}
