<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Integration;

use ConciliaRH\Core\Config;
use ConciliaRH\Core\Logger;
use ConciliaRH\Domain\Analysis\AnalysisService;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Imports\FileInspector;
use ConciliaRH\Domain\Imports\ImportException;
use ConciliaRH\Domain\Imports\ImportService;
use ConciliaRH\Domain\Imports\ImportStorage;
use ConciliaRH\Domain\Jobs\JobQueue;
use ConciliaRH\Domain\Lots\LotService;
use ConciliaRH\Domain\Periods\PeriodService;
use ConciliaRH\Domain\Settings\SettingsService;
use ConciliaRH\Domain\ValidationException;
use ConciliaRH\Tests\Support\AnalysisScenario;
use ConciliaRH\Tests\Support\DatabaseTestCase;
use ConciliaRH\Tests\Support\ImportFixtures;
use ConciliaRH\Tests\Support\TestUploadMover;

final class AnalysisServiceTest extends DatabaseTestCase
{
    use AnalysisScenario;

    private CurrentUser $operador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operador = $this->actor($this->createUser('operador.an', ['ADMIN']));
    }

    private function svc(?CurrentUser $actor = null): AnalysisService
    {
        $db = self::web();
        $audit = $this->audit($actor ?? $this->operador);
        $settings = new SettingsService($db, $audit);
        return new AnalysisService($db, $audit, new JobQueue($db, $audit, $settings), $settings);
    }

    private function file(int $id): array
    {
        return self::web()->fetchOne('SELECT * FROM archivos_importados WHERE id = :id', ['id' => $id]);
    }

    private function expectImport(string $code, callable $fn): void
    {
        try {
            $fn();
            self::fail("Se esperaba $code");
        } catch (ImportException $e) {
            self::assertSame($code, $e->errorCode());
        }
    }

    public function testWebRoleCannotWriteEngineResults(): void
    {
        $s = $this->analyzedFile();
        $web = self::web();
        $this->assertSqlState('42501', $web, fn ($db) => $db->execute("INSERT INTO analisis_archivo (archivo_id, numero, resultado, motor_version, reglas_version, perfil_confianza, perfil_origen, hoja_origen, total_hojas) VALUES (1, 9, 'ANALIZADO', 'x', 1, 'ALTA', 'MANUAL', 'MANUAL', 1)"));
        $this->assertSqlState('42501', $web, fn ($db) => $db->execute('UPDATE controles_archivo SET estado = :e WHERE archivo_id = :a', ['e' => 'CUADRA', 'a' => $s['archivo']]));
        $this->assertSqlState('42501', $web, fn ($db) => $db->execute('DELETE FROM staging_registros WHERE archivo_id = :a', ['a' => $s['archivo']]));
        // `datos` (nombres, RFC, CURP) y el HMAC de cuentas no son legibles por el rol web; los hashes sí (comparación)
        $this->assertSqlState('42501', $web, fn ($db) => $db->fetchAll('SELECT datos FROM staging_registros WHERE archivo_id = :a', ['a' => $s['archivo']]));
        $this->assertSqlState('42501', $web, fn ($db) => $db->fetchAll('SELECT cuenta_hmac FROM staging_registros WHERE archivo_id = :a', ['a' => $s['archivo']]));
        self::assertSame(3, (int) $web->fetchValue('SELECT count(hash_registro) FROM staging_registros WHERE archivo_id = :a', ['a' => $s['archivo']]));
        $this->assertSqlState('42501', $web, fn ($db) => $db->execute('UPDATE archivos_importados SET huella_contenido = NULL WHERE id = :a', ['a' => $s['archivo']]));
        // Transición válida pero reservada al motor → 42501; transición inválida → 23514 (se valida primero)
        $this->assertSqlState('42501', $web, fn ($db) => $db->execute("UPDATE archivos_importados SET estado = 'ANALIZANDO' WHERE id = :a", ['a' => $s['archivo']]));
        $this->assertSqlState('23514', $web, fn ($db) => $db->execute("UPDATE archivos_importados SET estado = 'REVISION_MANUAL' WHERE id = :a", ['a' => $s['archivo']]));
    }

    public function testManualSelectionTriggerInvalidatesFingerprintOnlyInAnalyzableStates(): void
    {
        $s = $this->analyzedFile();
        self::assertNotNull($this->file($s['archivo'])['huella_contenido']);
        self::web()->execute('UPDATE archivos_importados SET hoja_manual_indice = 1, hoja_manual_nombre = :n WHERE id = :id', ['n' => 'Version B', 'id' => $s['archivo']]);
        $f = $this->file($s['archivo']);
        self::assertSame([null, null, 'PENDIENTE'], [$f['huella_contenido'], $f['huella_version'], $f['clasificacion_duplicidad']]);
        // En un archivo recién recibido (no analizable) la selección manual se rechaza en la BD
        $admin = $this->actor($this->createUser('admin.an', ['ADMIN']));
        $periodo = (new PeriodService(self::web(), $this->audit($admin)))->create($admin, 2033, 5, 1);
        $lote = (new LotService(self::web(), $this->audit($admin)))->create($admin, $periodo);
        $tmp = ImportFixtures::tmpDir();
        $root = ImportFixtures::tmpDir();
        try {
            $db = self::web();
            $audit = $this->audit($admin);
            $settings = new SettingsService($db, $audit);
            $imports = new ImportService($db, $audit, new JobQueue($db, $audit, $settings), $settings, new ImportStorage($root), new FileInspector(),
                new TestUploadMover($tmp, true), new Config(['upload' => ['max_size' => 26214400]]), new Logger(sys_get_temp_dir()));
            $tipo = (int) $db->fetchValue("SELECT id FROM tipos_documento WHERE codigo = 'TESORERIA_DISPERSION'");
            $origen = (int) $db->fetchValue("SELECT id FROM origenes_administrativos WHERE codigo = 'SIN_DEFINIR'");
            $id = $imports->upload($admin, $lote, $tipo, $origen, '', ImportFixtures::upload('valid.xlsx', $tmp), false);
            $this->assertSqlState('23514', $db, fn ($d) => $d->execute('UPDATE archivos_importados SET hoja_manual_indice = 0, hoja_manual_nombre = :n WHERE id = :id', ['n' => 'x', 'id' => $id]));
        } finally {
            ImportFixtures::rmTree($tmp);
            ImportFixtures::rmTree($root);
        }
    }

    public function testReadModel(): void
    {
        $s = $this->analyzedFile();
        $svc = $this->svc();
        $an = $svc->current($s['archivo']);
        self::assertSame(['ANALIZADO', 'TESORERIA_BBVA', 'ALTA'], [$an['resultado'], $an['perfil_codigo'], $an['perfil_confianza']]);
        self::assertCount(2, $svc->sheets((int) $an['id']));
        self::assertSame(['NUM', 'NOMBRE', 'IMPORTE'], array_column($svc->columns($s['hojas'][0]), 'encabezado_normalizado'));
        $c = $svc->controls((int) $an['id']);
        self::assertSame(['TOTAL_IMPORTE', 'CUADRA', '3000.50', 'Version A'], [$c[0]['codigo'], $c[0]['estado'], $c[0]['valor_declarado'], $c[0]['hoja_nombre']]);
        self::assertCount(1, $svc->history($s['archivo']));
        self::assertNotContains('RRHH_TABULADO', array_column($svc->selectableProfiles(), 'codigo'), 'El genérico sustituido no es elegible');
        self::assertContains('RRHH_TABULADO_BOMBEROS', array_column($svc->selectableProfiles(), 'codigo'));
    }

    public function testReanalyzeEnqueuesOnceAndIsAudited(): void
    {
        $s = $this->analyzedFile();
        $svc = $this->svc();
        $job = $svc->reanalyze($this->operador, $s['archivo']);
        $row = self::web()->fetchOne('SELECT tipo, estado, parametros->>\'archivo_id\' AS a FROM procesos WHERE id = :id', ['id' => $job]);
        self::assertSame(['ANALIZAR_ARCHIVO', 'PENDIENTE', (string) $s['archivo']], [$row['tipo'], $row['estado'], $row['a']]);
        self::assertSame($job, (int) $this->file($s['archivo'])['proceso_id']);
        self::assertCount(1, $this->auditRows('analisis.reprocesar'));
        $this->expectImport('ESTADO_INVALIDO', fn () => $svc->reanalyze($this->operador, $s['archivo']));
        // Aunque el trabajo termine, hay una espera mínima entre análisis manuales del mismo archivo
        self::web()->execute("UPDATE procesos SET estado = 'CANCELADO', finalizado_at = now() WHERE id = :id", ['id' => $job]);
        try {
            $svc->reanalyze($this->operador, $s['archivo']);
            self::fail('Se esperaba la espera mínima');
        } catch (ImportException $e) {
            self::assertStringContainsString('Espere', $e->getMessage());
        }
    }

    public function testSelectSheetValidatesAuditsAndReanalyzes(): void
    {
        $s = $this->analyzedFile(['estado' => 'REVISION_MANUAL']);
        $svc = $this->svc();
        try {
            $svc->selectSheet($this->operador, $s['archivo'], 7, 'Motivo suficiente');
            self::fail('Hoja inexistente');
        } catch (ValidationException) {
        }
        try {
            $svc->selectSheet($this->operador, $s['archivo'], 1, 'x');
            self::fail('Motivo obligatorio');
        } catch (ValidationException) {
        }
        $job = $svc->selectSheet($this->operador, $s['archivo'], 1, 'La versión B es la entregada por Tesorería');
        $f = $this->file($s['archivo']);
        self::assertSame([1, 'Version B', $job, $this->operador->id], [(int) $f['hoja_manual_indice'], $f['hoja_manual_nombre'], (int) $f['proceso_id'], (int) $f['seleccion_manual_por']]);
        $audit = $this->auditRows('analisis.seleccionar_hoja')[0];
        $ctx = json_decode((string) $audit['contexto'], true);
        self::assertSame('La versión B es la entregada por Tesorería', $ctx['motivo']);
        self::assertSame('Version B', json_decode((string) $audit['valor_nuevo'], true)['hoja_nombre']);
    }

    public function testSelectProfileOnlyActiveImplemented(): void
    {
        $s = $this->analyzedFile(['estado' => 'REVISION_MANUAL']);
        $svc = $this->svc();
        $generic = (int) self::web()->fetchValue("SELECT id FROM perfiles_importacion WHERE codigo = 'RRHH_TABULADO'");
        try {
            $svc->selectProfile($this->operador, $s['archivo'], $generic, 'Motivo suficiente');
            self::fail('El perfil genérico está inactivo');
        } catch (ValidationException) {
        }
        $p = (int) self::web()->fetchValue("SELECT id FROM perfiles_importacion WHERE codigo = 'TESORERIA_PENSION_BBVA'");
        $svc->selectProfile($this->operador, $s['archivo'], $p, 'Es un archivo de pensiones');
        self::assertSame($p, (int) $this->file($s['archivo'])['perfil_manual_id']);
        self::assertCount(1, $this->auditRows('analisis.seleccionar_perfil'));
        // volver a detección automática: primero debe terminar el análisis en curso
        $this->expectImport('ESTADO_INVALIDO', fn () => $svc->selectProfile($this->operador, $s['archivo'], null, 'Volver a automático'));
    }

    public function testClosedLotBlocksManualDecisions(): void
    {
        $s = $this->analyzedFile(['lote_estado' => 'CERRADO']);
        $this->expectImport('LOTE_NO_ABIERTO', fn () => $this->svc()->reanalyze($this->operador, $s['archivo']));
    }

    public function testCompareCountsCommonRecordsWithoutPersonData(): void
    {
        $a = $this->analyzedFile(['staging' => ['r1', 'r2', 'r3']]);
        $b = $this->analyzedFile(['staging' => ['r1', 'r2', 'r9'], 'huella' => hash('sha256', 'otro'), 'lote' => null]);
        $cmp = $this->svc()->compare($a['archivo'], $b['archivo']);
        self::assertFalse($cmp['mismo_sha']);
        self::assertFalse($cmp['misma_huella']);
        self::assertSame(['total_a' => 3, 'total_b' => 3, 'comunes' => 2, 'claves_comunes' => 0], array_map('intval', $cmp['registros']));
        self::assertContains('TOTAL_IMPORTE#0', $cmp['codigos_control']);
        self::assertStringNotContainsString('PERSONA FICTICIA SECRETA', json_encode($cmp, JSON_UNESCAPED_UNICODE));
        try {
            $this->svc()->compare($a['archivo'], $a['archivo']);
            self::fail('Mismo archivo');
        } catch (ValidationException) {
        }
    }
}
