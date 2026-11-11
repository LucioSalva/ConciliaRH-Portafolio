<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Integration;

use ConciliaRH\Core\Config;
use ConciliaRH\Core\Logger;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Imports\FileInspector;
use ConciliaRH\Domain\Imports\ImportException;
use ConciliaRH\Domain\Imports\ImportService;
use ConciliaRH\Domain\Imports\ImportStorage;
use ConciliaRH\Domain\Imports\StorageAuditor;
use ConciliaRH\Domain\Imports\UploadedFile;
use ConciliaRH\Domain\Jobs\JobQueue;
use ConciliaRH\Domain\Lots\LotService;
use ConciliaRH\Domain\Periods\PeriodService;
use ConciliaRH\Domain\Settings\SettingsService;
use ConciliaRH\Domain\ValidationException;
use ConciliaRH\Tests\Support\DatabaseTestCase;
use ConciliaRH\Tests\Support\ImportFixtures;
use ConciliaRH\Tests\Support\TestUploadMover;
use RuntimeException;

final class ImportServiceTest extends DatabaseTestCase
{
    private string $tmp;
    private string $root;
    private CurrentUser $actor;
    private int $lote;
    private int $tipo;
    private int $origen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = ImportFixtures::tmpDir();
        $this->root = ImportFixtures::tmpDir();
        $this->actor = $this->actor($this->createUser('operador.imp', ['ADMIN']));
        $admin = $this->actor($this->createUser('admin.imp', ['ADMIN']));
        $p = (new PeriodService(self::web(), $this->audit($admin)))->create($admin, 2030, 1, 1);
        $this->lote = (new LotService(self::web(), $this->audit($admin)))->create($admin, $p);
        $this->tipo = (int) self::web()->fetchValue("SELECT id FROM tipos_documento WHERE codigo = 'TESORERIA_DISPERSION'");
        $this->origen = (int) self::web()->fetchValue("SELECT id FROM origenes_administrativos WHERE codigo = 'SIN_DEFINIR'");
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        ImportFixtures::rmTree($this->tmp);
        ImportFixtures::rmTree($this->root);
    }

    private function service(?ImportStorage $storage = null, int $maxSize = 26214400, bool $uploaded = true): ImportService
    {
        $db = self::web();
        $audit = $this->audit($this->actor);
        $settings = new SettingsService($db, $audit);
        return new ImportService($db, $audit, new JobQueue($db, $audit, $settings), $settings,
            $storage ?? new ImportStorage($this->root), new FileInspector(), new TestUploadMover($this->tmp, $uploaded),
            new Config(['upload' => ['max_size' => $maxSize]]), new Logger(sys_get_temp_dir()));
    }

    private function upload(string $fixture, ?string $name = null, ?int $lote = null, bool $confirm = false, ?ImportService $svc = null,
                            int $error = UPLOAD_ERR_OK): int
    {
        return ($svc ?? $this->service())->upload($this->actor, $lote ?? $this->lote, $this->tipo, $this->origen, 'Carga de prueba',
            ImportFixtures::upload($fixture, $this->tmp, $name, $error), $confirm);
    }

    private function expectCode(string $code, callable $fn): void
    {
        try {
            $fn();
            self::fail("Se esperaba $code");
        } catch (ImportException $e) {
            self::assertSame($code, $e->errorCode(), $e->getMessage());
        }
    }

    /** @return list<string> archivos fuera de staging dentro del almacenamiento de la prueba */
    private function storedFiles(): array
    {
        $out = [];
        if (!is_dir($this->root . '/imports')) {
            return [];
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/imports', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $out[] = substr($f->getPathname(), strlen($this->root) + 1);
            }
        }
        return $out;
    }

    public function testValidUploadsAreStoredImmutableHashedAndQueued(): void
    {
        foreach (['valid.xlsx' => false, 'valid.xlsm' => true, 'valid.xls' => false] as $fixture => $macros) {
            $id = $this->upload($fixture);
            $a = self::web()->fetchOne('SELECT * FROM archivos_importados WHERE id = :id', ['id' => $id]);
            self::assertSame('RECIBIDO', $a['estado']);
            self::assertSame('PENDIENTE', $a['clasificacion_duplicidad']);
            self::assertSame($macros, $a['tiene_macros']);
            self::assertNull($a['huella_contenido']);
            $abs = $this->root . '/' . $a['ruta_relativa'];
            self::assertSame(hash_file('sha256', ImportFixtures::dir() . '/' . $fixture), $a['sha256']);
            self::assertSame($a['sha256'], hash_file('sha256', $abs));
            self::assertSame('0440', substr(sprintf('%o', fileperms($abs)), -4));
            self::assertStringNotContainsString($fixture, $a['ruta_relativa'], 'El nombre del usuario no forma parte de la ruta');
            $job = self::web()->fetchOne('SELECT tipo, parametros, estado FROM procesos WHERE id = :p', ['p' => $a['proceso_id']]);
            self::assertSame(['VERIFICAR_ARCHIVO', ['archivo_id' => $id], 'PENDIENTE'], [$job['tipo'], json_decode($job['parametros'], true), $job['estado']]);
        }
        self::assertCount(3, $this->auditRows('archivos.cargar'));
        self::assertSame([], glob($this->root . '/imports/.staging/*') ?: [], 'No quedan restos en staging');
    }

    public function testRejectionsLeaveNoRowNoFileAndAreAudited(): void
    {
        $cases = [
            ['fake-extension.xlsx', null, 'FIRMA_INVALIDA'],
            ['valid.xlsx', 'datos.csv', 'EXTENSION_NO_PERMITIDA'],
            ['empty.xlsx', null, 'ARCHIVO_VACIO'],
            ['corrupt.xlsx', null, 'ARCHIVO_CORRUPTO'],
            ['zipbomb.xlsx', null, 'CONTENEDOR_PELIGROSO'],
            ['xxe.xlsx', null, 'CONTENEDOR_PELIGROSO'],
            ['macros-as.xlsx', null, 'EXTENSION_NO_COINCIDE'],
            ['valid.xlsx', "\0\u{202E}", 'NOMBRE_INVALIDO'],
        ];
        foreach ($cases as [$fixture, $name, $code]) {
            $this->expectCode($code, fn () => $this->upload($fixture, $name));
        }
        self::assertSame(0, (int) self::web()->fetchValue('SELECT count(*) FROM archivos_importados WHERE lote_id = :l', ['l' => $this->lote]));
        self::assertSame([], $this->storedFiles());
        $audited = array_map(static fn ($r) => json_decode($r['contexto'], true)['codigo'], $this->auditRows('archivos.rechazar_carga'));
        self::assertSame(array_column($cases, 2), $audited);
    }

    public function testUploadErrorsAndSizeLimit(): void
    {
        $this->expectCode('UPLOAD_DEMASIADO_GRANDE', fn () => $this->upload('valid.xlsx', null, null, false, $this->service(null, 1000)));
        $this->expectCode('UPLOAD_DEMASIADO_GRANDE', fn () => $this->upload('valid.xlsx', null, null, false, null, UPLOAD_ERR_INI_SIZE));
        $this->expectCode('UPLOAD_INCOMPLETO', fn () => $this->upload('valid.xlsx', null, null, false, null, UPLOAD_ERR_PARTIAL));
        $this->expectCode('UPLOAD_SIN_ARCHIVO', fn () => $this->service()->upload($this->actor, $this->lote, $this->tipo, $this->origen, '', null, false));
        // Un archivo que no proviene de una carga HTTP se rechaza
        $this->expectCode('UPLOAD_ERROR_SERVIDOR', fn () => $this->upload('valid.xlsx', null, null, false, $this->service(null, 26214400, false)));
    }

    public function testStrangeNamesAreMetadataOnly(): void
    {
        $id = $this->upload('valid.xlsx', "../../NÓMINA POLICÍA MAYO (1)\u{202E}.xlsx");
        $a = self::web()->fetchOne('SELECT nombre_original, ruta_relativa FROM archivos_importados WHERE id = :id', ['id' => $id]);
        self::assertSame('NÓMINA POLICÍA MAYO (1).xlsx', $a['nombre_original']);
        self::assertMatchesRegularExpression(ImportStorage::RELATIVE_PATTERN, $a['ruta_relativa']);
    }

    public function testExactDuplicates(): void
    {
        $first = $this->upload('valid.xlsx');
        $this->expectCode('DUPLICADO_EXACTO_EN_LOTE', fn () => $this->upload('valid-copy.xlsx'));
        $admin = $this->actor($this->createUser('admin.dup2', ['ADMIN']));
        $periodo = (int) self::web()->fetchValue('SELECT periodo_id FROM lotes_importacion WHERE id = :l', ['l' => $this->lote]);
        $otroLote = (new LotService(self::web(), $this->audit($admin)))->create($admin, $periodo);
        $this->expectCode('DUPLICADO_EXACTO_REQUIERE_CONFIRMACION', fn () => $this->upload('valid.xlsx', null, $otroLote));
        $second = $this->upload('valid.xlsx', null, $otroLote, true);
        $a = self::web()->fetchOne('SELECT clasificacion_duplicidad, archivo_relacionado_id FROM archivos_importados WHERE id = :id', ['id' => $second]);
        self::assertSame(['DUPLICADO_BINARIO', $first], [$a['clasificacion_duplicidad'], $a['archivo_relacionado_id']]);
        // La BD impide el mismo binario dos veces en un lote aunque la aplicación fallara
        self::assertSame(1, (int) self::web()->fetchValue("SELECT count(*) FROM pg_indexes WHERE indexname = 'archivos_lote_sha_uk'"));
    }

    public function testClosedLotAndInvalidClassification(): void
    {
        $this->expectCode('CLASIFICACION_INVALIDA', fn () => $this->service()->upload($this->actor, $this->lote, 999999, $this->origen, '',
            ImportFixtures::upload('valid.xlsx', $this->tmp), false));
        $admin = $this->actor($this->createUser('admin.cerr', ['ADMIN']));
        (new LotService(self::web(), $this->audit($admin)))->close($admin, $this->lote);
        $this->expectCode('LOTE_NO_ABIERTO', fn () => $this->upload('valid.xlsx'));
    }

    public function testStorageFailureRegistersNothing(): void
    {
        $this->expectCode('ALMACENAMIENTO_ERROR', fn () => $this->upload('valid.xlsx', null, null, false, $this->service(new ImportStorage('/proc/no-escribible'))));
        self::assertSame(0, (int) self::web()->fetchValue('SELECT count(*) FROM archivos_importados WHERE lote_id = :l', ['l' => $this->lote]));
    }

    public function testCompensationWhenFailureHappensAfterTheFileWasPlaced(): void
    {
        // Simula un fallo DESPUÉS del rename a la ruta definitiva (dentro de la transacción)
        $failing = new class($this->root) extends ImportStorage {
            public function finalize(string $staged, string $relative): string
            {
                parent::finalize($staged, $relative);
                throw new RuntimeException('fallo simulado posterior al rename');
            }
        };
        $this->expectCode('ALMACENAMIENTO_ERROR', fn () => $this->upload('valid.xlsx', null, null, false, $this->service($failing)));
        self::assertSame(0, (int) self::web()->fetchValue('SELECT count(*) FROM archivos_importados WHERE lote_id = :l', ['l' => $this->lote]));
        self::assertSame([], $this->storedFiles(), 'Ni registro sin archivo ni archivo sin registro');
        self::assertSame(0, (int) self::web()->fetchValue("SELECT count(*) FROM procesos WHERE tipo = 'VERIFICAR_ARCHIVO' AND parametros->>'archivo_id' IS NOT NULL AND created_at > now() - interval '1 minute' AND NOT EXISTS (SELECT 1 FROM archivos_importados a WHERE a.id = (parametros->>'archivo_id')::bigint)"));
    }

    public function testDatabaseStateMachineAndImmutability(): void
    {
        $id = $this->upload('valid.xlsx');
        $web = self::web();
        $this->assertSqlState('23514', $web, fn ($db) => $db->execute("UPDATE archivos_importados SET estado = 'ANALIZADO' WHERE id = :id", ['id' => $id]));
        $this->assertSqlState('42501', $web, fn ($db) => $db->execute("UPDATE archivos_importados SET sha256 = repeat('a', 64) WHERE id = :id", ['id' => $id]));
        $this->assertSqlState('42501', $web, fn ($db) => $db->execute("UPDATE archivos_importados SET ruta_relativa = 'x' WHERE id = :id", ['id' => $id]));
        $this->assertSqlState('42501', $web, fn ($db) => $db->execute('DELETE FROM archivos_importados WHERE id = :id', ['id' => $id]));
        $this->assertSqlState('42501', $this->worker(), fn ($db) => $db->execute("UPDATE archivos_importados SET nombre_original = 'x' WHERE false"));
        // Incluso el propietario choca con el trigger de inmutabilidad (sobre un registro visible para él)
        $o = $this->owner();
        $u = (int) $o->fetchValue("INSERT INTO usuarios (username, nombre, password_hash) VALUES ('owner.inm', 'Owner Inm', repeat('x', 30)) RETURNING id");
        $p = (int) $o->fetchValue("INSERT INTO periodos_nomina (anio, mes, quincena, fecha_inicio, fecha_fin, descripcion, creado_por) VALUES (2031, 1, 1, '2031-01-01', '2031-01-15', 'Inmutable', :u) RETURNING id", ['u' => $u]);
        $l = (int) $o->fetchValue("SELECT id FROM fn_crear_lote(:p, '', '', '', '', :u)", ['p' => $p, 'u' => $u]);
        $t = (int) $o->fetchValue("SELECT id FROM tipos_documento WHERE codigo = 'OTRO'");
        $aid = (int) $o->fetchValue("INSERT INTO archivos_importados (lote_id, tipo_documento_id, origen_administrativo_id, nombre_original, extension, mime_detectado, firma, tamano_bytes, sha256, ruta_relativa, usuario_carga_id)
            VALUES (:l, :t, :o, 'x.xlsx', 'xlsx', 'application/zip', 'OOXML', 10, repeat('a', 64), 'imports/1/1/00000000-0000-4000-8000-000000000000/original.xlsx', :u) RETURNING id",
            ['l' => $l, 't' => $t, 'o' => $this->origen, 'u' => $u]);
        $this->assertSqlState('23514', $o, fn ($db) => $db->execute('UPDATE archivos_importados SET tamano_bytes = 1 WHERE id = :id', ['id' => $aid]));
        $this->assertSqlState('23514', $o, fn ($db) => $db->execute("UPDATE archivos_importados SET sha256 = repeat('b', 64) WHERE id = :id", ['id' => $aid]));
        $this->assertSqlState('23514', $o, fn ($db) => $db->execute("INSERT INTO archivos_importados (lote_id, tipo_documento_id, origen_administrativo_id, nombre_original, extension, mime_detectado, firma, tamano_bytes, sha256, ruta_relativa, usuario_carga_id, estado)
            VALUES (:l, :t, :o, 'y.xlsx', 'xlsx', 'application/zip', 'OOXML', 10, repeat('c', 64), 'imports/1/1/00000000-0000-4000-8000-000000000001/original.xlsx', :u, 'VERIFICADO')",
            ['l' => $l, 't' => $t, 'o' => $this->origen, 'u' => $u]));
        // Los resultados de verificación/análisis sólo los fija el motor (0009), nunca el rol web
        foreach (['VERIFICANDO', 'ERROR'] as $estado) {
            $this->assertSqlState('42501', $web, fn ($db) => $db->execute('UPDATE archivos_importados SET estado = :e WHERE id = :id', ['e' => $estado, 'id' => $id]));
        }
        // Transiciones válidas para el motor; volver atrás desde VERIFICADO es inválido
        $o->execute("UPDATE archivos_importados SET estado = 'VERIFICANDO' WHERE id = :id", ['id' => $aid]);
        $o->execute("UPDATE archivos_importados SET estado = 'VERIFICADO' WHERE id = :id", ['id' => $aid]);
        $this->assertSqlState('23514', $o, fn ($db) => $db->execute("UPDATE archivos_importados SET estado = 'RECIBIDO' WHERE id = :id", ['id' => $aid]));
    }

    public function testReclassifyReprocessAnnulAndJobSync(): void
    {
        $svc = $this->service();
        $id = $this->upload('valid.xlsx');
        $state = fn (): string => (string) self::web()->fetchValue('SELECT estado FROM archivos_importados WHERE id = :id', ['id' => $id]);
        $otroTipo = (int) self::web()->fetchValue("SELECT id FROM tipos_documento WHERE codigo = 'TESORERIA_PENSION'");
        $svc->reclassify($this->actor, $id, $otroTipo, $this->origen);
        self::assertSame($otroTipo, self::web()->fetchValue('SELECT tipo_documento_id FROM archivos_importados WHERE id = :id', ['id' => $id]));
        self::assertCount(1, $this->auditRows('archivos.clasificar'));
        $this->expectCode('ESTADO_INVALIDO', fn () => $svc->reprocess($this->actor, $id));

        // Si el trabajo de verificación se cancela (p. ej. desde /procesos), el archivo pasa a ERROR
        // VERIFICACION_CANCELADA (trigger de sincronización) y puede reprocesarse: nunca queda atascado.
        $job = (int) self::web()->fetchValue('SELECT proceso_id FROM archivos_importados WHERE id = :id', ['id' => $id]);
        self::web()->execute("UPDATE procesos SET estado = 'CANCELADO', finalizado_at = now() WHERE id = :p", ['p' => $job]);
        self::assertSame('ERROR', $state());
        self::assertSame('VERIFICACION_CANCELADA', self::web()->fetchValue('SELECT error_codigo FROM archivos_importados WHERE id = :id', ['id' => $id]));

        // Un archivo en ERROR puede reprocesarse: nuevo trabajo y vuelta a RECIBIDO
        $jobId = $svc->reprocess($this->actor, $id);
        self::assertNotSame($job, $jobId);
        self::assertSame('RECIBIDO', $state());

        // Anular cancela el trabajo pendiente y conserva el original
        $svc->annul($this->actor, $id, 'Archivo equivocado');
        $row = self::web()->fetchOne('SELECT a.estado, p.estado AS job FROM archivos_importados a JOIN procesos p ON p.id = a.proceso_id WHERE a.id = :id', ['id' => $id]);
        self::assertSame(['ANULADO', 'CANCELADO'], [$row['estado'], $row['job']]);
        self::assertNotEmpty($this->storedFiles(), 'El original se conserva tras anular');
        $this->expectCode('ESTADO_INVALIDO', fn () => $svc->annul($this->actor, $id, 'Otra vez'));
    }

    public function testDownloadVerifiesIntegrityAndIsAudited(): void
    {
        $svc = $this->service();
        $id = $this->upload('valid.xls', 'NÓMINA BOMBEROS.xls');
        $d = $svc->prepareDownload($this->actor, $id);
        self::assertSame('NÓMINA BOMBEROS.xls', $d['nombre']);
        self::assertFileEquals(ImportFixtures::dir() . '/valid.xls', $d['path']);
        self::assertCount(1, $this->auditRows('archivos.descargar_original'));
        chmod($d['path'], 0640);
        file_put_contents($d['path'], 'alterado', FILE_APPEND);
        $this->expectCode('INTEGRIDAD_ERROR', fn () => $svc->prepareDownload($this->actor, $id));
        self::assertCount(1, $this->auditRows('archivos.integridad_fallida'));
    }

    public function testStorageAuditorFindsOrphansStagingAndMissing(): void
    {
        $id = $this->upload('valid.xlsx');
        $rel = (string) self::web()->fetchValue('SELECT ruta_relativa FROM archivos_importados WHERE id = :id', ['id' => $id]);
        $storage = new ImportStorage($this->root);
        // huérfano: archivo con forma válida, sin registro y con antigüedad mayor a la mínima
        $orphanRel = ImportStorage::relativePath(1, 1, ImportStorage::uuid(), 'xlsx');
        mkdir(dirname($this->root . '/' . $orphanRel), 0750, true);
        file_put_contents($this->root . '/' . $orphanRel, 'x');
        touch($this->root . '/' . $orphanRel, time() - 7200);
        // reciente sin registro: puede ser una carga aún sin COMMIT → no es huérfano todavía
        $recentRel = ImportStorage::relativePath(1, 1, ImportStorage::uuid(), 'xlsx');
        mkdir(dirname($this->root . '/' . $recentRel), 0750, true);
        file_put_contents($this->root . '/' . $recentRel, 'x');
        // staging vencido
        @mkdir($this->root . '/imports/.staging', 0750, true);
        touch($this->root . '/imports/.staging/viejo.part', time() - 7200);
        $auditor = new StorageAuditor(self::web(), $storage);
        $r = $auditor->audit();
        self::assertContains($orphanRel, $r['huerfanos']);
        self::assertNotContains($recentRel, $r['huerfanos'], 'Un archivo reciente sin registro no se trata como huérfano');
        self::assertSame(['imports/.staging/viejo.part'], $r['staging']);
        self::assertSame([], array_filter($r['faltantes'], static fn ($f) => $f['id'] === $id));
        chmod($this->root . '/' . $rel, 0640);
        unlink($this->root . '/' . $rel);
        self::assertContains(['id' => $id, 'problema' => 'ORIGINAL_AUSENTE'], $auditor->audit()['faltantes']);
        // La cuarentena re-verifica contra la BD: nunca aparta un original registrado
        $other = $this->upload('valid.xls');
        $otherRel = (string) self::web()->fetchValue('SELECT ruta_relativa FROM archivos_importados WHERE id = :id', ['id' => $other]);
        $dir = $auditor->quarantine([$orphanRel, $otherRel]);
        self::assertFileExists($dir . '/' . $orphanRel);
        self::assertFileDoesNotExist($this->root . '/' . $orphanRel);
        self::assertFileExists($this->root . '/' . $otherRel);
    }

    public function testLotWithActiveFilesCannotBeAnnulled(): void
    {
        $id = $this->upload('valid.xlsx');
        $admin = $this->actor($this->createUser('admin.anl', ['ADMIN']));
        $lots = new LotService(self::web(), $this->audit($admin));
        try {
            $lots->annul($admin, $this->lote, 'Motivo suficiente');
            self::fail('Un lote con archivos vigentes no se anula');
        } catch (ValidationException) {
        }
        $this->service()->annul($this->actor, $id, 'Archivo equivocado');
        $this->actingAs($admin);             // cada petición real tiene un único actor
        $lots->annul($admin, $this->lote, 'Motivo suficiente');
        self::assertSame('ANULADO', self::web()->fetchValue('SELECT estado FROM lotes_importacion WHERE id = :id', ['id' => $this->lote]));
    }

    public function testExactDuplicatePrefersSameLotAndIgnoresAnnulledLots(): void
    {
        $admin = $this->actor($this->createUser('admin.dup2', ['ADMIN']));
        $periodo = (int) self::web()->fetchValue('SELECT periodo_id FROM lotes_importacion WHERE id = :id', ['id' => $this->lote]);
        $otro = (new LotService(self::web(), $this->audit($admin)))->create($admin, $periodo);
        $this->upload('valid.xlsx');
        $this->upload('valid.xlsx', null, $otro, true);
        // Tercer intento en el lote 2: se informa el duplicado del MISMO lote, no se pide confirmación por el lote 1
        $this->expectCode('DUPLICADO_EXACTO_EN_LOTE', fn () => $this->upload('valid.xlsx', null, $otro, true));
    }

    public function testClosedLotBlocksReclassifyAndReprocess(): void
    {
        $id = $this->upload('valid.xlsx');
        $admin = $this->actor($this->createUser('admin.cer', ['ADMIN']));
        (new LotService(self::web(), $this->audit($admin)))->close($admin, $this->lote);
        $otroTipo = (int) self::web()->fetchValue("SELECT id FROM tipos_documento WHERE codigo = 'TESORERIA_PENSION'");
        $this->expectCode('LOTE_NO_ABIERTO', fn () => $this->service()->reclassify($this->actor, $id, $otroTipo, $this->origen));
    }

    public function testDownloadRequiresMacroConfirmationAndNeverServesRejected(): void
    {
        $svc = $this->service();
        $id = $this->upload('valid.xlsm');
        $this->expectCode('MACROS_REQUIERE_CONFIRMACION', fn () => $svc->prepareDownload($this->actor, $id));
        self::assertCount(0, $this->auditRows('archivos.descargar_original'));
        $svc->prepareDownload($this->actor, $id, true);
        self::assertCount(1, $this->auditRows('archivos.descargar_original'));

        // RECHAZADO sólo lo fija el motor: se prepara con el propietario (confirmado y limpiado al final)
        $rejected = $this->committedRejectedFile();
        $this->expectCode('ESTADO_INVALIDO', fn () => $svc->prepareDownload($this->actor, $rejected, true));
    }

    private function committedRejectedFile(): int
    {
        $ids = [];
        $o = $this->committedOwner(static function ($db) use (&$ids): void {
            $db->execute('DELETE FROM archivos_importados WHERE id = :a', ['a' => $ids['a'] ?? 0]);
            $db->execute('DELETE FROM lotes_importacion WHERE id = :l', ['l' => $ids['l'] ?? 0]);
            $db->execute('DELETE FROM periodos_nomina WHERE id = :p', ['p' => $ids['p'] ?? 0]);
            $db->execute('DELETE FROM usuarios WHERE id = :u', ['u' => $ids['u'] ?? 0]);
        });
        $ids['u'] = (int) $o->fetchValue("INSERT INTO usuarios (username, nombre, password_hash) VALUES ('owner.rech', 'Owner Rech', repeat('x', 30)) RETURNING id");
        $ids['p'] = (int) $o->fetchValue("INSERT INTO periodos_nomina (anio, mes, quincena, fecha_inicio, fecha_fin, descripcion, creado_por) VALUES (2099, 12, 2, '2099-12-16', '2099-12-31', 'Rechazo', :u) RETURNING id", ['u' => $ids['u']]);
        $ids['l'] = (int) $o->fetchValue("SELECT id FROM fn_crear_lote(:p, '', '', '', '', :u)", ['p' => $ids['p'], 'u' => $ids['u']]);
        $ids['a'] = (int) $o->fetchValue("INSERT INTO archivos_importados (lote_id, tipo_documento_id, origen_administrativo_id, nombre_original, extension, mime_detectado, firma, tamano_bytes, sha256, ruta_relativa, usuario_carga_id)
            VALUES (:l, :t, :o, 'bomba.xlsx', 'xlsx', 'application/zip', 'OOXML', 10, repeat('d', 64), 'imports/9/9/00000000-0000-4000-8000-000000000009/original.xlsx', :u) RETURNING id",
            ['l' => $ids['l'], 't' => $this->tipo, 'o' => $this->origen, 'u' => $ids['u']]);
        $o->execute("UPDATE archivos_importados SET estado = 'VERIFICANDO' WHERE id = :a", ['a' => $ids['a']]);
        $o->execute("UPDATE archivos_importados SET estado = 'RECHAZADO', error_codigo = 'CONTENEDOR_PELIGROSO' WHERE id = :a", ['a' => $ids['a']]);
        return $ids['a'];
    }
}
