<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Engine;

use ConciliaRH\Core\Database;
use ConciliaRH\Engine\Identity\Duplicates;
use ConciliaRH\Engine\Worker\WorkerSettings;
use ConciliaRH\Tests\Fixtures\IdentityFixtures as FX;
use ConciliaRH\Tests\Support\DatabaseTestCase;
use ConciliaRH\Tests\Support\EngineTestCase;
use PDOException;

final class Phase7Test extends EngineTestCase
{
    private const CLABE_A = '002180000000011111';
    private const CLABE_B = '002180000000022222';
    private const H2 = 'h2prueba';

    private int $user;
    private WorkerSettings $cfg;
    /** @var array<string,int> */
    private array $periodos = [];

    private static function secretH2(): string
    {
        return hash('sha256', 'secreto-hmac-ficticio-de-prueba-h2');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        $o = self::owner();
        $this->user = (int) $o->fetchValue(
            "INSERT INTO usuarios (username, nombre, password_hash) VALUES ('eng.identidad', 'Tester Identidad', repeat('x', 30))
             ON CONFLICT (username) DO UPDATE SET nombre = EXCLUDED.nombre RETURNING id");
        $o->execute("INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = 'ADMIN' ON CONFLICT DO NOTHING", ['u' => $this->user]);
        $this->cfg = self::settings();
        $this->periodos = [];
    }

    protected function tearDown(): void
    {
        if (CONCILIARH_DB_AVAILABLE) {
            $this->clean();
        }
        parent::tearDown();
    }

    private function clean(): void
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
                 VALUES (2042, :m, :q, CAST(:i AS date), CAST(:f AS date), 'PERIODO IDENTIDAD', :u) RETURNING id",
                ['m' => $mes, 'q' => $q, 'i' => $ini, 'f' => $fin, 'u' => $this->user]);
        }
        return (int) self::owner()->fetchValue("SELECT id FROM fn_crear_lote(CAST(:p AS bigint), '', '', '', '', CAST(:u AS bigint))",
            ['p' => $this->periodos[$key], 'u' => $this->user]);
    }

    private function add(string $data, string $ext, string $tipo, ?int $lote = null, bool $drain = true): int
    {
        $o = self::owner();
        $lote ??= $this->lote();
        $uid = sprintf('%s-%s-4%s-a%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1),
            substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6)));
        $per = (int) $o->fetchValue('SELECT periodo_id FROM lotes_importacion WHERE id = :l', ['l' => $lote]);
        $rel = "imports/$per/$lote/$uid/original.$ext";
        $dst = $this->cfg->storagePath . '/' . $rel;
        if (!is_dir(dirname($dst))) {
            mkdir(dirname($dst), 0775, true);
        }
        file_put_contents($dst, $data);
        chmod($dst, 0440);
        $tid = (int) $o->fetchValue('SELECT id FROM tipos_documento WHERE codigo = :c', ['c' => $tipo]);
        $oid = (int) $o->fetchValue("SELECT id FROM origenes_administrativos WHERE codigo = 'SIN_DEFINIR'");
        $aid = (int) $o->fetchValue(
            "INSERT INTO archivos_importados (uuid, lote_id, tipo_documento_id, origen_administrativo_id, nombre_original, extension,
                 mime_detectado, firma, tamano_bytes, sha256, ruta_relativa, usuario_carga_id, tiene_macros)
             VALUES (CAST(:uuid AS uuid), :l, :t, :o, :n, :e, 'application/octet-stream', :f, :s, :h, :r, :u, false) RETURNING id",
            ['uuid' => $uid, 'l' => $lote, 't' => $tid, 'o' => $oid, 'n' => "ficticio.$ext", 'e' => $ext, 'f' => $ext === 'xls' ? 'OLE2' : 'OOXML',
             's' => strlen($data), 'h' => hash('sha256', $data), 'r' => $rel, 'u' => $this->user]);
        $job = (int) $o->fetchValue("INSERT INTO procesos (tipo, parametros, max_intentos) VALUES ('VERIFICAR_ARCHIVO', CAST(:p AS jsonb), 2) RETURNING id",
            ['p' => json_encode(['archivo_id' => $aid])]);
        $o->execute('UPDATE archivos_importados SET proceso_id = :j WHERE id = :a', ['j' => $job, 'a' => $aid]);
        if ($drain) {
            $this->drain();
        }
        return $aid;
    }

    private function drain(int $n = 30): void
    {
        $o = self::owner();
        $w = self::worker($this->cfg, 'phpunit-identidad');
        for ($i = 0; $i < $n; $i++) {
            if ($o->fetchOne("SELECT 1 FROM procesos WHERE estado = 'PENDIENTE' AND intentos < max_intentos") === null) {
                break;
            }
            $w->run(true);
        }
        $bad = $o->fetchAll("SELECT tipo, error_codigo, error_detalle FROM procesos WHERE estado = 'ERROR'");
        self::assertSame([], $bad, json_encode($bad, JSON_UNESCAPED_UNICODE) ?: '');
    }

    /** @param array<string,mixed> $p @return array<string,mixed>|null */
    private function one(string $sql, array $p = []): ?array
    {
        return self::owner()->fetchOne($sql, $p);
    }

    /** @param array<string,mixed> $p @return list<array<string,mixed>> */
    private function all(string $sql, array $p = []): array
    {
        return self::owner()->fetchAll($sql, $p);
    }

    /** @return list<array<string,mixed>> */
    private function regs(int $aid, string $table = 'registros_rrhh'): array
    {
        return $this->all("SELECT r.* FROM $table r JOIN importaciones_normalizadas n ON n.id = r.importacion_id
                           WHERE r.archivo_id = :a AND n.estado = 'VIGENTE' ORDER BY r.fila_origen", ['a' => $aid]);
    }

    /** @return array<string,mixed>|null */
    private function emp(string $numero): ?array
    {
        return $this->one('SELECT * FROM empleados WHERE numero_empleado = :n', ['n' => $numero]);
    }

    private function empId(string $numero): int
    {
        return (int) ($this->emp($numero)['id'] ?? 0);
    }

    private function web(?int $asUser = null): Database
    {
        return self::webAs($asUser ?? $this->user);
    }

    private static function webAs(int $user, ?array $cfg = null): Database
    {
        $w = new Database($cfg ?? DatabaseTestCase::dbConfig('DB_USERNAME', 'DB_PASSWORD', 'conciliarh_web'));
        self::bindActor($w, $user);
        return $w;
    }

    /** @return array<mixed> */
    private static function j(mixed $v): array
    {
        return is_string($v) ? (array) json_decode($v, true, 512, JSON_THROW_ON_ERROR) : (array) $v;
    }

    private function settingsWithRing(array $ring): WorkerSettings
    {
        return self::settings(['hmacRing' => $ring]);
    }

    private function h1(): string
    {
        return self::settings()->hmacRing['h1'];
    }

    private function setActiveKey(string $keyId): void
    {
        $o = self::owner();
        $o->execute("SET conciliarh.identidad_mantenimiento = 'on'");
        $o->execute('UPDATE hmac_llaves_estado SET key_id_activa = :k, kcv_activa = NULL', ['k' => $keyId]);
        $o->execute('RESET conciliarh.identidad_mantenimiento');
    }

    private function expectDbError(?string $sqlstate, ?string $match, callable $fn): void
    {
        try {
            $fn();
        } catch (PDOException $e) {
            if ($sqlstate !== null) {
                self::assertSame($sqlstate, (string) ($e->errorInfo[0] ?? $e->getCode()), $e->getMessage());
            }
            if ($match !== null) {
                self::assertStringContainsString($match, $e->getMessage());
            }
            return;
        }
        self::fail('Se esperaba un error de la base de datos' . ($sqlstate !== null ? " ($sqlstate)" : ''));
    }

    public static function adminUser(Database $owner, string $username = 'eng.admin', string $rol = 'ADMIN'): int
    {
        $uid = (int) $owner->fetchValue(
            "INSERT INTO usuarios (username, nombre, password_hash) VALUES (:u, 'Administrador de prueba', repeat('x', 30))
             ON CONFLICT (username) DO UPDATE SET nombre = EXCLUDED.nombre RETURNING id", ['u' => $username]);
        $owner->execute('INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = :r ON CONFLICT DO NOTHING', ['u' => $uid, 'r' => $rol]);
        return $uid;
    }

    private function admin(string $username = 'eng.admin', string $rol = 'ADMIN'): int
    {
        return self::adminUser(self::owner(), $username, $rol);
    }

    private static function pgTextArray(array $items): string
    {
        return '{' . implode(',', array_map(static fn (string $s): string => '"' . addcslashes($s, '"\\') . '"', $items)) . '}';
    }

    /** @param array<string,mixed>|null $webCfg */
    public static function mergeEmployees(Database $owner, int $origen, int $destino, int $user, array $aceptados = [],
                                          string $motivo = 'Misma persona según expediente ficticio', ?array $webCfg = null): int
    {
        $w = self::webAs($user, $webCfg);
        $an = (string) $w->fetchValue('SELECT fn_fusion_analisis(CAST(:o AS bigint), CAST(:d AS bigint))', ['o' => $origen, 'd' => $destino]);
        $huella = (string) $w->fetchValue('SELECT fn_fusion_huella(CAST(:a AS jsonb))', ['a' => $an]);
        $grave = false;
        foreach ((array) (self::j($an)['conflictos'] ?? []) as $c) {
            $grave = $grave || ($c['severidad'] ?? null) === 'GRAVE';
        }
        $args = ['o' => $origen, 'd' => $destino, 'u' => $user, 'm' => $motivo, 'ac' => self::pgTextArray($aceptados), 'h' => $huella];
        if (!$grave) {
            return (int) $w->fetchValue('SELECT fn_fusionar_empleados(CAST(:o AS bigint), CAST(:d AS bigint), CAST(:u AS bigint), CAST(:m AS text),
                                         CAST(:ac AS text[]), CAST(:h AS text))', $args);
        }
        $sid = (int) $w->fetchValue('SELECT fn_solicitar_fusion_critica(CAST(:o AS bigint), CAST(:d AS bigint), CAST(:u AS bigint), CAST(:m AS text),
                                     CAST(:ac AS text[]), CAST(:h AS text))', $args);
        unset($w);
        $aprobador = self::adminUser($owner, 'eng.aprobador', 'ADMIN');
        $w = self::webAs($aprobador, $webCfg);
        $fid = $w->fetchValue("SELECT fn_aprobar_solicitud_fusion(CAST(:s AS bigint), CAST(:u AS bigint), 'Segunda firma de prueba ficticia')",
            ['s' => $sid, 'u' => $aprobador]);
        if ($fid === null) {
            throw new \RuntimeException('La solicitud expiró o su análisis cambió');
        }
        return (int) $fid;
    }

    private function fusionar(int $origen, int $destino, int $user, array $aceptados = []): int
    {
        return self::mergeEmployees(self::owner(), $origen, $destino, $user, $aceptados);
    }

    private function interPension(array $rows, int $lote): int
    {
        return $this->add(FX::interbancaria($rows, true), 'xls', 'TESORERIA_PENSION', $lote);
    }

    /** @return array<string,mixed> */
    private function conciliar(int $lote, bool $drain = true): array
    {
        $w = $this->web();
        $c = $w->fetchOne("SELECT * FROM fn_solicitar_conciliacion(CAST(:l AS bigint), CAST(:u AS bigint), 'Prueba de conciliación')",
            ['l' => $lote, 'u' => $this->user]) ?? [];
        unset($w);
        $job = $this->one('SELECT * FROM procesos WHERE id = :id', ['id' => $c['proceso_id']]) ?? [];
        self::assertSame(['CONCILIAR_LOTE', ['conciliacion_id' => $c['id']], $this->user], [$job['tipo'], self::j($job['parametros']), $job['creado_por']],
            'La BD encola el trabajo en la misma transacción que la solicitud');
        if ($drain) {
            $this->drain();
        }
        return $this->one('SELECT * FROM conciliaciones WHERE id = :id', ['id' => $c['id']]) ?? [];
    }

    /** @return array<string,array<string,mixed>> */
    private function resultados(int $cid): array
    {
        $out = [];
        foreach ($this->all('SELECT * FROM conciliacion_empleados WHERE conciliacion_id = :c', ['c' => $cid]) as $r) {
            $out[(string) $r['numero_empleado']] = $r;
        }
        return $out;
    }

    private static function neto(array $p): string
    {
        return bcsub(bcsub((string) $p['sueldo'], (string) $p['isr'], 2), (string) $p['pension'], 2);
    }

    /** @return array{0:string,1:string,2:string,3:string} */
    private static function pago(array $p): array
    {
        return [$p['num'], $p['nombre'], sprintf('600%08d', (int) $p['num']), self::neto($p)];
    }

    private function loteCompleto(int $lote, array $people, array $pagosSant, ?string $pensionFile = null): void
    {
        $this->add(FX::tabulado($people), 'xlsx', 'RRHH_TABULADO', $lote);
        $neto = '0';
        $ded = '0';
        foreach ($people as $p) {
            $neto = bcadd($neto, self::neto($p), 2);
            $ded = bcadd($ded, bcadd((string) $p['isr'], (string) $p['pension'], 2), 2);
        }
        $this->add(FX::polizaGlobal([['1', 'SUELDO BASE', (float) bcadd($neto, $ded, 2)]], [['70', 'ISR', (float) $ded]]), 'xlsx', 'RRHH_POLIZA_GLOBAL', $lote);
        $rows = [];
        $tot = '0';
        foreach ($pagosSant as [$n, $nombre, $cta, $imp]) {
            $rows[] = [$n, ...explode(' ', $nombre), $cta, $imp];
            $tot = bcadd($tot, $imp, 2);
        }
        $this->add(FX::santander($rows), 'xlsx', 'TESORERIA_DISPERSION', $lote);
        $hojas = [['SANTANDER $' . number_format((float) $tot, 2), $tot]];
        if ($pensionFile !== null) {
            $this->add($pensionFile, 'xlsx', 'TESORERIA_PENSION', $lote);
        }
        $this->add(FX::polizaEgresoBancos($hojas), 'xlsx', 'POLIZA_EGRESO_BANCARIA', $lote);
    }

    private function asociarManual(Database $w, int $regId, int $empId): void
    {
        $w->execute("UPDATE registros_tesoreria SET empleado_id = :e, asociacion_estado = 'ASOCIADO_MANUAL', asociacion_metodo = 'MANUAL',
                            asociacion_confianza = 'MANUAL', asociado_por = :u, asociado_at = now() WHERE id = :id",
            ['e' => $empId, 'u' => $this->user, 'id' => $regId]);
    }

    private function updateEmployee(string $column, ?string $value, int $id): void
    {
        self::owner()->execute("UPDATE empleados SET $column = :v WHERE id = :id", ['v' => $value, 'id' => $id]);
    }

    private function ptr(int $id): ?int
    {
        $v = $this->one('SELECT fusionado_en_id FROM empleados WHERE id = :id', ['id' => $id])['fusionado_en_id'] ?? null;
        return $v === null ? null : (int) $v;
    }

    public function testWorkerUsesTheDbActiveHmacKeyAndRetriesWithoutIt(): void
    {
        $lote = $this->lote(1, 1);
        $this->setActiveKey(self::H2);
        $aid = $this->add(FX::interbancaria([[self::CLABE_A, 'PERSONA FICTICIA', '10.00']]), 'xls', 'TESORERIA_DISPERSION', $lote, false);
        $w = self::worker($this->cfg, 'phpunit-hmac');
        for ($i = 0; $i < 4; $i++) {
            $w->run(true);
        }
        $job = $this->one("SELECT * FROM procesos WHERE tipo = 'ANALIZAR_ARCHIVO' ORDER BY id DESC LIMIT 1") ?? [];
        self::assertSame(['PENDIENTE', 'LLAVE_HMAC_NO_DISPONIBLE'], [$job['estado'] ?? null, $job['error_codigo'] ?? null], 'Sin la llave activa: reintento, nunca otra llave');
        self::assertSame(0, (int) $this->one('SELECT count(*) AS n FROM staging_registros WHERE archivo_id = :a', ['a' => $aid])['n']);
        $this->cfg = $this->settingsWithRing(['h1' => $this->h1(), self::H2 => self::secretH2()]);
        self::owner()->execute('UPDATE procesos SET disponible_desde = now() WHERE id = :id', ['id' => $job['id']]);
        $this->drain();
        $st = $this->one('SELECT cuenta_hmac, cuenta_hmac_key_id FROM staging_registros WHERE archivo_id = :a AND cuenta_hmac IS NOT NULL', ['a' => $aid]) ?? [];
        self::assertSame(self::H2, $st['cuenta_hmac_key_id'] ?? null);
        self::assertSame(hash_hmac('sha256', 'cuenta:' . self::CLABE_A, self::secretH2()), $st['cuenta_hmac']);
        self::assertSame(self::H2, $this->one('SELECT hash_key_id FROM analisis_archivo WHERE archivo_id = :a AND vigente', ['a' => $aid])['hash_key_id']);
        $reg = $this->regs($aid, 'registros_tesoreria')[0];
        self::assertSame([$st['cuenta_hmac'], self::H2], [$reg['cuenta_hmac'], $reg['cuenta_hmac_key_id']]);
        $ids = $this->one("SELECT array_to_json(hmac_key_ids) AS k FROM workers WHERE id = 'phpunit-identidad'")['k'];
        self::assertSame(['h1', self::H2], self::j($ids), 'Sólo ids, nunca secretos');
    }

    public function testManualDecisionSurvivesReanalysisAfterAKeyChange(): void
    {
        $lote = $this->lote(1, 1);
        $this->add(FX::tabulado([FX::person(1)]), 'xlsx', 'RRHH_TABULADO', $lote);
        $p = FX::person(1);
        $aid = $this->add(FX::interbancaria([[self::CLABE_A, $p['nombre'], '100.00']]), 'xls', 'TESORERIA_DISPERSION', $lote);
        $r = $this->regs($aid, 'registros_tesoreria')[0];
        $this->asociarManual($this->web(), (int) $r['id'], $this->empId('90001'));
        $this->cfg = $this->settingsWithRing(['h1' => $this->h1(), self::H2 => self::secretH2()]);
        $this->setActiveKey(self::H2);
        $job = (int) self::owner()->fetchValue("INSERT INTO procesos (tipo, parametros, max_intentos) VALUES ('ANALIZAR_ARCHIVO', CAST(:p AS jsonb), 2) RETURNING id",
            ['p' => json_encode(['archivo_id' => $aid])]);
        self::owner()->execute('UPDATE archivos_importados SET proceso_id = :j WHERE id = :a', ['j' => $job, 'a' => $aid]);
        $this->drain();
        $nuevo = $this->regs($aid, 'registros_tesoreria')[0];
        self::assertTrue($nuevo['id'] !== $r['id'] && $nuevo['hash_registro'] !== $r['hash_registro'], 'Otra llave, otra huella de registro');
        self::assertSame(['ASOCIADO_MANUAL', $this->empId('90001'), $this->user], [$nuevo['asociacion_estado'], $nuevo['empleado_id'], $nuevo['asociado_por']]);
    }

    /** @return array{0:int,1:int} */
    private function duplicadosPorEdicion(): array
    {
        $l1 = $this->lote(1, 1);
        $this->add(FX::tabulado([FX::person(2, ['rfc' => null, 'curp' => null])]), 'xlsx', 'RRHH_TABULADO', $l1);
        $this->add(FX::tabulado([FX::person(52, ['nombre' => FX::person(2)['nombre'], 'rfc' => FX::rfc(2), 'curp' => FX::curp(2)])]), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 2));
        $a = $this->empId('90002');
        $b = $this->empId('90052');
        $this->updateEmployee('rfc', FX::rfc(2), $a);
        return [$a, $b];
    }

    public function testDuplicateDetectionIsExplainableAndNameAloneNeverMerges(): void
    {
        [$a, $b] = $this->duplicadosPorEdicion();
        $l3 = $this->lote(2, 1);
        $this->add(FX::tabulado([FX::person(7), FX::person(8, ['nombre' => FX::person(7)['nombre']])]), 'xlsx', 'RRHH_TABULADO', $l3);
        $res = Duplicates::detect(self::owner());
        $pares = [];
        foreach ($this->all('SELECT * FROM duplicados_empleados') as $d) {
            $pares[$d['empleado_a'] . ':' . $d['empleado_b']] = $d;
        }
        $fuerte = $pares[min($a, $b) . ':' . max($a, $b)];
        $codigos = array_column(self::j($fuerte['evidencias']), 'codigo');
        self::assertSame('ALTA', $fuerte['confianza']);
        self::assertContains('MISMO_RFC', $codigos);
        self::assertContains('MISMO_NOMBRE', $codigos);
        $e7 = $this->empId('90007');
        $e8 = $this->empId('90008');
        $nombre = $pares["$e7:$e8"];
        self::assertSame(['BAJA', ['MISMO_NOMBRE']], [$nombre['confianza'], array_column(self::j($nombre['evidencias']), 'codigo')]);
        self::assertSame(2, $res['pares']);
        $adm = $this->admin();
        $this->expectDbError('23514', 'EVIDENCIA_INSUFICIENTE', fn () => $this->fusionar($e8, $e7, $adm));
        $this->add(FX::tabulado([FX::person(9, ['rfc' => null, 'curp' => null]), FX::person(59, ['rfc' => FX::rfc(59)])]), 'xlsx', 'RRHH_TABULADO', $this->lote(2, 2));
        $this->add(FX::tabulado([FX::person(9, ['rfc' => FX::rfc(59), 'curp' => null])]), 'xlsx', 'RRHH_TABULADO', $this->lote(3, 1));
        Duplicates::detect(self::owner());
        $e9 = $this->empId('90009');
        $e59 = $this->empId('90059');
        $media = $this->one('SELECT * FROM duplicados_empleados WHERE empleado_a = :a AND empleado_b = :b', ['a' => min($e9, $e59), 'b' => max($e9, $e59)]) ?? [];
        self::assertSame('MEDIA', $media['confianza'] ?? null);
        self::assertContains('REGISTRO_CON_IDENTIFICADORES_DE_AMBOS', array_column(self::j($media['evidencias']), 'codigo'));
        $w = $this->web();
        $w->execute("UPDATE duplicados_empleados SET estado = 'DESCARTADO', motivo = 'Revisado: son personas distintas' WHERE id = :id", ['id' => $nombre['id']]);
        $this->expectDbError('42501', null, fn () => $w->execute("UPDATE duplicados_empleados SET confianza = 'ALTA' WHERE id = :id", ['id' => $media['id']]));
        unset($w);
        Duplicates::detect(self::owner());
        $d = $this->one('SELECT estado, decidido_por, confianza_al_decidir FROM duplicados_empleados WHERE id = :id', ['id' => $nombre['id']]) ?? [];
        self::assertSame(['DESCARTADO', $this->user, 'BAJA'], [$d['estado'], $d['decidido_por'], $d['confianza_al_decidir']]);
        $this->updateEmployee('rfc', FX::rfc(7), $e8);
        Duplicates::detect(self::owner());
        self::assertSame(['estado' => 'PENDIENTE', 'confianza' => 'ALTA'], $this->one('SELECT estado, confianza FROM duplicados_empleados WHERE id = :id', ['id' => $nombre['id']]));
    }

    public function testMergeIsNonDestructiveHistoryIntactAndNewRunUsesCanonical(): void
    {
        $p2 = FX::person(2);
        $this->add(FX::tabulado([FX::person(52, ['nombre' => $p2['nombre'], 'rfc' => FX::rfc(2), 'curp' => FX::curp(2)])]), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 1));
        $lote = $this->lote(4, 1);
        $p2r = FX::person(2, ['rfc' => null, 'curp' => null, 'pension' => '300.00']);
        $pen = FX::santander([['90052', 'BENEF', 'FICTICIA', 'FUSION', '60099990052', '300.00']], true);
        $this->loteCompleto($lote, [FX::person(1), $p2r], [self::pago(FX::person(1)), ['90052', $p2['nombre'], '60000090052', self::neto($p2r)]], $pen);
        $a = $this->empId('90052');
        $b = $this->empId('90002');
        $this->updateEmployee('rfc', FX::rfc(2), $b);
        $pagoAlias = $this->one("SELECT * FROM registros_tesoreria WHERE numero_empleado = '90052' AND lote_id = :l", ['l' => $lote]) ?? [];
        self::assertSame([$a, 'ASOCIADO_AUTOMATICO'], [$pagoAlias['empleado_id'] ?? null, $pagoAlias['asociacion_estado'] ?? null]);
        $c1 = $this->conciliar($lote);
        $r1 = $this->resultados((int) $c1['id']);
        self::assertSame(['NO_PAGADO', 'PAGO_SIN_RESPALDO_RRHH'], [$r1['90002']['estado'] ?? null, $r1['90052']['estado'] ?? null]);
        $pen1 = array_column($this->all('SELECT * FROM conciliacion_pensiones WHERE conciliacion_id = :c', ['c' => $c1['id']]), 'estado', 'numero_empleado');
        self::assertSame('NO_DISPERSADA', $pen1['90002'] ?? null, json_encode($pen1) ?: '');
        $antes = $this->tesoreriaSnapshot();
        $cuentaAlias = $this->one('SELECT id, empleado_id FROM cuentas_bancarias WHERE empleado_id = :e', ['e' => $a]) ?? [];
        $adm = $this->admin();
        $consulta = $this->admin('eng.consulta', 'CONSULTA');
        $this->expectDbError('42501', null, fn () => $this->fusionar($a, $b, $consulta));
        $fid = $this->fusionar($a, $b, $adm);
        $f = $this->one('SELECT * FROM fusiones_empleados WHERE id = :id', ['id' => $fid]) ?? [];
        self::assertSame([$a, $b, 'APLICADA', $adm, 'ALTA'], [$f['origen_id'], $f['destino_id'], $f['estado'], $f['usuario_id'], $f['confianza']]);
        $ca = self::j($f['analisis'])['conteos'][(string) $a];
        self::assertSame([1, 1, 2], [$ca['registros_tesoreria'], $ca['pensiones'], $ca['cuentas_activas']], json_encode($ca) ?: '');
        self::assertSame(['fusionado_en_id' => $b, 'fusion_id' => $fid], $this->one('SELECT fusionado_en_id, fusion_id FROM empleados WHERE id = :id', ['id' => $a]));
        self::assertSame(['campo' => 'fusionado_en_id', 'valor_nuevo' => (string) $b, 'fuente' => 'FUSION', 'usuario_id' => $adm],
            $this->one("SELECT campo, valor_nuevo, fuente, usuario_id FROM empleado_historial WHERE empleado_id = :e AND fuente = 'FUSION'", ['e' => $a]));
        self::assertSame($antes, $this->tesoreriaSnapshot());
        self::assertSame($a, $this->one('SELECT empleado_id FROM cuentas_bancarias WHERE id = :id', ['id' => $cuentaAlias['id']])['empleado_id']);
        $c2 = $this->conciliar($lote);
        $r2 = $this->resultados((int) $c2['id']);
        self::assertSame('CORRECTO', $r2['90002']['estado'] ?? null);
        self::assertArrayNotHasKey('90052', $r2);
        $pen2 = array_column($this->all('SELECT * FROM conciliacion_pensiones WHERE conciliacion_id = :c', ['c' => $c2['id']]), 'estado', 'numero_empleado');
        self::assertSame('CORRECTA', $pen2['90002'] ?? null, json_encode($pen2) ?: '');
        self::assertArrayNotHasKey('90052', $pen2);
        self::assertNotSame($c1['huella_entradas'], $c2['huella_entradas']);
        $fmt = static fn (array $rs): array => array_map(static fn (array $v): array => [$v['estado'], $v['diferencia']], $rs);
        self::assertEquals($fmt($r1), $fmt($this->resultados((int) $c1['id'])));
        $l5 = $this->lote(5, 1);
        $aid = $this->add(FX::santander([['90052', ...explode(' ', $p2['nombre']), '60000090052', '50.00']]), 'xlsx', 'TESORERIA_DISPERSION', $l5);
        $nuevo = $this->regs($aid, 'registros_tesoreria')[0];
        self::assertSame(['ASOCIADO_AUTOMATICO', $a, $b], [$nuevo['asociacion_estado'], $nuevo['empleado_id'], self::j($nuevo['asociacion_evidencia'])['canonico'] ?? null]);
        $pend = $this->one("SELECT id FROM registros_tesoreria WHERE asociacion_estado <> 'ASOCIADO_AUTOMATICO' LIMIT 1");
        if ($pend !== null) {
            $w = $this->web();
            $this->expectDbError('23514', 'EMPLEADO_FUSIONADO', fn () => $this->asociarManual($w, (int) $pend['id'], $a));
        }
    }

    /** @return array<int,array{0:mixed,1:mixed}> */
    private function tesoreriaSnapshot(): array
    {
        $out = [];
        foreach ($this->all('SELECT * FROM registros_tesoreria ORDER BY id') as $x) {
            $out[(int) $x['id']] = [$x['empleado_id'], $x['asociacion_estado']];
        }
        return $out;
    }

    /** @return list<array{ok?:int,error?:string,message?:string}> */
    private function concurrentMerges(array $pairs, int $user, array $aceptados): array
    {
        $script = self::storage() . '/phase7-merge-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, '<?php
declare(strict_types=1);
require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';
$in = json_decode((string) stream_get_contents(STDIN), true);
try {
    $owner = new ConciliaRH\Core\Database($in["owner"]);
    $id = ConciliaRH\Tests\Engine\Phase7Test::mergeEmployees($owner, $in["o"], $in["d"], $in["u"], $in["a"], "Misma persona según expediente ficticio", $in["web"]);
    echo json_encode(["ok" => $id]);
} catch (PDOException $e) {
    echo json_encode(["error" => (string) ($e->errorInfo[0] ?? $e->getCode()), "message" => $e->getMessage()]);
} catch (Throwable $e) {
    echo json_encode(["fatal" => get_class($e), "message" => $e->getMessage()]);
}
');
        $ownerCfg = DatabaseTestCase::dbConfig('DB_MIGRATE_USERNAME', 'DB_MIGRATE_PASSWORD', 'conciliarh_owner');
        $webCfg = DatabaseTestCase::dbConfig('DB_USERNAME', 'DB_PASSWORD', 'conciliarh_web');
        $procs = [];
        foreach ($pairs as [$o, $d]) {
            $pipes = [];
            $proc = proc_open([PHP_BINARY, $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($proc);
            fwrite($pipes[0], json_encode(['owner' => $ownerCfg, 'web' => $webCfg, 'o' => $o, 'd' => $d, 'u' => $user, 'a' => $aceptados], JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $procs[] = [$proc, $pipes];
        }
        $out = [];
        foreach ($procs as [$proc, $pipes]) {
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            $out[] = (array) (json_decode($stdout, true) ?? ['fatal' => 'salida', 'message' => $stdout . $stderr]);
        }
        unlink($script);
        return $out;
    }

    public function testConflictsConcurrencyChainAndCorrectiveSeparation(): void
    {
        [$a, $b] = $this->duplicadosPorEdicion();
        $god = $this->admin('eng.god.fusion', 'GOD');
        $consulta = $this->admin('eng.consulta', 'CONSULTA');
        $this->updateEmployee('curp', FX::curp(99), $a);
        $this->expectDbError('42501', null, fn () => $this->fusionar($a, $b, $consulta, ['CURP_DIFERENTE']));
        $adm = $god;
        $this->expectDbError('23514', 'CONFLICTOS_SIN_ACEPTAR', fn () => $this->fusionar($a, $b, $adm));
        $w = $this->web($adm);
        $huella = (string) $w->fetchValue('SELECT fn_fusion_huella(fn_fusion_analisis(CAST(:a AS bigint), CAST(:b AS bigint)))', ['a' => $a, 'b' => $b]);
        self::owner()->execute("UPDATE empleados SET grupo = 'BOMBEROS' WHERE id = :id", ['id' => $a]);
        $this->expectDbError('40001', 'REGISTRO_MODIFICADO', fn () => $w->fetchValue(
            "SELECT fn_fusionar_empleados(CAST(:a AS bigint), CAST(:b AS bigint), CAST(:u AS bigint), 'Misma persona según expediente ficticio',
             CAST(:ac AS text[]), CAST(:h AS text))", ['a' => $a, 'b' => $b, 'u' => $adm, 'ac' => '{CURP_DIFERENTE}', 'h' => $huella]));
        unset($w);
        $results = $this->concurrentMerges([[$a, $b], [$b, $a]], $adm, ['CURP_DIFERENTE']);
        $ok = array_values(array_filter(array_map(static fn (array $r) => $r['ok'] ?? null, $results)));
        $errores = array_values(array_filter($results, static fn (array $r) => isset($r['error'])));
        self::assertTrue(count($ok) === 1 && count($errores) === 1, json_encode($results, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(1, (int) $this->one("SELECT count(*) AS n FROM fusiones_empleados WHERE estado = 'APLICADA'")['n']);
        $f1 = $this->one('SELECT * FROM fusiones_empleados WHERE id = :id', ['id' => $ok[0]]) ?? [];
        $origen = (int) $f1['origen_id'];
        $destino = (int) $f1['destino_id'];
        $this->add(FX::tabulado([FX::person(3, ['nombre' => FX::person(2)['nombre'], 'rfc' => FX::rfc(2), 'curp' => FX::curp(3)])]), 'xlsx', 'RRHH_TABULADO', $this->lote(6, 1));
        $c = $this->emp('90003');
        if ($c === null) {
            $this->add(FX::tabulado([FX::person(3, ['nombre' => FX::person(2)['nombre'], 'rfc' => null, 'curp' => FX::curp(3)])]), 'xlsx', 'RRHH_TABULADO', $this->lote(6, 2));
            $c = $this->emp('90003') ?? [];
            $this->updateEmployee('rfc', FX::rfc(2), (int) $c['id']);
        }
        $cid = (int) $c['id'];
        $f2 = $this->fusionar($destino, $cid, $adm, ['CURP_DIFERENTE']);
        self::assertSame($cid, $this->ptr($origen), 'Un salto');
        self::assertSame([$origen], self::j($this->one('SELECT array_to_json(reapuntados) AS r FROM fusiones_empleados WHERE id = :id', ['id' => $f2])['r']));
        $w = $this->web($consulta);
        $this->expectDbError('42501', null, fn () => $w->fetchValue("SELECT fn_separar_fusion(CAST(:f AS bigint), CAST(:u AS bigint), 'Corrección de una fusión equivocada ficticia')",
            ['f' => $f2, 'u' => $consulta]));
        unset($w);
        $admSep = $this->admin();
        $this->web($admSep)->fetchValue("SELECT fn_separar_fusion(CAST(:f AS bigint), CAST(:u AS bigint), 'Corrección de una fusión equivocada ficticia')", ['f' => $f2, 'u' => $admSep]);
        self::assertNull($this->ptr($destino));
        self::assertSame($destino, $this->ptr($origen), 'Vuelve a su canónico anterior');
        self::assertSame(['estado' => 'SEPARADA', 'separada_por' => $admSep], $this->one('SELECT estado, separada_por FROM fusiones_empleados WHERE id = :id', ['id' => $f2]));
        self::assertSame(2, (int) $this->one('SELECT count(*) AS n FROM fusiones_empleados')['n']);
        $this->expectDbError(null, null, fn () => self::owner()->execute('DELETE FROM fusiones_empleados WHERE id = :id', ['id' => $f2]));
    }

    public function testPensionHistoryBetweenPeriodsNeverGuesses(): void
    {
        $l1 = $this->lote(1, 1);
        $this->add(FX::tabulado([FX::person(1), FX::person(2), FX::person(3, ['pension' => '450.00'])]), 'xlsx', 'RRHH_TABULADO', $l1);
        $rows = [[self::CLABE_A, 'BENEF HISTORICA UNO', '300.00'], [self::CLABE_B, 'BENEF RECURRENTE DOS', '450.00'], ['002180000000033333', 'SOLO NOMBRE IMPORTE', '77.00']];
        $i1 = $this->interPension($rows, $l1);
        $regs1 = array_column($this->regs($i1, 'registros_tesoreria'), null, 'nombre_normalizado');
        $this->asociarManual($this->web(), (int) $regs1['BENEF HISTORICA UNO']['id'], $this->empId('90002'));
        self::assertSame('IMPORTE_RETENCION_UNICA', self::j($regs1['BENEF RECURRENTE DOS']['asociacion_evidencia'])['motivo'] ?? null);
        self::assertSame('BAJA', $regs1['BENEF RECURRENTE DOS']['asociacion_confianza']);
        $l2 = $this->lote(1, 2);
        $this->add(FX::tabulado([FX::person(1), FX::person(2), FX::person(3, ['pension' => '450.00'])]), 'xlsx', 'RRHH_TABULADO', $l2);
        $i2 = $this->interPension($rows, $l2);
        $regs2 = array_column($this->regs($i2, 'registros_tesoreria'), null, 'nombre_normalizado');
        $h = $regs2['BENEF HISTORICA UNO'];
        $he = self::j($h['asociacion_evidencia']);
        self::assertSame(['SUGERENCIA', 'MEDIA', 'CLABE_Y_NOMBRE_HISTORICOS', [$this->empId('90002')], 'MEDIA'],
            [$h['asociacion_estado'], $h['asociacion_confianza'], $he['motivo'] ?? null, $he['candidatos'] ?? null, $he['categoria'] ?? null]);
        self::assertNull($h['empleado_id'], '19. La CLABE vista antes (sin confirmar) nunca asocia sola');
        $r = $regs2['BENEF RECURRENTE DOS'];
        self::assertSame(['MEDIA', 'IMPORTE_RECURRENTE_MISMA_CLABE'], [$r['asociacion_confianza'], self::j($r['asociacion_evidencia'])['motivo'] ?? null]);
        $s = $regs2['SOLO NOMBRE IMPORTE'];
        $se = self::j($s['asociacion_evidencia']);
        self::assertSame(['NO_IDENTIFICADO', 'SIN_EVIDENCIA'], [$s['asociacion_estado'], $se['categoria'] ?? null], '21. Nombre + importe: insuficiente');
        self::assertContains('MISMO_NOMBRE_CON_ESA_CLABE', (array) ($se['senales'] ?? []));
        $l3 = $this->lote(2, 1);
        $this->add(FX::tabulado([FX::person(1, ['pension' => '300.00']), FX::person(2)]), 'xlsx', 'RRHH_TABULADO', $l3);
        $i3 = $this->interPension([[self::CLABE_A, 'OTRO NOMBRE', '300.00']], $l3);
        $c = $this->regs($i3, 'registros_tesoreria')[0];
        $ce = self::j($c['asociacion_evidencia']);
        self::assertSame(['AMBIGUO', 'SENALES_CONTRADICTORIAS'], [$c['asociacion_estado'], $ce['motivo'] ?? null]);
        $cand = (array) ($ce['candidatos'] ?? []);
        sort($cand);
        $exp = [$this->empId('90001'), $this->empId('90002')];
        sort($exp);
        self::assertSame($exp, $cand);
    }

    public function testReviewMergedAliasAccountIsNotAConflictAndCanBeConfirmed(): void
    {
        $p2 = FX::person(2);
        $this->add(FX::tabulado([FX::person(52, ['nombre' => $p2['nombre'], 'rfc' => FX::rfc(2), 'curp' => FX::curp(2)])]), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 1));
        $this->add(FX::tabulado([FX::person(2, ['rfc' => null, 'curp' => null])]), 'xlsx', 'RRHH_TABULADO', $this->lote(1, 2));
        $a = $this->empId('90052');
        $b = $this->empId('90002');
        $this->updateEmployee('rfc', FX::rfc(2), $b);
        $this->add(FX::santander([['90052', ...explode(' ', $p2['nombre']), '60000090052', '10.00']]), 'xlsx', 'TESORERIA_DISPERSION', $this->lote(2, 1));
        self::assertSame($a, $this->one("SELECT empleado_id FROM cuentas_bancarias WHERE cuenta_ultimos4 = '0052'")['empleado_id'] ?? null);
        $this->fusionar($a, $b, $this->admin());
        $aid = $this->add(FX::santander([['90002', ...explode(' ', $p2['nombre']), '60000090052', '20.00']]), 'xlsx', 'TESORERIA_DISPERSION', $this->lote(2, 2));
        $r = $this->regs($aid, 'registros_tesoreria')[0];
        self::assertSame(['ASOCIADO_AUTOMATICO', $b], [$r['asociacion_estado'], $r['empleado_id']], 'Número del canónico + cuenta del alias = misma persona');
        self::assertSame([], $this->all("SELECT 1 FROM incidencias_identidad WHERE tipo = 'CLABE_CONFLICTO' AND registro_id = :r", ['r' => $r['id']]));
        $i = $this->add(FX::interbancaria([['60000090052', $p2['nombre'], '5.00']]), 'xls', 'TESORERIA_DISPERSION', $this->lote(3, 1));
        $reg = $this->regs($i, 'registros_tesoreria')[0];
        if ($reg['empleado_id'] === null) {
            $w = $this->web();
            $w->transaction(function (Database $w) use ($reg, $b): void {
                $this->asociarManual($w, (int) $reg['id'], $b);
                $w->fetchValue("SELECT fn_confirmar_cuenta(CAST(:r AS bigint), CAST(:u AS bigint), 'Cuenta de la misma persona (alias fusionado)')",
                    ['r' => $reg['id'], 'u' => $this->user]);
            });
        }
        $this->addToAssertionCount(1);
    }

    public function testReviewSeparationInAChainRestoresEachAliasToItsOwnRoot(): void
    {
        $l = $this->lote(1, 1);
        $this->add(FX::tabulado(array_map(static fn (int $n): array => FX::person($n, ['rfc' => null, 'curp' => null]), [1, 2, 3, 4])), 'xlsx', 'RRHH_TABULADO', $l);
        [$A, $B, $C, $Dd] = array_map(fn (int $n): int => $this->empId("9000$n"), [1, 2, 3, 4]);
        foreach ([$A, $B, $C, $Dd] as $x) {
            $this->updateEmployee('rfc', FX::rfc(1), $x);
        }
        $god = $this->admin('eng.god.cadena', 'GOD');
        $acepta = ['NOMINA_SIMULTANEA_MISMO_LOTE'];
        $f0 = $this->fusionar($C, $A, $god, $acepta);
        $f1 = $this->fusionar($A, $B, $god, $acepta);
        $f2 = $this->fusionar($B, $Dd, $god, $acepta);
        self::assertSame([$Dd, $Dd, $Dd], [$this->ptr($C), $this->ptr($A), $this->ptr($B)], 'Un salto al canónico');
        $this->web($god)->fetchValue("SELECT fn_separar_fusion(CAST(:f AS bigint), CAST(:u AS bigint), 'Corrección ficticia de la fusión intermedia')", ['f' => $f1, 'u' => $god]);
        self::assertSame([$A, null, $Dd], [$this->ptr($C), $this->ptr($A), $this->ptr($B)], 'C vuelve a su propia raíz (A); A es canónico; B sigue en D');
        $this->web($god)->fetchValue("SELECT fn_separar_fusion(CAST(:f AS bigint), CAST(:u AS bigint), 'Corrección ficticia de la fusión final de la cadena')", ['f' => $f2, 'u' => $god]);
        self::assertSame([$A, null, null], [$this->ptr($C), $this->ptr($A), $this->ptr($B)], 'C nunca pasa a B');
        self::assertGreaterThan(0, $f0);
    }

    public function testReviewWorkerWithTheSameKeyIdButAnotherSecretNeverWrites(): void
    {
        $lote = $this->lote(1, 1);
        $this->add(FX::interbancaria([[self::CLABE_A, 'PERSONA FICTICIA', '10.00']]), 'xls', 'TESORERIA_DISPERSION', $lote);
        self::assertNotNull($this->one('SELECT kcv_activa FROM hmac_llaves_estado')['kcv_activa'] ?? null);
        $this->cfg = $this->settingsWithRing(['h1' => hash('sha256', 'otro secreto')]);
        $aid = $this->add(FX::interbancaria([[self::CLABE_B, 'OTRA PERSONA', '11.00']]), 'xls', 'TESORERIA_DISPERSION', $lote, false);
        $w = self::worker($this->cfg, 'phpunit-kcv');
        for ($i = 0; $i < 4; $i++) {
            $w->run(true);
        }
        $job = $this->one("SELECT * FROM procesos WHERE tipo = 'ANALIZAR_ARCHIVO' ORDER BY id DESC LIMIT 1") ?? [];
        self::assertSame(['PENDIENTE', 'LLAVE_HMAC_INCORRECTA'], [$job['estado'] ?? null, $job['error_codigo'] ?? null]);
        self::assertSame(0, (int) $this->one('SELECT count(*) AS n FROM staging_registros WHERE archivo_id = :a', ['a' => $aid])['n']);
    }
}
