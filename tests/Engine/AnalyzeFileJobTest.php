<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Engine;

use ConciliaRH\Engine\Analysis\Analyzer;
use ConciliaRH\Engine\Support\Json;
use ConciliaRH\Tests\Fixtures\Book;
use ConciliaRH\Tests\Fixtures\ExcelFixtures;
use ConciliaRH\Tests\Support\EngineTestCase;
use PDOException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class AnalyzeFileJobTest extends EngineTestCase
{
    /** @var array{user:int,periodo:int,lote:int,lote2:int} */
    private array $lote;
    private ?string $tmp = null;

    protected function setUp(): void
    {
        parent::setUp();
        $o = self::owner();
        $user = (int) $o->fetchValue(
            "INSERT INTO usuarios (username, nombre, password_hash) VALUES ('eng.analisis', 'Tester Análisis', repeat('x', 30))
             ON CONFLICT (username) DO UPDATE SET nombre = EXCLUDED.nombre RETURNING id");
        self::clean();
        $per = (int) $o->fetchValue(
            "INSERT INTO periodos_nomina (anio, mes, quincena, fecha_inicio, fecha_fin, descripcion, creado_por)
             VALUES (2041, 1, 1, '2041-01-01', '2041-01-15', 'PERIODO ANALISIS', :u) RETURNING id", ['u' => $user]);
        $l1 = (int) $o->fetchValue("SELECT id FROM fn_crear_lote(:p, '', '', '', '', :u)", ['p' => $per, 'u' => $user]);
        $l2 = (int) $o->fetchValue("SELECT id FROM fn_crear_lote(:p, '', '', '', '', :u)", ['p' => $per, 'u' => $user]);
        $this->lote = ['user' => $user, 'periodo' => $per, 'lote' => $l1, 'lote2' => $l2];
    }

    protected function tearDown(): void
    {
        if (CONCILIARH_DB_AVAILABLE) {
            self::clean();
        }
        if ($this->tmp !== null && is_dir($this->tmp)) {
            foreach ((array) glob($this->tmp . '/*') as $f) {
                @unlink((string) $f);
            }
            @rmdir($this->tmp);
        }
        parent::tearDown();
    }

    private static function clean(): void
    {
        $o = self::owner();
        self::cleanIdentity();
        $o->execute('UPDATE archivos_importados SET analisis_vigente_id = NULL');
        foreach (['staging_registros', 'controles_archivo', 'archivo_hojas', 'analisis_archivo', 'archivos_importados', 'lotes_importacion', 'periodos_nomina'] as $t) {
            $o->execute("DELETE FROM $t");
        }
        $o->execute("UPDATE configuracion SET valor = '1000000' WHERE clave = 'analisis.max_celdas'");
    }

    private function tmpDir(): string
    {
        if ($this->tmp === null) {
            $this->tmp = sys_get_temp_dir() . '/crh-anjob-' . bin2hex(random_bytes(6));
            mkdir($this->tmp, 0775, true);
        }
        return $this->tmp;
    }

    private static function fx(string $name): string
    {
        return ExcelFixtures::dir() . '/analisis/' . $name;
    }

    private static function uuid(): string
    {
        $h = bin2hex(random_bytes(16));
        return sprintf('%s-%s-4%s-8%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
    }

    private function register(string $src, string $loteKey = 'lote'): int
    {
        $o = self::owner();
        $ext = pathinfo($src, PATHINFO_EXTENSION);
        $uid = self::uuid();
        $rel = "imports/{$this->lote['periodo']}/{$this->lote[$loteKey]}/$uid/original.$ext";
        $dst = self::storage() . '/' . $rel;
        mkdir(dirname($dst), 0775, true);
        copy($src, $dst);
        chmod($dst, 0440);
        $data = (string) file_get_contents($src);
        $tipo = (int) $o->fetchValue("SELECT id FROM tipos_documento WHERE codigo = 'TESORERIA_DISPERSION'");
        $origen = (int) $o->fetchValue("SELECT id FROM origenes_administrativos WHERE codigo = 'SIN_DEFINIR'");
        $aid = (int) $o->fetchValue(
            "INSERT INTO archivos_importados (uuid, lote_id, tipo_documento_id, origen_administrativo_id, nombre_original, extension,
                 mime_detectado, firma, tamano_bytes, sha256, ruta_relativa, usuario_carga_id, tiene_macros)
             VALUES (CAST(:uuid AS uuid), :lote, :tipo, :origen, :nombre, :ext, 'application/octet-stream', :firma, :tam, :sha, :rel, :usr, CAST(:mac AS boolean))
             RETURNING id",
            ['uuid' => $uid, 'lote' => $this->lote[$loteKey], 'tipo' => $tipo, 'origen' => $origen, 'nombre' => basename($src), 'ext' => $ext,
             'firma' => $ext === 'xls' ? 'OLE2' : 'OOXML', 'tam' => strlen($data), 'sha' => hash('sha256', $data), 'rel' => $rel,
             'usr' => $this->lote['user'], 'mac' => $ext === 'xlsm' ? 'true' : 'false']);
        $job = (int) $o->fetchValue("INSERT INTO procesos (tipo, parametros, max_intentos) VALUES ('VERIFICAR_ARCHIVO', CAST(:p AS jsonb), 2) RETURNING id",
            ['p' => Json::encode(['archivo_id' => $aid])]);
        $o->execute('UPDATE archivos_importados SET proceso_id = :j WHERE id = :a', ['j' => $job, 'a' => $aid]);
        return $aid;
    }

    private static function drain(int $n = 6): void
    {
        $w = self::worker(null, 'phpunit-analisis');
        for ($i = 0; $i < $n; $i++) {
            $w->run(true);
        }
    }

    /** @return array<string,mixed> */
    private static function archivo(int $aid): array
    {
        return self::owner()->fetchOne('SELECT * FROM archivos_importados WHERE id = :id', ['id' => $aid]) ?? [];
    }

    /** @return array<string,int> */
    private static function counts(int $aid): array
    {
        $out = [];
        foreach (['archivo_hojas', 'controles_archivo', 'staging_registros', 'analisis_archivo'] as $t) {
            $out[$t] = (int) self::owner()->fetchValue("SELECT count(*) FROM $t WHERE archivo_id = :a", ['a' => $aid]);
        }
        return $out;
    }

    private static function enqueueAnalysis(int $aid, string $key): int
    {
        $o = self::owner();
        $job = (int) $o->fetchValue(
            "INSERT INTO procesos (tipo, parametros, max_intentos, clave_idempotencia) VALUES ('ANALIZAR_ARCHIVO', CAST(:p AS jsonb), 2, :k) RETURNING id",
            ['p' => Json::encode(['archivo_id' => $aid]), 'k' => $key]);
        $o->execute('UPDATE archivos_importados SET proceso_id = :j WHERE id = :a', ['j' => $job, 'a' => $aid]);
        return $job;
    }

    /** @return array<string,mixed> */
    private static function json(mixed $v): array
    {
        return is_string($v) ? (array) json_decode($v, true, 512, JSON_THROW_ON_ERROR) : (array) $v;
    }

    /** @return array<string,mixed> */
    private static function jobResult(int $job): array
    {
        return self::json(self::jobRow($job)['resultado']);
    }

    /** @return array<string,mixed> */
    private static function lastAuditContext(string $accion, int $aid): array
    {
        $ev = self::owner()->fetchOne('SELECT contexto FROM auditoria WHERE accion = :ac AND entidad_id = :e ORDER BY secuencia DESC LIMIT 1',
            ['ac' => $accion, 'e' => (string) $aid]);
        self::assertNotNull($ev);
        return self::json($ev['contexto']);
    }

    private function saveSpreadsheet(Spreadsheet $wb, string $name): string
    {
        $p = $this->tmpDir() . '/' . $name;
        $w = new Xlsx($wb);
        $w->setPreCalculateFormulas(false);
        $w->save($p);
        return $p;
    }

    public function testVerificationChainsAnalysisAndPersistsEverything(): void
    {
        $o = self::owner();
        $aid = $this->register(self::fx('a09_total.xlsx'));
        self::drain();
        $a = self::archivo($aid);
        self::assertSame('ANALIZADO', $a['estado']);
        self::assertSame('TESORERIA_SANTANDER', $o->fetchValue('SELECT codigo FROM perfiles_importacion WHERE id = :id', ['id' => $a['perfil_importacion_id']]));
        self::assertSame(1, (int) $a['huella_version']);
        self::assertSame(64, strlen((string) $a['huella_contenido']));
        self::assertSame('NUEVO', $a['clasificacion_duplicidad']);
        $job = self::jobRow((int) $a['proceso_id']);
        self::assertSame(['ANALIZAR_ARCHIVO', 'COMPLETADO'], [$job['tipo'], $job['estado']]);
        $an = $o->fetchOne('SELECT * FROM analisis_archivo WHERE id = :id', ['id' => $a['analisis_vigente_id']]);
        self::assertNotNull($an);
        self::assertSame([1, true, 'ANALIZADO', 'ALTA', 0], [(int) $an['numero'], $an['vigente'], $an['resultado'], $an['perfil_confianza'], (int) $an['hoja_indice']]);
        $metricas = self::json($an['metricas']);
        foreach (['lectura_ms', 'total_ms', 'rss_max_kb', 'celdas'] as $k) {
            self::assertArrayHasKey($k, $metricas);
        }
        $c = self::counts($aid);
        self::assertSame([6, 1, 1], [$c['staging_registros'], $c['archivo_hojas'], $c['analisis_archivo']]);
        $st = $o->fetchOne('SELECT * FROM staging_registros WHERE archivo_id = :a ORDER BY fila_origen LIMIT 1', ['a' => $aid]);
        self::assertNotNull($st);
        self::assertSame(8, (int) $st['fila_origen']);
        self::assertSame('OK', $st['importe_estado']);
        self::assertSame(64, strlen((string) $st['cuenta_hmac']));
        self::assertSame(4, strlen((string) $st['cuenta_ultimos4']));
        self::assertStringStartsWith('****', (string) self::json($st['datos'])['CUENTA'], 'La cuenta completa nunca se guarda en staging');
        $total = $o->fetchOne("SELECT * FROM controles_archivo WHERE archivo_id = :a AND codigo = 'TOTAL_IMPORTE'", ['a' => $aid]);
        self::assertNotNull($total);
        self::assertSame('CUADRA', $total['estado']);
        self::assertSame($total['valor_declarado'], $total['valor_calculado']);
        $cols = $o->fetchAll('SELECT campo_canonico, identificador_probable FROM archivo_columnas c JOIN archivo_hojas h ON h.id = c.hoja_id WHERE h.archivo_id = :a',
            ['a' => $aid]);
        $canon = array_column($cols, 'campo_canonico');
        foreach (['NUM_EMPLEADO', 'IMPORTE', 'CUENTA'] as $k) {
            self::assertContains($k, $canon);
        }
        $audit = $o->fetchOne("SELECT origen, contexto FROM auditoria WHERE accion = 'archivos.analizar' AND entidad_id = :e ORDER BY secuencia DESC LIMIT 1",
            ['e' => (string) $aid]);
        self::assertNotNull($audit);
        self::assertSame('WORKER', $audit['origen']);
        self::assertSame('TESORERIA_SANTANDER', self::json($audit['contexto'])['perfil']);
        self::assertStringNotContainsString('PERSONA', json_encode($audit, JSON_UNESCAPED_UNICODE) ?: '', 'La auditoría no contiene datos de celdas');
    }

    public function testReanalysisIsIdempotentAndReplacesDerivedRows(): void
    {
        $o = self::owner();
        $aid = $this->register(self::fx('a25_tabulado_policia.xlsx'));
        self::drain();
        $first = self::archivo($aid);
        $before = self::counts($aid);
        self::enqueueAnalysis($aid, "analizar:test:$aid:r2");
        self::drain(2);
        $after = self::archivo($aid);
        $c = self::counts($aid);
        self::assertSame(2, $c['analisis_archivo']);
        unset($c['analisis_archivo'], $before['analisis_archivo']);
        self::assertSame($before, $c);
        self::assertSame($first['huella_contenido'], $after['huella_contenido'], 'Determinista: misma huella');
        $vig = $o->fetchAll('SELECT numero FROM analisis_archivo WHERE archivo_id = :a AND vigente', ['a' => $aid]);
        self::assertSame([2], array_map(static fn ($v) => (int) $v['numero'], $vig));
        $old = (int) $o->fetchValue('SELECT count(*) FROM archivo_hojas h JOIN analisis_archivo a ON a.id = h.analisis_id WHERE a.archivo_id = :a AND NOT a.vigente',
            ['a' => $aid]);
        self::assertSame(0, $old, 'Los derivados del análisis anterior se sustituyen');
        $job = (int) $after['proceso_id'];
        $o->execute("UPDATE procesos SET estado = 'PENDIENTE', finalizado_at = NULL, intentos = 0 WHERE id = :id", ['id' => $job]);
        self::drain(1);
        self::assertSame(2, self::counts($aid)['analisis_archivo']);
        self::assertStringContainsString('ya fue persistido', (string) self::jobResult($job)['omitido']);
    }

    public function testSupersededJobIsSkipped(): void
    {
        $aid = $this->register(self::fx('a01_una_hoja.xlsx'));
        self::drain();
        $old = self::enqueueAnalysis($aid, "analizar:test:$aid:old");
        self::enqueueAnalysis($aid, "analizar:test:$aid:new");
        self::drain(3);
        self::assertStringContainsString('sustituyó', (string) self::jobResult($old)['omitido']);
        self::assertSame(2, self::counts($aid)['analisis_archivo']);
    }

    public function testContentDuplicateAndBinaryDuplicate(): void
    {
        $a = $this->register(self::fx('a09_total.xlsx'));
        $b = $this->register(self::fx('a14_orden_distinto.xlsx'), 'lote2');
        $c = $this->register(self::fx('a22_santander.xlsm'), 'lote2');
        self::drain(10);
        [$ra, $rb, $rc] = [self::archivo($a), self::archivo($b), self::archivo($c)];
        self::assertSame('NUEVO', $ra['clasificacion_duplicidad']);
        self::assertSame(['DUPLICADO_CONTENIDO', $a], [$rb['clasificacion_duplicidad'], (int) $rb['archivo_relacionado_id']]);
        self::assertSame(['DUPLICADO_CONTENIDO', $a], [$rc['clasificacion_duplicidad'], (int) $rc['archivo_relacionado_id']]);
        self::assertNotSame($ra['sha256'], $rb['sha256']);
        self::assertNotNull($ra['huella_contenido']);
        self::assertSame($ra['huella_contenido'], $rb['huella_contenido']);
        self::assertSame($rb['huella_contenido'], $rc['huella_contenido']);
        self::assertStringContainsString('misma huella', (string) self::lastAuditContext('archivos.clasificar_contenido', $b)['motivo']);
    }

    public function testPossibleVersionSamePeriodAndProfile(): void
    {
        $wb = IOFactory::load(self::fx('a09_total.xlsx'));
        $ws = $wb->getActiveSheet();
        $ws->setCellValue('H8', (float) $ws->getCell('H8')->getValue() + 100);
        $src = $this->saveSpreadsheet($wb, 'version.xlsx');
        $a = $this->register(self::fx('a09_total.xlsx'));
        $b = $this->register($src, 'lote2');
        self::drain(8);
        $rb = self::archivo($b);
        self::assertSame(['POSIBLE_VERSION', $a], [$rb['clasificacion_duplicidad'], (int) $rb['archivo_relacionado_id']]);
        $ctx = self::lastAuditContext('archivos.clasificar_contenido', $b);
        self::assertSame(5, $ctx['registros_en_comun']);
        self::assertEquals(1.0, $ctx['encabezados_jaccard']);
        self::assertSame(6, $ctx['claves_en_comun'], 'Misma población (empleado + cuenta), un importe distinto');
        self::assertSame('NUEVO', self::archivo($a)['clasificacion_duplicidad'], 'Nunca se reemplaza automáticamente');
    }

    public function testManualReviewThenManualSheetInvalidatesAndReanalyzes(): void
    {
        $o = self::owner();
        $aid = $this->register(self::fx('a18b_hojas_ambiguas.xlsx'));
        self::drain();
        $a = self::archivo($aid);
        self::assertSame(['REVISION_MANUAL', 'REVISION_MANUAL', null], [$a['estado'], $a['clasificacion_duplicidad'], $a['huella_contenido']]);
        $hojas = $o->fetchAll('SELECT indice, estado, puntaje_candidata FROM archivo_hojas WHERE archivo_id = :a ORDER BY indice', ['a' => $aid]);
        self::assertSame(['CANDIDATA', 'CANDIDATA'], array_column($hojas, 'estado'));
        $o->execute("UPDATE archivos_importados SET hoja_manual_indice = 1, hoja_manual_nombre = 'Version B', seleccion_manual_at = now() WHERE id = :a", ['a' => $aid]);
        self::enqueueAnalysis($aid, "analizar:test:$aid:m1");
        self::drain(2);
        $a = self::archivo($aid);
        $an = $o->fetchOne('SELECT * FROM analisis_archivo WHERE id = :id', ['id' => $a['analisis_vigente_id']]);
        self::assertNotNull($an);
        self::assertSame(['ANALIZADO', 'MANUAL', 1, 'Version B'],
            [$a['estado'], $an['hoja_origen'], (int) $an['hoja_indice'], self::json($an['seleccion_manual'])['hoja_nombre']]);
        self::assertNotNull($a['huella_contenido']);
        self::assertSame('NUEVO', $a['clasificacion_duplicidad']);
    }

    public function testManualSelectionResetsFingerprintByTrigger(): void
    {
        $o = self::owner();
        $aid = $this->register(self::fx('a01_una_hoja.xlsx'));
        self::drain();
        self::assertNotNull(self::archivo($aid)['huella_contenido']);
        $perfil = (int) $o->fetchValue("SELECT id FROM perfiles_importacion WHERE codigo = 'TESORERIA_BBVA'");
        $o->execute('UPDATE archivos_importados SET perfil_manual_id = :p WHERE id = :a', ['p' => $perfil, 'a' => $aid]);
        $a = self::archivo($aid);
        self::assertSame([null, null, 'PENDIENTE'], [$a['huella_contenido'], $a['huella_version'], $a['clasificacion_duplicidad']]);
    }

    public function testUnknownProfileAndXls(): void
    {
        $unk = $this->register(self::fx('a19_perfil_desconocido.xlsx'));
        $xls = $this->register(self::fx('a21_interbancaria.xls'));
        self::drain(8);
        $u = self::archivo($unk);
        self::assertSame(['REVISION_MANUAL', null], [$u['estado'], $u['perfil_importacion_id']]);
        self::assertSame('ANALIZADO', self::archivo($xls)['estado']);
        $hojas = self::owner()->fetchAll('SELECT tiene_formulas, estado FROM archivo_hojas WHERE archivo_id = :a ORDER BY indice', ['a' => $xls]);
        self::assertNull($hojas[0]['tiene_formulas']);
        self::assertSame(['SELECCIONADA', 'DESCARTADA', 'DESCARTADA'], array_column($hojas, 'estado'));
    }

    public function testPermanentErrorIsNotRetriedAndMarksError(): void
    {
        self::owner()->execute("UPDATE configuracion SET valor = '10000' WHERE clave = 'analisis.max_celdas'");
        $b = new Book('Sheet');
        $b->append(0, ['Núm.', 'Nombre', 'R.F.C.', 'IMPORTE', 'CTA BBVA', ...array_map(static fn ($i) => "X$i", range(0, 39))]);
        for ($i = 0; $i < 300; $i++) {
            $b->append(0, [$i, 'PERSONA', 'XAXX010101000', 1.0, '0100000000', ...array_fill(0, 40, 1)]);
        }
        $big = $this->tmpDir() . '/grande.xlsx';
        file_put_contents($big, $b->xlsx());
        $aid = $this->register($big);
        self::drain(4);
        $a = self::archivo($aid);
        self::assertSame(['ERROR', 'ESTRUCTURA_EXCESIVA'], [$a['estado'], $a['error_codigo']]);
        $job = self::jobRow((int) $a['proceso_id']);
        self::assertSame(['ERROR', 1], [$job['estado'], (int) $job['intentos']], 'Un error permanente no se reintenta');
        self::assertSame(0, self::counts($aid)['analisis_archivo']);
    }

    public function testWorkerCannotTouchIdentityButCanPersistAnalysis(): void
    {
        $w = self::workerDb();
        foreach (['UPDATE archivos_importados SET hoja_manual_indice = 1 WHERE false', "UPDATE analisis_archivo SET resultado = 'ANALIZADO' WHERE false",
                  'DELETE FROM analisis_archivo WHERE false'] as $sql) {
            try {
                $w->execute($sql);
                self::fail("Se esperaba InsufficientPrivilege: $sql");
            } catch (PDOException $e) {
                self::assertSame('42501', $e->errorInfo[0] ?? (string) $e->getCode(), $sql);
            }
        }
    }

    public function testSameStructureDifferentPopulationIsNotAVersion(): void
    {
        $wb = IOFactory::load(self::fx('a09_total.xlsx'));
        $ws = $wb->getActiveSheet();
        for ($r = 8; $r < 14; $r++) {
            $ws->setCellValueExplicit("C$r", sprintf('9%04d', $r), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $ws->setCellValueExplicit("G$r", sprintf('700%08d', $r), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        $src = $this->saveSpreadsheet($wb, 'otra_poblacion.xlsx');
        $a = $this->register(self::fx('a09_total.xlsx'));
        $b = $this->register($src, 'lote2');
        self::drain(8);
        self::assertSame('NUEVO', self::archivo($a)['clasificacion_duplicidad']);
        self::assertSame('NUEVO', self::archivo($b)['clasificacion_duplicidad']);
    }

    public function testFailedReanalysisKeepsPreviousResult(): void
    {
        $aid = $this->register(self::fx('a25_tabulado_policia.xlsx'));
        self::drain();
        $before = self::archivo($aid)['estado'];
        self::assertContains($before, ['ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS']);
        $path = self::storage() . '/' . self::archivo($aid)['ruta_relativa'];
        $bak = substr($path, 0, (int) strrpos($path, '.')) . '.bak';
        chmod($path, 0640);
        rename($path, $bak);
        try {
            $job = self::enqueueAnalysis($aid, "analizar:test:$aid:fail");
            self::owner()->execute('UPDATE procesos SET max_intentos = 1 WHERE id = :id', ['id' => $job]);
            self::drain(2);
            $a = self::archivo($aid);
            self::assertSame('ERROR', self::jobRow($job)['estado']);
            self::assertSame($before, $a['estado']);
            self::assertStringContainsString('conserva', (string) $a['error_resumen'], 'El análisis vigente se conserva');
            self::assertSame(1, self::counts($aid)['analisis_archivo']);
        } finally {
            rename($bak, $path);
        }
    }

    public function testChangedFingerprintSameVersionIsFlaggedNotBlocked(): void
    {
        $o = self::owner();
        $aid = $this->register(self::fx('a18b_hojas_ambiguas.xlsx'));
        self::drain();
        foreach ([[0, 'Version A'], [1, 'Version B']] as [$idx, $name]) {
            $o->execute('UPDATE archivos_importados SET hoja_manual_indice = :i, hoja_manual_nombre = :n WHERE id = :a', ['i' => $idx, 'n' => $name, 'a' => $aid]);
            self::enqueueAnalysis($aid, "analizar:test:$aid:h$idx");
            self::drain(2);
        }
        $a = self::archivo($aid);
        $an = $o->fetchOne('SELECT advertencias FROM analisis_archivo WHERE id = :id', ['id' => $a['analisis_vigente_id']]);
        self::assertNotNull($an);
        self::assertSame('ANALIZADO_CON_ADVERTENCIAS', $a['estado']);
        self::assertContains('HUELLA_DISTINTA_MISMA_VERSION', array_column(self::json($an['advertencias']), 'codigo'));
    }

    public function testStagedHashesAreHmac(): void
    {
        $aid = $this->register(self::fx('a01_una_hoja.xlsx'));
        self::drain();
        $hashes = array_column(self::owner()->fetchAll('SELECT hash_registro FROM staging_registros WHERE archivo_id = :a', ['a' => $aid]), 'hash_registro');
        $plain = array_map(static fn ($s) => $s->hash, (new Analyzer())->analyze(self::fx('a01_una_hoja.xlsx'), 'xlsx')->staging);
        self::assertNotEmpty($hashes);
        self::assertSame([], array_values(array_intersect($hashes, $plain)), 'Sin llave no pueden reproducirse (ni forzarse) los hashes persistidos');
    }

    public function testOriginalAlteredAfterVerificationIsNotAnalyzed(): void
    {
        $aid = $this->register(self::fx('a01_una_hoja.xlsx'));
        self::worker(null, 'phpunit-toctou')->run(true);
        $path = self::storage() . '/' . self::archivo($aid)['ruta_relativa'];
        chmod($path, 0640);
        file_put_contents($path, 'alterado', FILE_APPEND);
        self::drain(2);
        $a = self::archivo($aid);
        self::assertSame(['ERROR', 'INTEGRIDAD_FALLIDA'], [$a['estado'], $a['error_codigo']]);
        self::assertSame(0, self::counts($aid)['analisis_archivo']);
    }
}
