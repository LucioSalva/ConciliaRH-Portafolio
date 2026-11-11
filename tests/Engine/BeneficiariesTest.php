<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Engine;

use ConciliaRH\Core\Database;
use ConciliaRH\Engine\Support\Json;
use ConciliaRH\Tests\Fixtures\IdentityFixtures as FX;
use ConciliaRH\Tests\Support\EngineTestCase;
use PDOException;

final class BeneficiariesTest extends EngineTestCase
{
    private const CLABE_A = '002180000000011111';
    private const CLABE_B = '002180000000022222';

    private int $user = 0;
    /** @var array<string,int> */
    private array $periodos = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::cleanAll();
        $o = self::owner();
        $this->user = (int) $o->fetchValue(
            "INSERT INTO usuarios (username, nombre, password_hash) VALUES ('eng.identidad', 'Tester Identidad', repeat('x', 30))
             ON CONFLICT (username) DO UPDATE SET nombre = EXCLUDED.nombre RETURNING id");
        $o->execute("INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = 'ADMIN' ON CONFLICT DO NOTHING",
            ['u' => $this->user]);
        $this->periodos = [];
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
        $key = "$mes-$q";
        if (!isset($this->periodos[$key])) {
            [$ini, $fin] = $q === 1 ? [sprintf('2042-%02d-01', $mes), sprintf('2042-%02d-15', $mes)] : [sprintf('2042-%02d-16', $mes), sprintf('2042-%02d-28', $mes)];
            $this->periodos[$key] = (int) self::owner()->fetchValue(
                "INSERT INTO periodos_nomina (anio, mes, quincena, fecha_inicio, fecha_fin, descripcion, creado_por)
                 VALUES (2042, :m, :q, :i, :f, 'PERIODO IDENTIDAD', :u) RETURNING id",
                ['m' => $mes, 'q' => $q, 'i' => $ini, 'f' => $fin, 'u' => $this->user]);
        }
        return (int) self::owner()->fetchValue("SELECT id FROM fn_crear_lote(:p, '', '', '', '', :u)", ['p' => $this->periodos[$key], 'u' => $this->user]);
    }

    private function add(string $data, string $ext, string $tipo, int $lote): int
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
        $this->drain();
        return $aid;
    }

    private function drain(int $n = 30): void
    {
        $w = self::worker(null, 'phpunit-identidad');
        for ($i = 0; $i < $n; $i++) {
            if (self::owner()->fetchOne("SELECT 1 FROM procesos WHERE estado = 'PENDIENTE' AND intentos < max_intentos") === null) {
                break;
            }
            $w->run(true);
        }
        $bad = self::owner()->fetchAll("SELECT tipo, error_codigo, error_detalle FROM procesos WHERE estado = 'ERROR'");
        self::assertSame([], $bad, Json::encode($bad));
    }

    /** @param array<string,mixed> $params @return array<string,mixed>|null */
    private function one(string $sql, array $params = []): ?array
    {
        return self::owner()->fetchOne($sql, $params);
    }

    /** @param array<string,mixed> $params @return list<array<string,mixed>> */
    private function all(string $sql, array $params = []): array
    {
        return self::owner()->fetchAll($sql, $params);
    }

    /** @return list<array<string,mixed>> */
    private function regs(int $aid, string $table = 'registros_rrhh'): array
    {
        $rows = $this->all("SELECT r.* FROM $table r JOIN importaciones_normalizadas n ON n.id = r.importacion_id
                            WHERE r.archivo_id = :a AND n.estado = 'VIGENTE' ORDER BY r.fila_origen", ['a' => $aid]);
        return array_map(static function (array $r): array {
            if (isset($r['asociacion_evidencia']) && is_string($r['asociacion_evidencia'])) {
                $r['asociacion_evidencia'] = json_decode($r['asociacion_evidencia'], true);
            }
            return $r;
        }, $rows);
    }

    private function empId(string $numero): int
    {
        return (int) $this->one('SELECT id FROM empleados WHERE numero_empleado = :n', ['n' => $numero])['id'];
    }

    private function web(?int $asUser = null): Database
    {
        $w = self::webDb();
        self::bindActor($w, $asUser ?? $this->user);
        return $w;
    }

    private function createUser(string $username, string $nombre): int
    {
        return (int) self::owner()->fetchValue(
            "INSERT INTO usuarios (username, nombre, password_hash) VALUES (:u, :n, repeat('x', 30))
             ON CONFLICT (username) DO UPDATE SET nombre = EXCLUDED.nombre RETURNING id", ['u' => $username, 'n' => $nombre]);
    }

    private static function assertSqlState(string $state, callable $fn): void
    {
        try {
            $fn();
        } catch (PDOException $e) {
            self::assertSame($state, (string) ($e->errorInfo[0] ?? $e->getCode()), $e->getMessage());
            return;
        }
        self::fail("Se esperaba el error SQLSTATE $state");
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

    private static function manualAssociation(Database $w, int $empId, int $userId, int $regId, bool $bumpVersion = false): void
    {
        $w->execute("UPDATE registros_tesoreria SET empleado_id = :e, asociacion_estado = 'ASOCIADO_MANUAL', asociacion_metodo = 'MANUAL',
                            asociacion_confianza = 'MANUAL', asociado_por = :u, asociado_at = now()"
            . ($bumpVersion ? ', asociacion_version = asociacion_version + 1' : '') . ' WHERE id = :r', ['e' => $empId, 'u' => $userId, 'r' => $regId]);
    }

    private function confirmar(int $regId, int $empId, ?int $beneficiario = null, ?int $user = null): int
    {
        $u = $user ?? $this->user;
        return (int) $this->web($user)->transaction(static function (Database $w) use ($regId, $empId, $beneficiario, $u): mixed {
            self::manualAssociation($w, $empId, $u, $regId, true);
            return $w->fetchValue("SELECT fn_confirmar_beneficiario(:r, :u, :b, 'Confirmado con el expediente ficticio') AS id",
                ['r' => $regId, 'u' => $u, 'b' => $beneficiario]);
        });
    }

    /** @param list<array{0:string,1:string,2:string,3:string}> $rows */
    private function pensionSant(array $rows, int $lote): int
    {
        $out = [];
        foreach ($rows as [$n, $nom, $cta, $imp]) {
            $out[] = [$n, ...explode(' ', $nom), $cta, $imp];
        }
        return $this->add(FX::santander($out, true), 'xlsx', 'TESORERIA_PENSION', $lote);
    }

    /** @param list<array{0:string,1:string,2:string}> $rows */
    private function interPension(array $rows, int $lote): int
    {
        return $this->add(FX::interbancaria($rows, true), 'xls', 'TESORERIA_PENSION', $lote);
    }

    /** @param list<array<string,mixed>> $people */
    private function tab(array $people, int $lote, bool $hiddenRows = false): int
    {
        return $this->add(FX::tabulado($people, $hiddenRows), 'xlsx', 'RRHH_TABULADO', $lote);
    }

    private function rowCount(string $sql, array $params = []): int
    {
        return (int) $this->one($sql, $params)['n'];
    }

    /** @return array<string,mixed> */
    private static function decoded(?string $json): array
    {
        return (array) json_decode((string) $json, true);
    }

    public function testBeneficiaryCreatedByStrongEvidenceOnly(): void
    {
        $lote = $this->lote(1, 1);
        $this->tab([FX::person(1), FX::person(2)], $lote);
        $aid = $this->pensionSant([['90001', 'BENEF FICTICIA UNO', '60011111111', '300.00']], $lote);
        $r = $this->regs($aid, 'registros_tesoreria')[0];
        $b = $this->one('SELECT * FROM beneficiarios_pension');
        $rel = $this->one('SELECT * FROM beneficiario_trabajadores');
        self::assertSame(['EVIDENCIA_FUERTE', 'BENEF FICTICIA UNO', $b['id']], [$b['origen'], $b['nombre_normalizado'], $r['beneficiario_id']]);
        self::assertSame([$this->empId('90001'), 'NUMERO_EMPLEADO_ARCHIVO', 'ACTIVA'], [$rel['empleado_id'], $rel['origen'], $rel['estado']]);
        $evid = self::decoded($rel['evidencia']);
        self::assertSame($r['id'], $evid['registro_id']);
        self::assertSame($lote, $evid['lote_id']);
        self::assertSame($b['id'], $this->one("SELECT beneficiario_id FROM cuentas_bancarias WHERE uso = 'PENSION_BENEFICIARIO'")['beneficiario_id']);
        $aid2 = $this->pensionSant([['90001', 'BENEF FICTICIA UNO', '60011111111', '300.00']], $this->lote(1, 2));
        self::assertSame($b['id'], $this->regs($aid2, 'registros_tesoreria')[0]['beneficiario_id']);
        $this->interPension([[self::CLABE_A, 'PERSONA SIN TRABAJADOR', '150.00']], $lote);
        self::assertSame(1, $this->rowCount('SELECT count(*) AS n FROM beneficiarios_pension'));
    }

    public function testManualConfirmationLearnsAndClabeIsReused(): void
    {
        $lote = $this->lote(1, 1);
        $this->tab([FX::person(1), FX::person(2)], $lote);
        $i1 = $this->interPension([[self::CLABE_A, 'BENEF INTER DOS', '400.00']], $lote);
        $r = $this->regs($i1, 'registros_tesoreria')[0];
        self::assertSame(['NO_IDENTIFICADO', null], [$r['asociacion_estado'], $r['empleado_id']], 'Primera vez: nunca definitivo');
        $ben = $this->confirmar($r['id'], $this->empId('90002'));
        $b = $this->one('SELECT * FROM beneficiarios_pension WHERE id = :b', ['b' => $ben]);
        $rel = $this->one('SELECT * FROM beneficiario_trabajadores WHERE beneficiario_id = :b', ['b' => $ben]);
        $cta = $this->one('SELECT * FROM cuentas_bancarias WHERE beneficiario_id = :b', ['b' => $ben]);
        self::assertSame(['CONFIRMACION_MANUAL', $this->user, 'CONFIRMACION_MANUAL', $this->user],
            [$b['origen'], $b['confirmado_por'], $rel['origen'], $rel['confirmado_por']]);
        $evid = self::decoded($rel['evidencia']);
        self::assertSame([$i1, $lote], [$evid['archivo_id'], $evid['lote_id']]);
        self::assertSame(['CONFIRMACION_MANUAL', 'PENSION_BENEFICIARIO', substr(self::CLABE_A, -4)], [$cta['origen'], $cta['uso'], $cta['cuenta_ultimos4']]);
        self::assertStringNotContainsString(self::CLABE_A, Json::encode($this->all("SELECT * FROM auditoria WHERE contexto::text LIKE '%cuenta%'")),
            'CLABE completa jamás en auditoría');
        $h = $this->one("SELECT accion, usuario_id FROM asociaciones_historial WHERE accion = 'CONFIRMAR_BENEFICIARIO'");
        self::assertSame(['accion' => 'CONFIRMAR_BENEFICIARIO', 'usuario_id' => $this->user], $h);
        $i2 = $this->interPension([[self::CLABE_A, 'BENEF INTER DOS', '400.00']], $this->lote(1, 2));
        $r2 = $this->regs($i2, 'registros_tesoreria')[0];
        self::assertSame(['ASOCIADO_AUTOMATICO', 'CUENTA_CONFIRMADA', $this->empId('90002'), $ben],
            [$r2['asociacion_estado'], $r2['asociacion_metodo'], $r2['empleado_id'], $r2['beneficiario_id']]);
    }

    public function testNewAccountKeepsHistory(): void
    {
        $lote = $this->lote(1, 1);
        $this->tab([FX::person(1)], $lote);
        $this->pensionSant([['90001', 'BENEF FICTICIA UNO', '60011111111', '300.00']], $lote);
        $this->pensionSant([['90001', 'BENEF FICTICIA UNO', '60099999999', '300.00']], $this->lote(1, 2));
        $ben = $this->one('SELECT id FROM beneficiarios_pension')['id'];
        $ctas = $this->all('SELECT cuenta_ultimos4, estado FROM cuentas_bancarias WHERE beneficiario_id = :b ORDER BY id', ['b' => $ben]);
        self::assertSame([['1111', 'ACTIVA'], ['9999', 'ACTIVA']], array_map(static fn ($c) => [$c['cuenta_ultimos4'], $c['estado']], $ctas),
            'La cuenta anterior no se sobrescribe');
        self::assertSame(1, $this->rowCount('SELECT count(*) AS n FROM beneficiarios_pension'));
    }

    public function testAmbiguousBeneficiaryAndUniqueAmount(): void
    {
        $lote = $this->lote(1, 1);
        $this->tab([FX::person(1), FX::person(2, ['pension' => '450.00']), FX::person(3)], $lote);
        $this->pensionSant([['90001', 'MISMO NOMBRE BENEF', '60011111111', '100.00'], ['90003', 'MISMO NOMBRE BENEF', '60033333333', '100.00']], $lote);
        self::assertSame(2, $this->rowCount('SELECT count(*) AS n FROM beneficiarios_pension'));
        $i = $this->interPension([[self::CLABE_A, 'MISMO NOMBRE BENEF', '123.00'], [self::CLABE_B, 'OTRA PERSONA FICTICIA', '450.00']], $lote);
        $regs = [];
        foreach ($this->regs($i, 'registros_tesoreria') as $r) {
            $regs[$r['nombre_normalizado']] = $r;
        }
        $amb = $regs['MISMO NOMBRE BENEF'];
        self::assertSame('NO_IDENTIFICADO', $amb['asociacion_estado'], 'Dos beneficiarios con ese nombre: el nombre no distingue a nadie');
        $uni = $regs['OTRA PERSONA FICTICIA'];
        self::assertSame(['SUGERENCIA', 'BAJA', 'IMPORTE_RETENCION_UNICA', [$this->empId('90002')]],
            [$uni['asociacion_estado'], $uni['asociacion_confianza'], $uni['asociacion_evidencia']['motivo'], $uni['asociacion_evidencia']['candidatos']],
            'Importe = retención de UN trabajador del lote');
        self::assertNull($uni['empleado_id']);
    }

    public function testAmbiguousWorkerByRepeatedAmount(): void
    {
        $lote = $this->lote(1, 1);
        $this->tab([FX::person(1, ['pension' => '200.00']), FX::person(2, ['pension' => '200.00'])], $lote);
        $i = $this->interPension([[self::CLABE_A, 'BENEF X', '200.00']], $lote);
        $r = $this->regs($i, 'registros_tesoreria')[0];
        self::assertSame(['NO_IDENTIFICADO', []], [$r['asociacion_estado'], $r['asociacion_evidencia']['candidatos'] ?? []],
            'Dos trabajadores retienen 200: el importe no identifica a nadie');
    }

    public function testIncompatibleClabeAndRules(): void
    {
        $lote = $this->lote(1, 1);
        $this->tab([FX::person(1), FX::person(2)], $lote);
        $i1 = $this->interPension([[self::CLABE_A, 'BENEF INTER', '400.00']], $lote);
        $r = $this->regs($i1, 'registros_tesoreria')[0];
        $this->confirmar($r['id'], $this->empId('90001'));
        $i2 = $this->interPension([[self::CLABE_A, 'BENEF INTER', '400.00']], $this->lote(1, 2));
        $r2 = $this->regs($i2, 'registros_tesoreria')[0];
        $emp2 = $this->empId('90002');
        $user = $this->user;
        self::assertSqlState('23505', function () use ($r2, $emp2, $user): void {
            $this->web()->transaction(static function (Database $w) use ($r2, $emp2, $user): void {
                self::manualAssociation($w, $emp2, $user, $r2['id']);
                $w->fetchValue("SELECT fn_confirmar_beneficiario(:r, :u, NULL, 'Intento incompatible')", ['r' => $r2['id'], 'u' => $user]);
            });
        });
        self::assertSqlState('23514', fn () => $this->web()->fetchValue("SELECT fn_confirmar_beneficiario(:r, :u, NULL, 'Sin asociar')",
            ['r' => $r2['id'], 'u' => $user]));
        $other = $this->createUser('eng.otra', 'Otra');
        self::assertSqlState('42501', fn () => $this->web()->fetchValue("SELECT fn_confirmar_beneficiario(:r, :u, NULL, 'Suplantando')",
            ['r' => $r2['id'], 'u' => $other]));
        $rel = $this->one('SELECT id FROM beneficiario_trabajadores')['id'];
        $w = $this->web();
        self::assertSqlState('23514', static fn () => $w->execute("UPDATE beneficiario_trabajadores SET estado = 'INACTIVA' WHERE id = :id", ['id' => $rel]));
        $w->execute("UPDATE beneficiario_trabajadores SET estado = 'INACTIVA', desactivado_por = :u, desactivado_at = '2001-01-01',
                            motivo_desactivacion = 'Relación confirmada por error' WHERE id = :id", ['u' => $user, 'id' => $rel]);
        self::assertDbError(static fn () => $w->execute('DELETE FROM beneficiario_trabajadores WHERE id = :id', ['id' => $rel]));
        self::assertSqlState('23514', static fn () => $w->execute("UPDATE beneficiario_trabajadores SET estado = 'ACTIVA' WHERE id = :id", ['id' => $rel]));
        $at = (string) $this->one('SELECT desactivado_at FROM beneficiario_trabajadores WHERE id = :id', ['id' => $rel])['desactivado_at'];
        self::assertGreaterThan(2001, (int) substr($at, 0, 4));
    }

    public function testUniqueShortfallForWorkerWithTwoBeneficiaries(): void
    {
        $lote = $this->lote(1, 1);
        $this->tab([FX::person(1, ['pension' => '500.00']), FX::person(2)], $lote);
        $this->pensionSant([['90001', 'BENEF UNO SANT', '60011111111', '300.00']], $lote);
        $i = $this->interPension([[self::CLABE_A, 'OTRO BENEF INTER', '200.00']], $lote);
        $r = $this->regs($i, 'registros_tesoreria')[0];
        self::assertSame(['SUGERENCIA', 'IMPORTE_RETENCION_UNICA', [$this->empId('90001')], null],
            [$r['asociacion_estado'], $r['asociacion_evidencia']['motivo'], $r['asociacion_evidencia']['candidatos'], $r['empleado_id']]);
    }

    public function testRetentionIsNotDuplicatedByDerivedOrRepeatedTabulados(): void
    {
        $lote = $this->lote(1, 1);
        $ps = [FX::person(1, ['pension' => '300.00']), FX::person(2)];
        $this->tab($ps, $lote);
        $this->tab($ps, $lote, true);
        $this->pensionSant([['90001', 'BENEF UNO SANT', '60011111111', '300.00']], $lote);
        $i = $this->interPension([[self::CLABE_A, 'OTRA BENEF', '300.00']], $lote);
        $r = $this->regs($i, 'registros_tesoreria')[0];
        self::assertSame('NO_IDENTIFICADO', $r['asociacion_estado'], 'Sin faltante real no hay sugerencia');
    }

    public function testReusingABeneficiaryFromAnotherPeriodAndForeignBulkProvenance(): void
    {
        [$l1, $l2] = [$this->lote(1, 1), $this->lote(1, 2)];
        $this->tab([FX::person(1), FX::person(2)], $l1);
        $r1 = $this->regs($this->interPension([[self::CLABE_A, 'BENEF REUSO', '400.00']], $l1), 'registros_tesoreria')[0];
        $ben = $this->confirmar($r1['id'], $this->empId('90002'));
        $r2 = $this->regs($this->interPension([[self::CLABE_B, 'BENEF REUSO', '400.00']], $l2), 'registros_tesoreria')[0];
        self::assertSame($ben, $this->confirmar($r2['id'], $this->empId('90002'), $ben));
        self::assertSame($r2['periodo_id'], $this->one('SELECT ultimo_periodo_id FROM beneficiarios_pension WHERE id = :b', ['b' => $ben])['ultimo_periodo_id']);
        self::assertSame(2, $this->rowCount('SELECT count(*) AS n FROM cuentas_bancarias WHERE beneficiario_id = :b', ['b' => $ben]), 'Dos cuentas, historial');
        $otro = $this->createUser('eng.ajeno', 'Ajeno');
        $am = (int) self::owner()->fetchValue(
            "INSERT INTO acciones_masivas (accion, usuario_id, motivo, registros, huella)
             VALUES ('CONFIRMAR_PROPUESTA', :u, 'Acción ajena de prueba', 1, repeat('a', 64)) RETURNING id", ['u' => $otro]);
        $r3 = $this->regs($this->interPension([['002180000000033333', 'OTRA BENEF', '10.00']], $l1), 'registros_tesoreria')[0];
        $emp1 = $this->empId('90001');
        $user = $this->user;
        self::assertSqlState('42501', function () use ($r3, $emp1, $user, $am): void {
            $this->web()->transaction(static function (Database $w) use ($r3, $emp1, $user, $am): void {
                self::manualAssociation($w, $emp1, $user, $r3['id']);
                $w->fetchValue("SELECT fn_confirmar_beneficiario(:r, :u, NULL, 'Procedencia falsa', :am)", ['r' => $r3['id'], 'u' => $user, 'am' => $am]);
            });
        });
        self::assertSqlState('42501', fn () => $this->web()->execute(
            "INSERT INTO asociaciones_historial (registro_tipo, registro_id, accion, estado_nuevo, usuario_id, motivo, accion_masiva_id)
             VALUES ('TESORERIA', :r, 'ASOCIAR', 'ASOCIADO_MANUAL', :u, 'Historial con acción ajena', :am)", ['r' => $r3['id'], 'u' => $user, 'am' => $am]));
    }

    public function testDirectWebEditsOfABeneficiaryAreAttributedToTheSession(): void
    {
        $lote = $this->lote(1, 1);
        $this->tab([FX::person(1), FX::person(2)], $lote);
        $r = $this->regs($this->interPension([[self::CLABE_A, 'BENEF ED', '400.00']], $lote), 'registros_tesoreria')[0];
        $ben = $this->confirmar($r['id'], $this->empId('90002'));
        $other = $this->createUser('eng.edita', 'Edita');
        self::owner()->execute("INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = 'ADMIN' ON CONFLICT DO NOTHING",
            ['u' => $other]);
        $this->web()->execute("UPDATE beneficiarios_pension SET observaciones = 'Nota del primer usuario', actualizado_por = :u WHERE id = :b",
            ['u' => $this->user, 'b' => $ben]);
        $w = $this->web($other);
        $w->execute("UPDATE beneficiarios_pension SET observaciones = 'Cambio sin declarar autor' WHERE id = :b", ['b' => $ben]);
        self::assertDbError(static fn () => $w->execute("UPDATE beneficiarios_pension SET estado = 'INACTIVO' WHERE id = :b", ['b' => $ben]));
        self::assertSame($other, $this->one('SELECT actualizado_por FROM beneficiarios_pension WHERE id = :b', ['b' => $ben])['actualizado_por'],
            'La autoría es de la sesión');
    }
}
