<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Integration;

use ConciliaRH\Core\Config;
use ConciliaRH\Core\Logger;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Conciliation\ConciliationService;
use ConciliaRH\Domain\Imports\FileInspector;
use ConciliaRH\Domain\Imports\ImportException;
use ConciliaRH\Domain\Imports\ImportService;
use ConciliaRH\Domain\Imports\ImportStorage;
use ConciliaRH\Domain\Jobs\JobQueue;
use ConciliaRH\Domain\Lots\LotService;
use ConciliaRH\Domain\Periods\PeriodService;
use ConciliaRH\Domain\Periods\QuincenaService;
use ConciliaRH\Domain\Settings\SettingsService;
use ConciliaRH\Domain\ValidationException;
use ConciliaRH\Tests\Support\DatabaseTestCase;
use ConciliaRH\Tests\Support\ImportFixtures;
use ConciliaRH\Tests\Support\TestUploadMover;

final class QuincenaServiceTest extends DatabaseTestCase
{
    private string $tmp;
    private string $root;
    private CurrentUser $actor;
    private int $periodo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = ImportFixtures::tmpDir();
        $this->root = ImportFixtures::tmpDir();
        $this->actor = $this->actor($this->createUser('operador.qna', ['ADMIN']));
        $admin = $this->actor($this->createUser('admin.qna', ['ADMIN']));
        $this->periodo = (new PeriodService(self::web(), $this->audit($admin)))->create($admin, 2031, 3, 2);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        ImportFixtures::rmTree($this->tmp);
        ImportFixtures::rmTree($this->root);
    }

    private function imports(): ImportService
    {
        $db = self::web();
        $audit = $this->audit($this->actor);
        $settings = new SettingsService($db, $audit);
        return new ImportService($db, $audit, new JobQueue($db, $audit, $settings), $settings, new ImportStorage($this->root), new FileInspector(),
            new TestUploadMover($this->tmp, true), new Config(['upload' => ['max_size' => 26214400]]), new Logger(sys_get_temp_dir()));
    }

    private function service(): QuincenaService
    {
        $db = self::web();
        $audit = $this->audit($this->actor);
        return new QuincenaService($db, new LotService($db, $audit), $this->imports(), new ConciliationService($db, $audit));
    }

    public function testFirstUploadCreatesTheInternalLotAndRecordsTypeOriginAndPopulation(): void
    {
        $svc = $this->service();
        self::assertNull($svc->workingLot($this->periodo));
        $r = $svc->upload($this->actor, $this->periodo, 'PAGOS', 'BOMBEROS', [ImportFixtures::upload('valid.xlsx', $this->tmp, 'pagos bomberos.xlsx')], false);
        self::assertSame(1, $r['cargados']);
        self::assertSame([], $r['errores']);
        $lot = $svc->workingLot($this->periodo);
        self::assertNotNull($lot);
        $a = self::web()->fetchOne(
            'SELECT a.poblacion, t.codigo AS tipo, o.codigo AS origen FROM archivos_importados a JOIN tipos_documento t ON t.id = a.tipo_documento_id
               JOIN origenes_administrativos o ON o.id = a.origen_administrativo_id WHERE a.lote_id = :l', ['l' => (int) $lot['id']]);
        self::assertSame(['poblacion' => 'BOMBEROS', 'tipo' => 'TESORERIA_DISPERSION', 'origen' => 'TESORERIA'], $a);
        $o = $svc->overview($this->periodo);
        self::assertCount(1, $o['celdas']['BOMBEROS']['PAGOS']);
        self::assertSame([], $o['celdas']['POLICIA']['PAGOS']);
        self::assertContains('Nómina de RH de Policías', $o['faltan']);
        self::assertFalse($o['listo']);
    }

    public function testSecondUploadReusesTheSameLot(): void
    {
        $svc = $this->service();
        $svc->upload($this->actor, $this->periodo, 'PAGOS', 'POLICIA', [ImportFixtures::upload('valid.xlsx', $this->tmp, 'a.xlsx')], false);
        $svc->upload($this->actor, $this->periodo, 'PENSIONES', 'POLICIA', [ImportFixtures::upload('valid.xlsm', $this->tmp, 'b.xlsm')], false);
        self::assertSame(1, (int) self::web()->fetchValue('SELECT count(*) FROM lotes_importacion WHERE periodo_id = :p', ['p' => $this->periodo]));
    }

    public function testDuplicateFileInTheSameQuincenaIsReportedPerFileWithoutStoppingTheOthers(): void
    {
        $svc = $this->service();
        $r = $svc->upload($this->actor, $this->periodo, 'PAGOS', 'POLICIA', [ImportFixtures::upload('valid.xlsx', $this->tmp, 'a.xlsx'),
            ImportFixtures::upload('valid-copy.xlsx', $this->tmp, 'copia.xlsx'), ImportFixtures::upload('valid.xls', $this->tmp, 'c.xls')], false);
        self::assertSame(2, $r['cargados']);
        self::assertCount(1, $r['errores']);
        self::assertSame('copia.xlsx', $r['errores'][0]['archivo']);
        self::assertInstanceOf(ImportException::class, $r['errores'][0]['error']);
        self::assertSame('DUPLICADO_EXACTO_EN_LOTE', $r['errores'][0]['error']->errorCode());
    }

    public function testMainSlotsRequireAPopulation(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->upload($this->actor, $this->periodo, 'NOMINA', null, [ImportFixtures::upload('valid.xlsx', $this->tmp)], false);
    }

    public function testUnknownSlotIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->upload($this->actor, $this->periodo, 'CUALQUIERA', 'POLICIA', [ImportFixtures::upload('valid.xlsx', $this->tmp)], false);
    }

    public function testCotejarIsRefusedWhileRequiredFilesAreMissing(): void
    {
        $svc = $this->service();
        $svc->upload($this->actor, $this->periodo, 'PAGOS', 'POLICIA', [ImportFixtures::upload('valid.xlsx', $this->tmp, 'a.xlsx')], false);
        try {
            $svc->cotejar($this->actor, $this->periodo, '');
            self::fail('Debió rechazar el cotejo');
        } catch (ValidationException $e) {
            self::assertStringContainsString('Nómina de RH de Policías', implode(' ', $e->errors()));
        }
    }

    public function testPopulationOfAnOlderFileCanBeSetAndIsAudited(): void
    {
        $svc = $this->service();
        $svc->upload($this->actor, $this->periodo, 'POLIZA_EGRESO', null, [ImportFixtures::upload('valid.xlsx', $this->tmp, 'p.xlsx')], false);
        $id = (int) self::web()->fetchValue('SELECT max(id) FROM archivos_importados');
        $this->imports()->setPopulation($this->actor, $id, 'POLICIA');
        self::assertSame('POLICIA', self::web()->fetchValue('SELECT poblacion FROM archivos_importados WHERE id = :id', ['id' => $id]));
        self::assertSame(1, (int) self::web()->fetchValue("SELECT count(*) FROM auditoria WHERE accion = 'archivos.clasificar' AND entidad_id = :id",
            ['id' => (string) $id]));
        $this->expectException(ValidationException::class);
        $this->imports()->setPopulation($this->actor, $id, 'OTRA');
    }

    public function testDatabaseRejectsAnUnknownPopulation(): void
    {
        $this->service()->upload($this->actor, $this->periodo, 'PAGOS', 'POLICIA', [ImportFixtures::upload('valid.xlsx', $this->tmp, 'a.xlsx')], false);
        $this->expectException(\PDOException::class);
        self::web()->execute("UPDATE archivos_importados SET poblacion = 'TODOS' WHERE id = (SELECT max(id) FROM archivos_importados)");
    }
}
