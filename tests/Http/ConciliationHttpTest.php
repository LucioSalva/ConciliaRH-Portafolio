<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Http;

use ConciliaRH\Domain\Conciliation\Exporter;
use ConciliaRH\Tests\Support\ConciliationScenario;
use ConciliaRH\Tests\Support\HttpTestCase;

final class ConciliationHttpTest extends HttpTestCase
{
    use ConciliationScenario;

    private function as(string $role): int
    {
        $user = strtolower($role) . '.conc' . bin2hex(random_bytes(2));
        $id = $this->createUser($user, [$role]);
        $this->loginAs($user);
        return $id;
    }

    public function testDashboardAndConciliationPagesShowHonestIndicators(): void
    {
        $s = $this->conciliationScenario();
        $this->as('CONSULTA');
        $home = $this->get('/')->body();
        foreach (['RH autorizó', 'Total pagado', 'Diferencia', '% conciliado', 'Por atender', '17,000.00', '17,734.56', '734.56', '50.00 %', 'No oficial'] as $t) {
            self::assertStringContainsString($t, $home, $t);
        }
        self::assertStringContainsString('Pendiente de identificar', $home);
        self::assertStringContainsString('<strong>$1,234.56</strong> aún no tiene persona asignada', $home, 'Lo pendiente se muestra aparte');
        self::assertStringContainsString('diff--over', $home, 'La diferencia global lleva signo, icono y texto');
        $page = $this->get('/conciliaciones/' . $s['conc'])->body();
        self::assertStringContainsString('No oficial: resultado provisional', $page);
        self::assertStringNotContainsString('/cerrar"', $page, 'CONSULTA no cierra');
        self::assertStringNotContainsString('/exportar"', $page, 'CONSULTA no exporta');
        $list = $this->get('/conciliaciones/' . $s['conc'] . '/empleados?estado=PAGO_MENOR')->body();
        self::assertStringContainsString('90001', $list);
        self::assertStringNotContainsString('90002', $list);
        self::assertStringContainsString('−$500.00<span class="visually-hidden"> (pagado de menos)</span>', $list, 'Diferencia con signo y texto, no sólo color');
        $d = $this->get('/conciliaciones/' . $s['conc'] . '/empleados/' . $s['emp1'])->body();
        self::assertStringContainsString('pagado − esperado', $d);
        self::assertStringContainsString('TABULADO FICTICIO.xlsx', $d);
        self::assertStringNotContainsString(self::fakeRfc(1), $d);
        self::assertStringNotContainsString('90001', $this->get('/conciliaciones/' . $s['conc'] . '/empleados?q=' . self::fakeRfc(1))->body());
        $api = json_decode($this->get('/api/conciliaciones/' . $s['conc'])->body(), true);
        self::assertSame('COMPLETADA_CON_INCIDENCIAS', $api['data']['conciliacion']['estado']);
        self::assertStringNotContainsString('FICTICIA', json_encode($api), 'La API de resumen no expone personas');
    }

    public function testPermissionsMatrix(): void
    {
        $s = $this->conciliationScenario();
        $c = $s['conc'];
        $this->as('CONSULTA');
        self::assertSame(403, $this->post('/conciliaciones', ['lote_id' => (string) $s['lote']])->status());
        self::assertSame(403, $this->post("/conciliaciones/$c/incidencias/{$s['inc_menor']}", ['estado' => 'RESUELTA', 'motivo' => 'Revisado con Tesorería'])->status());
        self::assertSame(403, $this->post("/conciliaciones/$c/exportar", ['formato' => 'csv'])->status());
        self::assertSame(403, $this->post("/conciliaciones/$c/cerrar", ['motivo' => 'Cierre de prueba'])->status());
        self::assertSame(403, $this->post("/conciliaciones/$c/invalidar", ['motivo' => 'Invalidar prueba'])->status());
        self::assertSame('COMPLETADA_CON_INCIDENCIAS', self::web()->fetchValue('SELECT estado FROM conciliaciones WHERE id = :id', ['id' => $c]));
    }

    public function testResolvingAnIncidentNeverChangesMoneyAndIsAudited(): void
    {
        $s = $this->conciliationScenario();
        $c = $s['conc'];
        $uid = $this->as('ADMIN');
        $r = $this->post("/conciliaciones/$c/incidencias/{$s['inc_menor']}", ['estado' => 'ACEPTADA_JUSTIFICADA', 'motivo' => 'Descuento autorizado por oficio', 'evidencia' => 'Oficio FICT-1']);
        self::assertSame(303, $r->status());
        $i = self::web()->fetchOne('SELECT * FROM conciliacion_incidencias WHERE id = :id', ['id' => $s['inc_menor']]);
        self::assertSame(['ACEPTADA_JUSTIFICADA', $uid, '-500.00'], [$i['estado'], (int) $i['actualizado_por'], $i['importe']]);
        self::assertSame('-500.00', self::web()->fetchValue('SELECT diferencia FROM conciliacion_empleados WHERE conciliacion_id = :c AND empleado_id = :e',
            ['c' => $c, 'e' => $s['emp1']]), 'Resolver ≠ cambiar dinero');
        $h = self::web()->fetchAll('SELECT * FROM conciliacion_incidencias_historial WHERE incidencia_id = :i', ['i' => $s['inc_menor']]);
        self::assertSame([['ABIERTA', 'ACEPTADA_JUSTIFICADA', $uid]], array_map(static fn ($x) => [$x['estado_anterior'], $x['estado_nuevo'], (int) $x['usuario_id']], $h));
        self::assertCount(1, $this->auditRows('incidencias.justificar'));
        self::assertSame(404, $this->post('/conciliaciones/' . ($c + 999) . "/incidencias/{$s['inc_menor']}", ['estado' => 'RESUELTA', 'motivo' => 'Otro intento'])->status());
        $this->post("/conciliaciones/$c/incidencias/{$s['inc_pend']}", ['estado' => 'RESUELTA', 'motivo' => '']);
        self::assertSame('ABIERTA', self::web()->fetchValue('SELECT estado FROM conciliacion_incidencias WHERE id = :id', ['id' => $s['inc_pend']]));
        $this->assertSqlState('42501', self::web(), fn () => self::web()->execute('UPDATE conciliacion_incidencias SET importe = 0 WHERE id = :id', ['id' => $s['inc_pend']]));
    }

    public function testCloseFreezesAndInvalidateKeepsHistory(): void
    {
        $s = $this->conciliationScenario();
        $c = $s['conc'];
        $admin = $this->as('ADMIN');
        self::assertSame(303, $this->post("/conciliaciones/$c/cerrar", ['motivo' => 'Revisión concluida'])->status());
        $row = self::web()->fetchOne('SELECT estado, cerrada_por, motivo_cierre FROM conciliaciones WHERE id = :id', ['id' => $c]);
        self::assertSame(['CERRADA', 'Revisión concluida'], [$row['estado'], $row['motivo_cierre']]);
        $a = $this->auditRows('conciliaciones.cerrar');
        self::assertCount(1, $a);
        self::assertStringContainsString('"ABIERTA": 2', (string) $a[0]['contexto']);
        $this->post("/conciliaciones/$c/incidencias/{$s['inc_menor']}", ['estado' => 'RESUELTA', 'motivo' => 'Después del cierre']);
        self::assertSame('ABIERTA', self::web()->fetchValue('SELECT estado FROM conciliacion_incidencias WHERE id = :id', ['id' => $s['inc_menor']]));
        $this->assertSqlState('23514', self::web(), fn () => self::web()->execute(
            "UPDATE conciliacion_incidencias SET estado = 'RESUELTA' WHERE id = :id", ['id' => $s['inc_menor']]));
        self::assertSame(403, $this->post("/conciliaciones/$c/invalidar", ['motivo' => 'Error de captura'])->status());
        $this->assertSqlState('42501', self::web(), fn () => self::web()->execute(
            "UPDATE conciliaciones SET estado = 'INVALIDADA', invalidada_por = :u, invalidada_at = now(), motivo_invalidacion = 'x' WHERE id = :id",
            ['u' => $admin, 'id' => $c]));
        $this->as('GOD');
        self::assertSame(303, $this->post("/conciliaciones/$c/invalidar", ['motivo' => 'Error de captura'])->status());
        self::assertNotNull(self::web()->fetchValue('SELECT cerrada_por FROM conciliaciones WHERE id = :id', ['id' => $c]), 'El cierre se conserva');
        self::assertSame('INVALIDADA', self::web()->fetchValue('SELECT estado FROM conciliaciones WHERE id = :id', ['id' => $c]));
        self::assertSame(2, (int) self::web()->fetchValue('SELECT count(*) FROM conciliacion_empleados WHERE conciliacion_id = :id', ['id' => $c]), 'El historial no se borra');
    }

    public function testRequestExecutionEnqueuesJobWithDatabaseActorAndRerunRules(): void
    {
        $s = $this->conciliationScenario();
        $uid = $this->as('ADMIN');
        $r = $this->post('/conciliaciones', ['lote_id' => (string) $s['lote'], 'solicitado_por' => '1']);
        self::assertSame(0, (int) self::web()->fetchValue('SELECT count(*) FROM conciliaciones WHERE numero = 2'));
        self::assertSame(303, $r->status());
        $r = $this->post('/conciliaciones', ['lote_id' => (string) $s['lote'], 'motivo' => 'Se cargó la dispersión corregida', 'solicitado_por' => '1']);
        $new = self::web()->fetchOne('SELECT * FROM conciliaciones WHERE lote_id = :l AND numero = 2', ['l' => $s['lote']]);
        self::assertNotNull($new, 'v2 creada; v1 no se toca');
        self::assertSame([$uid, 'PENDIENTE'], [(int) $new['solicitado_por'], $new['estado']], 'El solicitante sale de la sesión, no del formulario');
        $job = self::web()->fetchOne('SELECT tipo, parametros FROM procesos WHERE id = :id', ['id' => $new['proceso_id']]);
        self::assertSame(['CONCILIAR_LOTE', (int) $new['id']], [$job['tipo'], json_decode($job['parametros'], true)['conciliacion_id']]);
        self::assertCount(1, $this->auditRows('conciliaciones.reprocesar'));
        self::assertSame('COMPLETADA_CON_INCIDENCIAS', self::web()->fetchValue('SELECT estado FROM conciliaciones WHERE id = :id', ['id' => $s['conc']]));
        $this->post('/conciliaciones', ['lote_id' => (string) $s['lote'], 'motivo' => 'Otra más en paralelo']);
        self::assertSame(2, (int) self::web()->fetchValue('SELECT count(*) FROM conciliaciones WHERE lote_id = :l', ['l' => $s['lote']]));
        self::assertSame(404, $this->post('/conciliaciones', ['lote_id' => '999999', 'motivo' => 'x'])->status());
    }

    public function testExportIsPostAuditedNoStoreAndFormulaSafe(): void
    {
        $s = $this->conciliationScenario();
        $this->as('ADMIN');
        self::assertSame(405, $this->get('/conciliaciones/' . $s['conc'] . '/exportar')->status());
        $csv = $this->post('/conciliaciones/' . $s['conc'] . '/exportar', ['formato' => 'csv']);
        self::assertSame(200, $csv->status());
        self::assertStringContainsString('no-store', (string) $csv->header('Cache-Control'));
        self::assertStringContainsString('90001,"PERSONA FICTICIA UNO"', str_replace("\xEF\xBB\xBF", '', $csv->body()));
        self::assertStringContainsString(',-500.00,', $csv->body(), 'Los importes negativos NO se neutralizan como texto');
        $x = $this->post('/conciliaciones/' . $s['conc'] . '/exportar', ['formato' => 'xlsx']);
        self::assertSame('PK', substr($x->body(), 0, 2));
        self::assertCount(2, $this->auditRows('conciliaciones.exportar'));
        $bs = Exporter::csv(['a', 'b', 'c'], [['A', 'x\\",=1+1', 'B']], []);
        $parsed = str_getcsv(explode("\n", str_replace("\xEF\xBB\xBF", '', $bs))[1], ',', '"', '');
        self::assertSame(['A', 'x\\",=1+1', 'B'], $parsed, 'El valor completo queda en UNA celda');
        $csv = Exporter::csv(['a', 'b'], [['=HYPERLINK("http://x")', '-500.00'], ['+1', '@SUM(A1)']], [1]);
        self::assertStringContainsString("\"'=HYPERLINK(\"\"http://x\"\")\",-500.00", $csv);
        self::assertStringContainsString("'+1,'@SUM(A1)", $csv);
        $zip = Exporter::xlsx(['a'], [['=1+1'], ['-2']], []);
        $tmp = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($tmp, $zip);
        $z = new \ZipArchive();
        $z->open($tmp);
        $sheet = (string) $z->getFromName('xl/worksheets/sheet1.xml');
        $z->close();
        unlink($tmp);
        self::assertStringNotContainsString('<f>', $sheet);
        self::assertStringContainsString('&apos;=1+1', $sheet);
    }

    public function testIdorOnEmployeeDetail(): void
    {
        $s = $this->conciliationScenario();
        $this->as('ADMIN');
        self::assertSame(404, $this->get('/conciliaciones/' . $s['conc'] . '/empleados/999999')->status());
        self::assertSame(404, $this->get('/conciliaciones/' . ($s['conc'] + 999) . '/empleados/' . $s['emp1'])->status());
        self::assertStringContainsString(self::fakeRfc(1), $this->get('/conciliaciones/' . $s['conc'] . '/empleados/' . $s['emp1'])->body(), 'ADMIN ve RFC');
    }

    public function testIncidentsAndIdsNeedTheirOwnPermissions(): void
    {
        $s = $this->conciliationScenario();
        $role = (int) $this->asFixtureGod(fn () => self::web()->fetchValue("INSERT INTO roles (codigo, nombre) VALUES ('CONC_SOLO', 'Sólo conciliación') RETURNING id"));
        foreach (['dashboard.ver', 'conciliaciones.ver', 'conciliaciones.ver_sensible'] as $p) {
            $this->asFixtureGod(fn () => self::web()->execute('INSERT INTO rol_permiso (rol_id, permiso_id) SELECT :r, id FROM permisos WHERE codigo = :p', ['r' => $role, 'p' => $p]));
        }
        $this->createUser('conc.solo', ['CONC_SOLO']);
        $this->loginAs('conc.solo');
        $d = $this->get('/conciliaciones/' . $s['conc'] . '/empleados/' . $s['emp1'])->body();
        self::assertStringNotContainsString('Incidencias de la persona', $d, 'Sin incidencias.ver no se listan incidencias');
        self::assertStringNotContainsString(self::fakeRfc(1), $d, 'RFC en claro exige también empleados.ver_sensible');
        self::assertStringNotContainsString('Por atender', $this->get('/conciliaciones/' . $s['conc'])->body());
        self::assertStringNotContainsString('90001', $this->get('/conciliaciones/' . $s['conc'] . '/empleados?q=' . self::fakeRfc(1))->body(),
            'Sin ambos permisos sensibles no hay búsqueda por RFC (ni oráculo)');
        self::assertNull(json_decode($this->get('/api/conciliaciones/' . $s['conc'])->body(), true)['data']['conciliacion']['incidencias_por_estado']);
        self::assertSame(403, $this->get('/conciliaciones/' . $s['conc'] . '/incidencias')->status());
    }
}
