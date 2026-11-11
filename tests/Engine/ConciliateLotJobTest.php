<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Engine;

use ConciliaRH\Core\Database;
use ConciliaRH\Engine\Support\Dec;
use ConciliaRH\Engine\Support\Json;
use ConciliaRH\Tests\Fixtures\IdentityFixtures as FX;
use ConciliaRH\Tests\Support\EngineTestCase;
use PDOException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ConciliateLotJobTest extends EngineTestCase
{
    private int $user;
    /** @var array<string,int> */
    private array $periodos = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::cleanAll();
        $this->periodos = [];
        $this->user = (int) self::owner()->fetchValue(
            "INSERT INTO usuarios (username, nombre, password_hash) VALUES ('eng.identidad', 'Tester Identidad', repeat('x', 30))
             ON CONFLICT (username) DO UPDATE SET nombre = EXCLUDED.nombre RETURNING id");
        self::owner()->execute("INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = 'ADMIN' ON CONFLICT DO NOTHING",
            ['u' => $this->user]);
    }

    protected function tearDown(): void
    {
        if (CONCILIARH_DB_AVAILABLE) {
            self::cleanAll();
        }
        parent::tearDown();
    }

    private static function cleanAll(): void
    {
        self::cleanIdentity();
        $o = self::owner();
        $o->execute('UPDATE archivos_importados SET analisis_vigente_id = NULL');
        foreach (['staging_registros', 'controles_archivo', 'archivo_hojas', 'analisis_archivo', 'archivos_importados', 'lotes_importacion', 'periodos_nomina'] as $t) {
            $o->execute("DELETE FROM $t");
        }
    }

    private function lote(int $mes = 1, int $q = 1): int
    {
        $key = "$mes:$q";
        if (!isset($this->periodos[$key])) {
            [$ini, $fin] = $q === 1 ? [sprintf('2042-%02d-01', $mes), sprintf('2042-%02d-15', $mes)] : [sprintf('2042-%02d-16', $mes), sprintf('2042-%02d-28', $mes)];
            $this->periodos[$key] = (int) self::owner()->fetchValue(
                "INSERT INTO periodos_nomina (anio, mes, quincena, fecha_inicio, fecha_fin, descripcion, creado_por)
                 VALUES (2042, :m, :q, :i, :f, 'PERIODO IDENTIDAD', :u) RETURNING id", ['m' => $mes, 'q' => $q, 'i' => $ini, 'f' => $fin, 'u' => $this->user]);
        }
        return (int) self::owner()->fetchValue("SELECT id FROM fn_crear_lote(:p, '', '', '', '', :u)", ['p' => $this->periodos[$key], 'u' => $this->user]);
    }

    private function add(string $data, string $ext, string $tipo, int $lote, bool $drain = true): int
    {
        $o = self::owner();
        $uid = sprintf('%s-%s-4%s-a%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1),
            substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6)));
        $per = (int) $o->fetchValue('SELECT periodo_id FROM lotes_importacion WHERE id = :l', ['l' => $lote]);
        $rel = "imports/$per/$lote/$uid/original.$ext";
        $dst = self::storage() . '/' . $rel;
        mkdir(dirname($dst), 0775, true);
        file_put_contents($dst, $data);
        chmod($dst, 0440);
        $tid = (int) $o->fetchValue('SELECT id FROM tipos_documento WHERE codigo = :c', ['c' => $tipo]);
        $oid = (int) $o->fetchValue("SELECT id FROM origenes_administrativos WHERE codigo = 'SIN_DEFINIR'");
        $aid = (int) $o->fetchValue(
            "INSERT INTO archivos_importados (uuid, lote_id, tipo_documento_id, origen_administrativo_id, nombre_original, extension,
                 mime_detectado, firma, tamano_bytes, sha256, ruta_relativa, usuario_carga_id, tiene_macros)
             VALUES (:uuid, :l, :t, :o, :n, :e, 'application/octet-stream', :f, :s, :h, :r, :u, false) RETURNING id",
            ['uuid' => $uid, 'l' => $lote, 't' => $tid, 'o' => $oid, 'n' => "ficticio.$ext", 'e' => $ext, 'f' => $ext === 'xls' ? 'OLE2' : 'OOXML',
             's' => strlen($data), 'h' => hash('sha256', $data), 'r' => $rel, 'u' => $this->user]);
        $job = (int) $o->fetchValue("INSERT INTO procesos (tipo, parametros, max_intentos) VALUES ('VERIFICAR_ARCHIVO', CAST(:p AS jsonb), 2) RETURNING id",
            ['p' => Json::encode(['archivo_id' => $aid])]);
        $o->execute('UPDATE archivos_importados SET proceso_id = :j WHERE id = :a', ['j' => $job, 'a' => $aid]);
        if ($drain) {
            $this->drain();
        }
        return $aid;
    }

    private function drain(int $n = 30): void
    {
        $w = self::worker(null, 'phpunit-identidad');
        for ($i = 0; $i < $n; $i++) {
            if (self::owner()->fetchValue("SELECT 1 FROM procesos WHERE estado = 'PENDIENTE' AND intentos < max_intentos") === null) {
                break;
            }
            $w->run(true);
        }
        $bad = self::owner()->fetchAll("SELECT tipo, error_codigo, error_detalle FROM procesos WHERE estado = 'ERROR'");
        self::assertSame([], $bad);
    }

    private function web(?int $asUser = null): Database
    {
        $db = self::webDb();
        self::bindActor($db, $asUser ?? $this->user);
        return $db;
    }

    /** @return array<string,mixed> */
    private static function one(string $sql, array $params = []): array
    {
        $row = self::owner()->fetchOne($sql, $params);
        self::assertNotNull($row, $sql);
        return $row;
    }

    /** @return array<string,mixed> */
    private function emp(string $numero): array
    {
        return self::one('SELECT * FROM empleados WHERE numero_empleado = :n', ['n' => $numero]);
    }

    /** @return array<string,mixed> */
    private function conciliar(int $lote, bool $drain = true): array
    {
        $c = $this->web()->fetchOne("SELECT * FROM fn_solicitar_conciliacion(:l, :u, 'Prueba de conciliación')", ['l' => $lote, 'u' => $this->user]);
        self::assertNotNull($c);
        $job = self::one('SELECT * FROM procesos WHERE id = :id', ['id' => $c['proceso_id']]);
        self::assertSame(['CONCILIAR_LOTE', ['conciliacion_id' => (int) $c['id']], $this->user],
            [$job['tipo'], Json::decode($job['parametros']), (int) $job['creado_por']], 'La BD encola el trabajo en la misma transacción que la solicitud');
        if ($drain) {
            $this->drain();
        }
        return self::one('SELECT * FROM conciliaciones WHERE id = :id', ['id' => $c['id']]);
    }

    /** @return array<string,array<string,mixed>> */
    private static function resultados(int $cid): array
    {
        $out = [];
        foreach (self::owner()->fetchAll('SELECT * FROM conciliacion_empleados WHERE conciliacion_id = :c', ['c' => $cid]) as $r) {
            $out[(string) $r['numero_empleado']] = $r;
        }
        return $out;
    }

    private static function money(string $v): string
    {
        $neg = str_starts_with($v, '-');
        $q = Dec::quantizeHalfEven(ltrim($v, '-'), 2);
        [$int, $frac] = array_pad(explode('.', $q), 2, '00');
        return ($neg ? '-' : '') . strrev(implode(',', str_split(strrev($int), 3))) . '.' . str_pad($frac, 2, '0');
    }

    /**
     * @param list<array<string,mixed>> $people
     * @param list<array{0:string,1:string,2:string,3:string}> $pagosSant
     * @param list<array{0:string,1:string,2:string}> $pagosInter
     */
    private function loteCompleto(int $lote, array $people, array $pagosSant, array $pagosInter = [], bool $deduccionesNegativas = false,
                                  ?string $pensionFile = null): void
    {
        $this->add(FX::tabulado($people, deduccionesNegativas: $deduccionesNegativas), 'xlsx', 'RRHH_TABULADO', $lote);
        $neto = Dec::sum(array_map(static fn ($p) => Dec::sub(Dec::sub($p['sueldo'], $p['isr']), $p['pension']), $people));
        $ded = Dec::sum(array_map(static fn ($p) => Dec::add($p['isr'], $p['pension']), $people));
        $this->add(FX::polizaGlobal([['1', 'SUELDO BASE', (float) Dec::add($neto, $ded)]], [['70', 'ISR', (float) $ded]]), 'xlsx', 'RRHH_POLIZA_GLOBAL', $lote);
        $rows = array_map(static fn ($x) => [$x[0], ...explode(' ', $x[1]), $x[2], $x[3]], $pagosSant);
        $this->add(FX::santander($rows), 'xlsx', 'TESORERIA_DISPERSION', $lote);
        $totSant = Dec::sum(array_map(static fn ($x) => $x[3], $pagosSant));
        $hojas = [['SANTANDER $' . self::money($totSant), $totSant]];
        if ($pagosInter !== []) {
            $this->add(FX::interbancaria($pagosInter), 'xls', 'TESORERIA_DISPERSION', $lote);
            $tot = Dec::sum(array_map(static fn ($x) => $x[2], $pagosInter));
            $hojas[] = ['SANTANDER $' . self::money($tot) . ' I', $tot];
        }
        if ($pensionFile !== null) {
            $this->add($pensionFile, 'xlsx', 'TESORERIA_PENSION', $lote);
        }
        $this->add(FX::polizaEgresoBancos($hojas), 'xlsx', 'POLIZA_EGRESO_BANCARIA', $lote);
    }

    /** @return array<string,mixed> */
    private static function p(int $i, array $kw = []): array
    {
        return FX::person($i, $kw);
    }

    /** @return array{0:string,1:string,2:string,3:string} */
    private static function pago(array $p, ?string $imp = null, ?string $cta = null): array
    {
        return [$p['num'], $p['nombre'], $cta ?? sprintf('600%08d', (int) $p['num']),
                $imp ?? Dec::sub(Dec::sub($p['sueldo'], $p['isr']), $p['pension'])];
    }

    /** @param list<array<string,mixed>> $people */
    private static function sant(array $people): string
    {
        $rows = [];
        foreach ($people as $p) {
            [$pat, $mat, $nom] = explode(' ', $p['nombre']);
            $rows[] = [$p['num'], $pat, $mat, $nom, sprintf('600%08d', (int) $p['num']), '5000.00'];
        }
        return FX::santander($rows);
    }

    private static function assertSqlState(string $state, callable $fn): void
    {
        try {
            $fn();
        } catch (PDOException $e) {
            self::assertSame($state, (string) $e->getCode(), $e->getMessage());
            return;
        }
        self::fail("Se esperaba un error SQLSTATE $state");
    }

    private static function assertDbError(callable $fn): void
    {
        try {
            $fn();
        } catch (PDOException) {
            self::assertTrue(true);
            return;
        }
        self::fail('Se esperaba un error de la base de datos');
    }

    private static function year(string $ts): int
    {
        return (int) substr($ts, 0, 4);
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function closed(): array
    {
        $lote = $this->lote(3, 1);
        $ps = [self::p(1)];
        $this->loteCompleto($lote, $ps, [self::pago($ps[0], '8000.00')]);
        return [$lote, $this->conciliar($lote)];
    }

    public function testFullLotLevelsAndExplainability(): void
    {
        $lote = $this->lote(3, 1);
        $ps = [self::p(1), self::p(2, ['pension' => '300.00']), self::p(3), self::p(4)];
        $pen = FX::santander([[$ps[1]['num'], 'BENEFICIARIA', 'FICTICIA', 'UNO', '60099988877', '300.00']], pension: true);
        $this->loteCompleto($lote, $ps, [self::pago($ps[0]), self::pago($ps[1]), self::pago($ps[2], '8000.00')],
            [['002180000000012345', 'OTRA PERSONA FICTICIA', '100.00']], pensionFile: $pen);
        $c = $this->conciliar($lote);
        self::assertSame(['COMPLETADA_CON_INCIDENCIAS', 1, \ConciliaRH\Engine\Conciliation\Engine::VERSION, false], [$c['estado'], (int) $c['numero'], (int) $c['motor_version'], $c['lote_oficial']]);
        $r = self::resultados((int) $c['id']);
        $estados = array_map(static fn ($v) => $v['estado'], $r);
        ksort($estados);
        self::assertSame(['90001' => 'CORRECTO', '90002' => 'CORRECTO', '90003' => 'PAGO_MENOR', '90004' => 'NO_PAGADO'], $estados);
        self::assertTrue(Dec::eq('-500.00', (string) $r['90003']['diferencia']));
        self::assertTrue(Dec::eq('8500.00', (string) $r['90004']['neto_esperado']));
        $t = Json::decode($c['totales'])['TOTAL'];
        self::assertSame(['100.00', '300.00', '300.00'], [$t['tesoreria_nomina']['pendiente_identidad'], $t['pensiones']['dispersado'], $t['pensiones']['retenido']]);
        $penRes = self::owner()->fetchAll('SELECT estado FROM conciliacion_pensiones WHERE conciliacion_id = :c', ['c' => $c['id']]);
        self::assertSame(['CORRECTA'], array_column($penRes, 'estado'));
        $ctl = [];
        foreach (self::owner()->fetchAll('SELECT * FROM conciliacion_controles WHERE conciliacion_id = :c', ['c' => $c['id']]) as $k) {
            $ctl[$k['nivel'] . ':' . $k['codigo']] = $k['estado'];
        }
        self::assertSame('CUADRA', $ctl['1:RRHH_GLOBAL_NETO']);
        self::assertSame('CUADRA', $ctl['3:POLIZA_EGRESO_VS_DISPERSION']);
        self::assertSame('NO_CUADRA', $ctl['2:GLOBAL_VS_EGRESO_NETO'], 'La GLOBAL autoriza más de lo que pagan los bancos');
        $f = self::owner()->fetchAll('SELECT tipo, archivo_id, fila_origen, importe FROM conciliacion_fuentes WHERE conciliacion_id = :c AND empleado_id = :e
                                      ORDER BY tipo', ['c' => $c['id'], 'e' => $r['90003']['empleado_id']]);
        self::assertSame([['RRHH', Dec::key('8500.00')], ['TESORERIA_NOMINA', Dec::key('8000.00')]],
            array_map(static fn ($x) => [$x['tipo'], Dec::key((string) $x['importe'])], $f));
        $inc = array_column(self::owner()->fetchAll('SELECT tipo FROM conciliacion_incidencias WHERE conciliacion_id = :c', ['c' => $c['id']]), 'tipo');
        self::assertEmpty(array_diff(['PAGO_MENOR', 'NO_PAGADO', 'IMPORTE_PENDIENTE_IDENTIDAD', 'GLOBAL_EGRESO_NO_CUADRA'], $inc));
        $audit = self::one("SELECT origen, contexto FROM auditoria WHERE accion = 'conciliaciones.ejecutar' AND entidad_id = :e", ['e' => (string) $c['id']]);
        self::assertSame('WORKER', $audit['origen']);
        self::assertStringNotContainsString('FICTICIO', (string) json_encode($audit, JSON_UNESCAPED_UNICODE));
        self::assertGreaterThanOrEqual(0, Json::decode($c['metricas'])['total_ms']);
        self::assertNotEmpty($c['huella_entradas']);
    }

    public function testCase23NegativeDeductionsSemantics(): void
    {
        $lote = $this->lote(3, 1);
        $ps = [self::p(1, ['pension' => '250.00'])];
        $pen = FX::santander([[$ps[0]['num'], 'BENEFICIARIA', 'FICTICIA', 'UNO', '60099988877', '250.00']], pension: true);
        $this->loteCompleto($lote, $ps, [self::pago($ps[0])], deduccionesNegativas: true, pensionFile: $pen);
        $c = $this->conciliar($lote);
        $f = self::one("SELECT importe_original, importe FROM conciliacion_fuentes WHERE conciliacion_id = :c AND tipo = 'RRHH_PENSION'", ['c' => $c['id']]);
        self::assertSame([Dec::key('-250.00'), Dec::key('250.00')], [Dec::key((string) $f['importe_original']), Dec::key((string) $f['importe'])],
            'El signo se invierte UNA vez según la convención');
        self::assertSame('CORRECTA', self::one('SELECT estado FROM conciliacion_pensiones WHERE conciliacion_id = :c', ['c' => $c['id']])['estado']);
    }

    public function testCase26And28DuplicateFileInLotAndOtherLotAreNotMixed(): void
    {
        [$lote1, $lote2] = [$this->lote(3, 1), $this->lote(3, 1)];
        $ps = [self::p(1), self::p(2)];
        $this->loteCompleto($lote1, $ps, [self::pago($ps[0]), self::pago($ps[1])]);
        $rows = array_map(static fn ($p) => [$p['num'], ...explode(' ', $p['nombre']), sprintf('600%08d', (int) $p['num']), Dec::sub($p['sueldo'], $p['isr'])], $ps);
        $tmp = tempnam(sys_get_temp_dir(), 'crh');
        file_put_contents($tmp, FX::santander($rows));
        $wb = IOFactory::load($tmp);
        $wb->getProperties()->setTitle('Otro binario, mismo contenido');
        (new Xlsx($wb))->save($tmp);
        $data = (string) file_get_contents($tmp);
        unlink($tmp);
        $this->add($data, 'xlsx', 'TESORERIA_DISPERSION', $lote1);
        $this->loteCompleto($lote2, $ps, [self::pago($ps[0], '1.00'), self::pago($ps[1])]);
        $c1 = $this->conciliar($lote1);
        $r1 = self::resultados((int) $c1['id']);
        self::assertTrue(Dec::eq('8500.00', (string) $r1['90001']['pagado_total']));
        self::assertSame('CORRECTO', $r1['90001']['estado']);
        $roles = array_column(self::owner()->fetchAll('SELECT rol FROM conciliacion_archivos WHERE conciliacion_id = :c ORDER BY archivo_id', ['c' => $c1['id']]), 'rol');
        self::assertSame(1, count(array_keys($roles, 'TESORERIA_NOMINA', true)));
        self::assertContains('EXCLUIDO_DUPLICADO', $roles);
        $c2 = $this->conciliar($lote2);
        self::assertSame('PAGO_MENOR', self::resultados((int) $c2['id'])['90001']['estado']);
    }

    public function testCase29To35NewExecutionReflectsAssociationAndHistoryIsFrozen(): void
    {
        $lote = $this->lote(3, 1);
        $ps = [self::p(1), self::p(2)];
        $this->loteCompleto($lote, [$ps[0], $ps[1]], [self::pago($ps[0])],
            [['002180000000012345', $ps[1]['nombre'], Dec::sub($ps[1]['sueldo'], $ps[1]['isr'])]]);
        $c1 = $this->conciliar($lote);
        self::assertSame('PENDIENTE_IDENTIDAD', self::resultados((int) $c1['id'])['90002']['estado']);
        $c1b = $this->conciliar($lote);
        self::assertSame($c1['huella_entradas'], $c1b['huella_entradas']);
        self::assertSame((int) $c1['id'], (int) $c1b['igual_a_id']);
        $proj = static function (array $rs): array {
            $out = [];
            foreach ($rs as $k => $v) {
                $out[$k] = [$v['estado'], Dec::key((string) $v['diferencia'])];
            }
            ksort($out);
            return $out;
        };
        self::assertSame($proj(self::resultados((int) $c1['id'])), $proj(self::resultados((int) $c1b['id'])));
        $reg = self::one("SELECT id FROM registros_tesoreria WHERE banco = 'INTERBANCARIA'");
        $emp2 = (int) $this->emp('90002')['id'];
        $this->web()->execute("UPDATE registros_tesoreria SET empleado_id = :e, asociacion_estado = 'ASOCIADO_MANUAL', asociacion_metodo = 'MANUAL',
                                      asociacion_confianza = 'MANUAL', asociado_por = :u, asociado_at = now() WHERE id = :id",
            ['e' => $emp2, 'u' => $this->user, 'id' => $reg['id']]);
        $c2 = $this->conciliar($lote);
        self::assertSame([3, 'CORRECTO'], [(int) $c2['numero'], self::resultados((int) $c2['id'])['90002']['estado']]);
        self::assertSame('PENDIENTE_IDENTIDAD', self::resultados((int) $c1['id'])['90002']['estado'], 'La conciliación histórica no cambia');
        self::assertNotSame($c1['huella_entradas'], $c2['huella_entradas']);
        self::assertNull($c2['igual_a_id']);
        self::assertDbError(static fn () => self::owner()->execute("UPDATE conciliacion_empleados SET estado = 'CORRECTO' WHERE conciliacion_id = :c",
            ['c' => $c1['id']]));
        self::assertDbError(static fn () => self::owner()->execute("UPDATE conciliaciones SET totales = '{}' WHERE id = :c", ['c' => $c1['id']]));
    }

    public function testLotInProcessIsRejectedThenConciliable(): void
    {
        $lote = $this->lote(3, 1);
        $ps = [self::p(1)];
        $this->loteCompleto($lote, $ps, [self::pago($ps[0])]);
        $this->add(self::sant([self::p(5)]), 'xlsx', 'TESORERIA_DISPERSION', $lote, false);
        self::assertSqlState('55006', fn () => $this->web()->fetchValue("SELECT fn_solicitar_conciliacion(:l, :u, 'Prueba')", ['l' => $lote, 'u' => $this->user]));
        $this->drain();
        $c = $this->conciliar($lote);
        self::assertContains($c['estado'], ['COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS']);
    }

    public function testJobRetriesWhenLotGetsANewFileAfterTheRequest(): void
    {
        $lote = $this->lote(3, 1);
        $ps = [self::p(1)];
        $this->loteCompleto($lote, $ps, [self::pago($ps[0])]);
        $c = $this->conciliar($lote, false);
        $this->add(self::sant([self::p(5)]), 'xlsx', 'TESORERIA_DISPERSION', $lote, false);
        $w = self::worker(null, 'phpunit-conc');
        $job = (int) self::one("SELECT id FROM procesos WHERE tipo = 'CONCILIAR_LOTE'")['id'];
        self::owner()->execute('UPDATE procesos SET prioridad = 0 WHERE id = :id', ['id' => $job]);
        $w->run(true);
        $j = self::one('SELECT estado, error_codigo, intentos FROM procesos WHERE id = :id', ['id' => $job]);
        self::assertSame(['PENDIENTE', 'LOTE_EN_PROCESO'], [$j['estado'], $j['error_codigo']], 'No concluye con datos incompletos: reintenta');
        $this->drain();
        self::owner()->execute('UPDATE procesos SET disponible_desde = now() WHERE id = :id', ['id' => $job]);
        $this->drain();
        self::assertContains(self::one('SELECT estado FROM conciliaciones WHERE id = :id', ['id' => $c['id']])['estado'], ['COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS']);
    }

    public function testWebRoleCannotForgeResultsOrStates(): void
    {
        $lote = $this->lote(3, 1);
        $ps = [self::p(1)];
        $this->loteCompleto($lote, $ps, [self::pago($ps[0])]);
        $c = $this->conciliar($lote);
        $w = $this->web();
        foreach (["UPDATE conciliaciones SET totales = '{}' WHERE id = :c",
                  "INSERT INTO conciliacion_empleados (conciliacion_id, empleado_id, grupo, numero_empleado, estado) VALUES (:c, 1, 'POLICIA', 'x', 'CORRECTO')",
                  'DELETE FROM conciliacion_incidencias WHERE conciliacion_id = :c'] as $sql) {
            self::assertSqlState('42501', static fn () => $w->execute($sql, ['c' => $c['id']]));
        }
        self::assertDbError(static fn () => $w->execute("UPDATE conciliaciones SET estado = 'COMPLETADA' WHERE id = :c", ['c' => $c['id']]));
        self::assertSqlState('42501', fn () => $w->fetchValue("SELECT fn_solicitar_conciliacion(:l, :u, 'x')", ['l' => $lote, 'u' => $this->user + 100000]));
    }

    public function testDbRulesForConciliationLifecycle(): void
    {
        [$lote, $c] = $this->closed();
        $other = (int) self::owner()->fetchValue(
            "INSERT INTO usuarios (username, nombre, password_hash) VALUES ('eng.otro', 'Otro', repeat('x', 30))
             ON CONFLICT (username) DO UPDATE SET nombre = EXCLUDED.nombre RETURNING id");
        $w = $this->web();
        foreach (["UPDATE conciliaciones SET estado = 'ERROR' WHERE id = :c", 'UPDATE conciliaciones SET proceso_id = proceso_id + 1 WHERE id = :c'] as $sql) {
            self::assertDbError(static fn () => $w->execute($sql, ['c' => $c['id']]));
        }
        $w->execute("UPDATE conciliaciones SET estado = 'CERRADA', cerrada_por = :u, cerrada_at = '2001-01-01', motivo_cierre = 'Cierre de prueba'
                     WHERE id = :c", ['u' => $this->user, 'c' => $c['id']]);
        $row = self::one('SELECT cerrada_at, motivo_cierre FROM conciliaciones WHERE id = :c', ['c' => $c['id']]);
        self::assertGreaterThan(2001, self::year((string) $row['cerrada_at']));
        $w = $this->web();
        self::assertSqlState('23514', fn () => $w->fetchValue("SELECT fn_solicitar_conciliacion(:l, :u, 'Reproceso de prueba')", ['l' => $lote, 'u' => $this->user]));
        self::assertSqlState('42501', fn () => $w->execute(
            "UPDATE conciliaciones SET estado = 'INVALIDADA', invalidada_por = :u, invalidada_at = now(),
                 motivo_invalidacion = 'x', cerrada_por = NULL, cerrada_at = NULL WHERE id = :c", ['u' => $this->user, 'c' => $c['id']]));
        self::owner()->execute("INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = 'GOD' ON CONFLICT DO NOTHING", ['u' => $other]);
        $w = $this->web($other);
        self::assertSqlState('23514', static fn () => $w->execute(
            "UPDATE conciliaciones SET estado = 'INVALIDADA', invalidada_por = :u, motivo_invalidacion = 'Error de captura',
                 cerrada_por = NULL, cerrada_at = NULL WHERE id = :c", ['u' => $other, 'c' => $c['id']]));
        $w->execute("UPDATE conciliaciones SET estado = 'INVALIDADA', invalidada_por = :u, motivo_invalidacion = 'Error de captura' WHERE id = :c",
            ['u' => $other, 'c' => $c['id']]);
        self::assertSame($this->user, (int) self::one('SELECT cerrada_por FROM conciliaciones WHERE id = :c', ['c' => $c['id']])['cerrada_por']);
    }

    public function testOnlyLatestRunIsClosedAndRerunRules(): void
    {
        $lote = $this->lote(3, 1);
        $ps = [self::p(1)];
        $this->loteCompleto($lote, $ps, [self::pago($ps[0])]);
        $c1 = $this->conciliar($lote);
        $w = $this->web();
        self::assertSqlState('22023', fn () => $w->fetchValue("SELECT fn_solicitar_conciliacion(:l, :u, '')", ['l' => $lote, 'u' => $this->user]));
        $c2 = $this->conciliar($lote);
        $w = $this->web();
        self::assertSqlState('23514', fn () => $w->execute(
            "UPDATE conciliaciones SET estado = 'CERRADA', cerrada_por = :u, motivo_cierre = 'Cierre de la versión vieja' WHERE id = :c",
            ['u' => $this->user, 'c' => $c1['id']]));
        $cons = (int) self::owner()->fetchValue(
            "INSERT INTO usuarios (username, nombre, password_hash) VALUES ('eng.consulta', 'Consulta', repeat('x', 30))
             ON CONFLICT (username) DO UPDATE SET nombre = EXCLUDED.nombre RETURNING id");
        self::owner()->execute("INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = 'CONSULTA' ON CONFLICT DO NOTHING", ['u' => $cons]);
        $w = $this->web($cons);
        self::assertSqlState('42501', static fn () => $w->fetchValue("SELECT fn_solicitar_conciliacion(:l, :u, 'Intento sin permiso')", ['l' => $lote, 'u' => $cons]));
        self::assertSame(2, (int) $c2['numero']);
    }

    public function testIncidentUpdatesWriteHistoryInDbAndCannotBeForged(): void
    {
        $lote = $this->lote(3, 1);
        $ps = [self::p(1)];
        $this->loteCompleto($lote, $ps, [self::pago($ps[0], '8000.00')]);
        $c = $this->conciliar($lote);
        $inc = (int) self::one("SELECT id FROM conciliacion_incidencias WHERE conciliacion_id = :c AND tipo = 'PAGO_MENOR'", ['c' => $c['id']])['id'];
        $w = $this->web();
        self::assertSqlState('42501', fn () => $w->execute(
            "INSERT INTO conciliacion_incidencias_historial (incidencia_id, estado_anterior, estado_nuevo, usuario_id, comentario)
             VALUES (:i, 'ABIERTA', 'RESUELTA', :u, 'Historial falso')", ['i' => $inc, 'u' => $this->user]));
        self::assertSqlState('23514', static fn () => $w->execute("UPDATE conciliacion_incidencias SET comentario = 'Sólo comentario' WHERE id = :i", ['i' => $inc]));
        $w->execute("UPDATE conciliacion_incidencias SET estado = 'EN_REVISION', actualizado_por = :u, actualizado_at = '2001-01-01',
                            comentario = 'Revisando con Tesorería' WHERE id = :i", ['u' => $this->user, 'i' => $inc]);
        $h = self::owner()->fetchAll('SELECT estado_anterior, estado_nuevo, usuario_id, comentario FROM conciliacion_incidencias_historial WHERE incidencia_id = :i',
            ['i' => $inc]);
        self::assertSame([['ABIERTA', 'EN_REVISION', $this->user]], array_map(static fn ($x) => [$x['estado_anterior'], $x['estado_nuevo'], (int) $x['usuario_id']], $h));
        self::assertGreaterThan(2001, self::year((string) self::one('SELECT actualizado_at FROM conciliacion_incidencias WHERE id = :i', ['i' => $inc])['actualizado_at']));
    }

    public function testPolicyRelationRulesInDb(): void
    {
        $lote = $this->lote(3, 1);
        $ps = [self::p(1)];
        $this->loteCompleto($lote, $ps, [self::pago($ps[0])]);
        $otherLote = $this->lote(4, 1);
        $this->loteCompleto($otherLote, $ps, [self::pago($ps[0])]);
        $byType = static fn (int $l, string $t): int => (int) self::one(
            "SELECT a.id FROM archivos_importados a JOIN importaciones_normalizadas n ON n.archivo_id = a.id AND n.estado = 'VIGENTE'
              WHERE a.lote_id = :l AND n.tipo_registro = :t", ['l' => $l, 't' => $t])['id'];
        $eg = $byType($lote, 'POLIZA_EGRESO');
        $dispOtro = $byType($otherLote, 'TESORERIA');
        $disp = $byType($lote, 'TESORERIA');
        $ins = "INSERT INTO relaciones_poliza_dispersion (lote_id, archivo_egreso_id, hoja_indice, archivo_dispersion_id, motivo, creado_por)
                VALUES (:l, :e, 0, :d, 'Relación de prueba', :u) RETURNING id";
        $w = $this->web();
        self::assertSqlState('23514', fn () => $w->fetchValue($ins, ['l' => $lote, 'e' => $eg, 'd' => $dispOtro, 'u' => $this->user]));
        $rid = (int) $w->fetchValue($ins, ['l' => $lote, 'e' => $eg, 'd' => $disp, 'u' => $this->user]);
        $w->execute("UPDATE relaciones_poliza_dispersion SET estado = 'ANULADA', anulado_por = :u, anulado_at = '2001-01-01',
                            motivo_anulacion = 'Se relacionó por error' WHERE id = :id", ['u' => $this->user, 'id' => $rid]);
        self::assertSqlState('23514', static fn () => $w->execute(
            "UPDATE relaciones_poliza_dispersion SET estado = 'ACTIVA', anulado_por = NULL, anulado_at = NULL WHERE id = :id", ['id' => $rid]));
        self::assertGreaterThan(2001, self::year((string) self::one('SELECT anulado_at FROM relaciones_poliza_dispersion WHERE id = :id', ['id' => $rid])['anulado_at']));
    }

    public function testSessionBindingMarkerCannotBeSetByTheClient(): void
    {
        $w = $this->web();
        $w->fetchValue("SELECT set_config('conciliarh.sesion_vinculando', 'x' || repeat('0', 63), false)");
        self::assertDbError(fn () => $w->execute("INSERT INTO sesiones (id, datos, usuario_id) VALUES ('x' || repeat('0', 63), '', :u)", ['u' => $this->user]));
    }

    public function testLongLivedWorkerSessionSurvivesGenericPlans(): void
    {
        $lote = $this->lote(3, 1);
        $this->loteCompleto($lote, [self::p(1)], [self::pago(self::p(1))]);
        $w = self::workerDb();
        for ($i = 0; $i < 8; $i++) {
            $c = self::one('SELECT * FROM fn_solicitar_conciliacion(:l, :u, :m)', ['l' => $lote, 'u' => $this->user, 'm' => "Ciclo número $i"]);
            $w->execute("UPDATE conciliaciones SET estado = 'EJECUTANDO', iniciada_at = now() WHERE id = :id", ['id' => $c['id']]);
            $w->execute("UPDATE conciliaciones SET estado = 'COMPLETADA', motor_version = 1, totales = '{}', resumen = '{}', terminada_at = now() WHERE id = :id",
                ['id' => $c['id']]);
            self::owner()->execute("UPDATE procesos SET estado = 'CANCELADO', finalizado_at = now() WHERE id = :id AND estado = 'PENDIENTE'", ['id' => $c['proceso_id']]);
        }
        self::assertSame(8, (int) self::owner()->fetchValue("SELECT count(*) FROM conciliaciones WHERE lote_id = :l AND estado = 'COMPLETADA'", ['l' => $lote]));
    }
}
