<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Integration;

use ConciliaRH\Core\HttpException;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Lots\LotService;
use ConciliaRH\Domain\Periods\PeriodService;
use ConciliaRH\Domain\ValidationException;
use ConciliaRH\Tests\Support\DatabaseTestCase;

final class PeriodLotTest extends DatabaseTestCase
{
    private function periods(CurrentUser $a): PeriodService
    {
        return new PeriodService(self::web(), $this->audit($a));
    }

    private function lots(CurrentUser $a): LotService
    {
        return new LotService(self::web(), $this->audit($a));
    }

    public function testCreatePeriodWithDefaultsAndAudit(): void
    {
        $admin = $this->actor($this->createUser('admin.per', ['ADMIN']));
        $id = $this->periods($admin)->create($admin, 2026, 5, 1);
        $p = self::web()->fetchOne('SELECT * FROM periodos_nomina WHERE id = :id', ['id' => $id]);
        self::assertSame(['2026-05-01', '2026-05-15', '1RA QUINCENA MAYO 2026', 'ABIERTO', 'ORDINARIA'],
            [$p['fecha_inicio'], $p['fecha_fin'], $p['descripcion'], $p['estado'], $p['tipo']]);
        self::assertSame(5, $p['mes'], 'El mes se guarda numérico');
        self::assertCount(1, $this->auditRows('periodos.crear'));
        $id2 = $this->periods($admin)->create($admin, 2026, 2, 2);
        self::assertSame('2026-02-28', self::web()->fetchValue('SELECT fecha_fin FROM periodos_nomina WHERE id = :id', ['id' => $id2]));
    }

    public function testDuplicatePeriodIsRejectedButExtraordinaryIsSeparate(): void
    {
        $admin = $this->actor($this->createUser('admin.dup', ['ADMIN']));
        $svc = $this->periods($admin);
        $svc->create($admin, 2026, 6, 2);
        try {
            $svc->create($admin, 2026, 6, 2, 'ORDINARIA', 'Otra descripción');
            self::fail('Periodo duplicado aceptado');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('periodo', $e->errors());
        }
        self::assertGreaterThan(0, $svc->create($admin, 2026, 6, 2, 'EXTRAORDINARIA'));
    }

    public function testDateValidationInServiceAndDatabase(): void
    {
        $admin = $this->actor($this->createUser('admin.fechas', ['ADMIN']));
        $svc = $this->periods($admin);
        foreach ([['2026-07-01', '2026-07-20'], ['2026-06-30', '2026-07-10'], ['2026-07-10', '2026-07-05'], ['x', '2026-07-05']] as [$i, $f]) {
            try {
                $svc->create($admin, 2026, 7, 1, 'ORDINARIA', '', $i, $f);
                self::fail("Fechas aceptadas: $i – $f");
            } catch (ValidationException $e) {
                self::assertArrayHasKey('fechas', $e->errors());
            }
        }
        // La BD también lo impide aunque la aplicación fallara
        $this->assertSqlState('23514', self::web(), fn ($db) => $db->execute(
            "INSERT INTO periodos_nomina (anio, mes, quincena, fecha_inicio, fecha_fin, descripcion, creado_por)
             VALUES (2026, 8, 1, '2026-08-10', '2026-08-25', 'Mal', :u)", ['u' => $admin->id]));
        // Año/mes/quincena son inmutables (sin privilegio de columna)
        $id = $svc->create($admin, 2026, 8, 1);
        $this->assertSqlState('42501', self::web(), fn ($db) => $db->execute('UPDATE periodos_nomina SET mes = 9 WHERE id = :id', ['id' => $id]));
        $this->assertSqlState('42501', self::web(), fn ($db) => $db->execute('UPDATE periodos_nomina SET ultimo_numero_lote = 99 WHERE id = :id', ['id' => $id]));
    }

    public function testEditOnlyWhileOpenAndCloseClosesLots(): void
    {
        $admin = $this->actor($this->createUser('admin.cierre', ['ADMIN']));
        $svc = $this->periods($admin);
        $id = $svc->create($admin, 2026, 9, 1);
        $svc->update($admin, $id, 'Descripción nueva', '2026-09-01', '2026-09-15');
        $l1 = $this->lots($admin)->create($admin, $id);
        $l2 = $this->lots($admin)->create($admin, $id);
        $svc->close($admin, $id, 'Fin de captura');
        $states = array_column(self::web()->fetchAll('SELECT estado FROM lotes_importacion WHERE id IN (:a, :b)', ['a' => $l1, 'b' => $l2]), 'estado');
        self::assertSame(['CERRADO', 'CERRADO'], $states);
        self::assertSame([1, 2], json_decode($this->auditRows('periodos.cerrar')[0]['contexto'], true)['lotes_cerrados']);
        try {
            $svc->update($admin, $id, 'Otra', '2026-09-01', '2026-09-15');
            self::fail('Edición de periodo cerrado');
        } catch (ValidationException) {
        }
        try {
            $this->lots($admin)->create($admin, $id);
            self::fail('Lote en periodo cerrado');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('periodo', $e->errors());
        }
    }

    public function testReopenNeedsPermissionAndReason(): void
    {
        $admin = $this->actor($this->createUser('admin.reab', ['ADMIN']));
        $consulta = $this->actor($this->createUser('consulta.reab', ['CONSULTA']));
        $id = $this->periods($admin)->create($admin, 2026, 10, 2);
        $this->periods($admin)->close($admin, $id);
        try {
            $this->periods($consulta)->reopen($consulta, $id, 'Motivo suficiente');
            self::fail('CONSULTA no debe reabrir');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status());
        }
        try {
            $this->periods($admin)->reopen($admin, $id, 'no');
            self::fail('Motivo obligatorio');
        } catch (ValidationException) {
        }
        $this->periods($admin)->reopen($admin, $id, 'Faltó cargar la dispersión de Bomberos');
        self::assertSame('ABIERTO', self::web()->fetchValue('SELECT estado FROM periodos_nomina WHERE id = :id', ['id' => $id]));
        self::assertSame('Faltó cargar la dispersión de Bomberos', json_decode($this->auditRows('periodos.reabrir')[0]['contexto'], true)['motivo']);
    }

    public function testLotNumberingSequentialAndNamedPerPeriod(): void
    {
        $an = $this->actor($this->createUser('admin.lotes2', ['ADMIN']));
        $admin = $this->actor($this->createUser('admin.lotes', ['ADMIN']));
        $p1 = $this->periods($admin)->create($admin, 2027, 1, 1);
        $p2 = $this->periods($admin)->create($admin, 2027, 1, 2);
        $svc = $this->lots($an);
        $ids = [$svc->create($an, $p1), $svc->create($an, $p1, 'Carpeta Tesorería'), $svc->create($an, $p2)];
        $rows = self::web()->fetchAll('SELECT periodo_id, numero, nombre FROM lotes_importacion WHERE id IN (:a, :b, :c) ORDER BY id',
            ['a' => $ids[0], 'b' => $ids[1], 'c' => $ids[2]]);
        self::assertSame([[$p1, 1, 'Carga 001'], [$p1, 2, 'Carpeta Tesorería'], [$p2, 1, 'Carga 001']],
            array_map(static fn ($r) => [$r['periodo_id'], $r['numero'], $r['nombre']], $rows));
        // La numeración sólo pasa por fn_crear_lote: el rol web no puede insertar lotes directamente (0009)
        $this->assertSqlState('42501', self::web(), fn ($db) => $db->execute(
            "INSERT INTO lotes_importacion (periodo_id, numero, nombre, creado_por) VALUES (:p, 1, 'Duplicado', :u)", ['p' => $p1, 'u' => $an->id]));
    }

    public function testLotLifecycleAndOfficialFlag(): void
    {
        $admin = $this->actor($this->createUser('admin.ofi', ['ADMIN']));
        $consulta = $this->actor($this->createUser('consulta.ofi', ['CONSULTA']));
        $p = $this->periods($admin)->create($admin, 2027, 2, 1);
        $a = $this->lots($admin)->create($admin, $p);
        $b = $this->lots($admin)->create($admin, $p);
        try {
            $this->lots($consulta)->declareOfficial($consulta, $a, 'Motivo suficiente');
            self::fail('CONSULTA no declara el lote oficial');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status());
        }
        $this->lots($admin)->declareOfficial($admin, $a, 'Carpeta validada por Tesorería');
        $this->lots($admin)->declareOfficial($admin, $b, 'La carga 002 corrige totales');
        $oficial = self::web()->fetchAll('SELECT id, declarado_oficial_por FROM lotes_importacion WHERE periodo_id = :p AND es_oficial', ['p' => $p]);
        self::assertSame([$b], array_column($oficial, 'id'), 'Sólo un lote oficial por periodo');
        self::assertSame($admin->id, $oficial[0]['declarado_oficial_por']);
        self::assertCount(2, $this->auditRows('lotes.declarar_oficial'));
        $this->lots($admin)->close($admin, $a);
        try {
            $this->lots($consulta)->reopen($consulta, $a, 'Motivo suficiente');
            self::fail('CONSULTA no reabre lotes');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status());
        }
        $this->lots($admin)->reopen($admin, $a, 'Falta un archivo');
        try {
            $this->lots($consulta)->annul($consulta, $b, 'Carga duplicada por error');
            self::fail('Sin lotes.declarar_oficial no se retira la marca oficial anulando el lote');
        } catch (ValidationException) {
        }
        $this->lots($admin)->annul($admin, $b, 'Carga duplicada por error');
        self::assertSame(['ABIERTO', 'ANULADO', false], array_values(self::web()->fetchOne(
            'SELECT (SELECT estado FROM lotes_importacion WHERE id = :a) AS a, (SELECT estado FROM lotes_importacion WHERE id = :b) AS b,
                    (SELECT es_oficial FROM lotes_importacion WHERE id = :b2) AS o', ['a' => $a, 'b' => $b, 'b2' => $b])));
    }

    public function testAnnulPeriodOnlyWithoutFiles(): void
    {
        $admin = $this->actor($this->createUser('admin.anu', ['ADMIN']));
        $consulta = $this->actor($this->createUser('consulta.anu', ['CONSULTA']));
        $p = $this->periods($admin)->create($admin, 2027, 3, 1);
        $this->lots($admin)->create($admin, $p);
        try {
            $this->periods($consulta)->annul($consulta, $p, 'Motivo suficiente');
            self::fail('CONSULTA no anula periodos');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status());
        }
        $this->periods($admin)->annul($admin, $p, 'Periodo creado por error');
        self::assertSame('ANULADO', self::web()->fetchValue('SELECT estado FROM lotes_importacion WHERE periodo_id = :p', ['p' => $p]));
    }

    /** Matriz funcional: editar los datos de un lote abierto (auditado) y nunca uno cerrado. */
    public function testEditOpenLotOnly(): void
    {
        $admin = $this->actor($this->createUser('admin.edl', ['ADMIN']));
        $p = $this->periods($admin)->create($admin, 2027, 4, 1);
        $lote = $this->lots($admin)->create($admin, $p);
        try {
            $this->lots($admin)->update($admin, $lote, 'ab', '', '', '');
            self::fail('Nombre demasiado corto');
        } catch (ValidationException) {
        }
        $this->lots($admin)->update($admin, $lote, 'Carga de Tesorería', 'Quincena 1', 'Tesorería', 'Sin observaciones');
        self::assertSame(['Carga de Tesorería', 'Quincena 1', 'Tesorería'], array_values(self::web()->fetchOne(
            'SELECT nombre, descripcion, origen FROM lotes_importacion WHERE id = :id', ['id' => $lote])));
        self::assertCount(1, $this->auditRows('lotes.editar'));
        $this->lots($admin)->update($admin, $lote, 'Carga de Tesorería', 'Quincena 1', 'Tesorería', 'Sin observaciones');
        self::assertCount(1, $this->auditRows('lotes.editar'), 'Sin cambios no se audita de nuevo');
        $this->lots($admin)->close($admin, $lote);
        try {
            $this->lots($admin)->update($admin, $lote, 'Otro nombre', '', '', '');
            self::fail('Un lote cerrado no se edita');
        } catch (ValidationException) {
        }
    }
}
