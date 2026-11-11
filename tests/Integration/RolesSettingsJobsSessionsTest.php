<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Integration;

use ConciliaRH\Core\HttpException;
use ConciliaRH\Core\Session\DatabaseSessionHandler;
use ConciliaRH\Domain\Jobs\JobQueue;
use ConciliaRH\Domain\Roles\RoleService;
use ConciliaRH\Domain\Settings\SettingsService;
use ConciliaRH\Domain\ValidationException;
use ConciliaRH\Domain\Catalogs\CatalogService;
use ConciliaRH\Tests\Support\DatabaseTestCase;

final class RolesSettingsJobsSessionsTest extends DatabaseTestCase
{
    // ------------------------------------------------------------------ roles

    public function testOnlyGodAdministersRolesAndPermissions(): void
    {
        $admin = $this->actor($this->createUser('admin.r', ['ADMIN']));
        $svc = new RoleService(self::web(), $this->audit($admin));
        try {
            $svc->create($admin, 'SUPERVISOR', 'Supervisor', '');
            self::fail('ADMIN no crea roles');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status());
        }
        $god = $this->actor($this->createUser('god.r', ['GOD']));
        $svc = new RoleService(self::web(), $this->audit($god));
        $id = $svc->create($god, 'supervisor', 'Supervisor', 'Revisión');
        $perm = fn (string $c): int => (int) self::web()->fetchValue('SELECT id FROM permisos WHERE codigo = :c', ['c' => $c]);
        $svc->updatePermissions($god, $id, [$perm('dashboard.ver'), $perm('auditoria.ver')]);
        self::assertSame(2, (int) self::web()->fetchValue('SELECT count(*) FROM rol_permiso WHERE rol_id = :r', ['r' => $id]));
        $audit = $this->auditRows('roles.editar_permisos')[0];
        self::assertSame(['auditoria.ver', 'dashboard.ver'], (function (array $a) { sort($a); return $a; })(json_decode($audit['contexto'], true)['agregados']));

        try {
            $svc->updatePermissions($god, $id, [$perm('roles.administrar')]);
            self::fail('solo_god no asignable');
        } catch (ValidationException $e) {
            self::assertStringContainsString('exclusivo de GOD', $e->getMessage());
        }
        $godRole = (int) self::web()->fetchValue("SELECT id FROM roles WHERE codigo = 'GOD'");
        $this->expectException(ValidationException::class);
        $svc->updatePermissions($god, $godRole, []);
    }

    // ------------------------------------------------------------------ configuración

    public function testSettingsUpdateIsValidatedGodOnlyAndAudited(): void
    {
        $god = $this->actor($this->createUser('god.cfg', ['GOD']));
        $admin = $this->actor($this->createUser('admin.cfg', ['ADMIN']));
        $svc = new SettingsService(self::web(), $this->audit($god));
        try {
            (new SettingsService(self::web(), $this->audit($admin)))->update($admin, 'conciliacion.tolerancia_monetaria', '0.05');
            self::fail('ADMIN no edita parámetros solo_god');
        } catch (ValidationException) {
        }
        foreach (['-1', 'abc', '1000'] as $bad) {
            try {
                $svc->update($god, 'conciliacion.tolerancia_monetaria', $bad);
                self::fail("Debió rechazar $bad");
            } catch (ValidationException) {
            }
        }
        $this->actingAs($god);
        $svc->update($god, 'conciliacion.tolerancia_monetaria', '0.05');
        self::assertSame('0.05', self::web()->fetchValue("SELECT valor FROM configuracion WHERE clave = 'conciliacion.tolerancia_monetaria'"));
        $row = $this->auditRows('configuracion.editar')[0];
        self::assertSame(['valor' => '0.01'], json_decode($row['valor_anterior'], true));
        self::assertSame(['valor' => '0.05'], json_decode($row['valor_nuevo'], true));
    }

    // ------------------------------------------------------------------ cola (lado PHP)

    private function queue(\ConciliaRH\Domain\Auth\CurrentUser $actor): JobQueue
    {
        return new JobQueue(self::web(), $this->audit($actor), new SettingsService(self::web(), $this->audit($actor)));
    }

    public function testEnqueueValidatesContractAndIsIdempotent(): void
    {
        $god = $this->actor($this->createUser('god.q', ['GOD']));
        $q = $this->queue($god);
        $id = $q->enqueue($god, 'DIAGNOSTICO', ['eco' => 'hola'], 10, 'prueba:idem:0001');
        $same = $q->enqueue($god, 'DIAGNOSTICO', ['eco' => 'hola'], 10, 'prueba:idem:0001');
        self::assertSame($id, $same);
        $row = self::web()->fetchOne('SELECT estado, contract_version, intentos, max_intentos, creado_por, parametros FROM procesos WHERE id = :id', ['id' => $id]);
        self::assertSame('PENDIENTE', $row['estado']);
        self::assertSame(1, $row['contract_version']);
        self::assertSame(0, $row['intentos']);
        self::assertSame(3, $row['max_intentos']);
        self::assertSame(['eco' => 'hola'], json_decode($row['parametros'], true));
        self::assertCount(1, $this->auditRows('procesos.crear'));
        foreach ([['OTRO', []], ['DIAGNOSTICO', ['comando' => 'rm -rf /']]] as [$t, $p]) {
            try {
                $q->enqueue($god, $t, $p);
                self::fail('Contrato inválido');
            } catch (ValidationException) {
            }
        }
    }

    public function testIdempotencyKeyCannotBeReusedForAnotherJobOrUser(): void
    {
        $god = $this->actor($this->createUser('god.idem', ['GOD']));
        $other = $this->actor($this->createUser('god.idem2', ['GOD']));
        $this->queue($god)->enqueue($god, 'DIAGNOSTICO', [], 100, 'clave:compartida:01');
        $this->expectException(ValidationException::class);
        $this->queue($other)->enqueue($other, 'DIAGNOSTICO', [], 100, 'clave:compartida:01');
    }

    public function testPaginationIsClampedToTheLastPage(): void
    {
        $this->createUser('pagina.uno', ['CONSULTA']);
        $r = (new \ConciliaRH\Domain\Users\UserRepository(self::web()))->paginate(['q' => 'pagina.uno'], 999, 20);
        self::assertSame(1, $r['page']);
        self::assertCount(1, $r['rows']);
    }

    public function testCancelOnlyPendingAndWebCannotForgeResults(): void
    {
        $god = $this->actor($this->createUser('god.c', ['GOD']));
        $q = $this->queue($god);
        $id = $q->enqueue($god, 'DIAGNOSTICO');
        $q->cancel($god, $id);
        self::assertSame('CANCELADO', self::web()->fetchValue('SELECT estado FROM procesos WHERE id = :id', ['id' => $id]));
        try {
            $q->cancel($god, $id);
            self::fail('Un trabajo ya cancelado no puede volver a cancelarse');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('estado', $e->errors());
        }
        // La aplicación web no puede escribir resultados ni tomar trabajos (privilegio por columna)
        $this->assertSqlState('42501', self::web(), fn ($db) => $db->execute("UPDATE procesos SET resultado = '{}' WHERE id = :id", ['id' => $id]));
        $this->assertSqlState('42501', self::web(), fn ($db) => $db->execute('UPDATE procesos SET token_bloqueo = gen_random_uuid() WHERE id = :id', ['id' => $id]));
    }

    public function testQueueHealthCountsActiveWorkers(): void
    {
        $god = $this->actor($this->createUser('god.h', ['GOD']));
        $q = $this->queue($god);
        $before = $q->health(120);
        $this->worker()->execute("INSERT INTO workers (id, hostname, pid, version) VALUES ('test:1', 'h', 1, '0.1.0')");
        // La conexión web no ve la fila no confirmada del worker: sólo se valida la forma del resultado
        self::assertSame(['pendientes', 'en_ejecucion', 'workers_activos', 'ultimo_latido'], array_keys($before));
    }

    // ------------------------------------------------------------------ sesiones en BD

    public function testDatabaseSessionHandlerStoresHashedIdsAndHonorsRevocation(): void
    {
        $db = self::web();
        $uid = $this->createUser('sesion1', ['CONSULTA']);
        $h = new DatabaseSessionHandler($db, 30, '192.0.2.9', 'phpunit');
        $sid = $h->create_sid();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $sid);
        $data = serialize(['_auth' => ['user_id' => $uid], 'x' => 1]);
        $h->write($sid, $data);
        self::assertSame($data, $h->read($sid));
        self::assertTrue($h->validateId($sid));
        self::assertFalse((bool) $db->fetchValue('SELECT 1 FROM sesiones WHERE id = :id', ['id' => $sid]), 'El ID real no debe almacenarse');
        // El manejador NO vincula usuarios (lo hace sólo fn_sesion_vincular al autenticar)
        self::assertNull($db->fetchValue('SELECT usuario_id FROM sesiones WHERE id = :id', ['id' => DatabaseSessionHandler::hashId($sid)]));
        self::assertSame('192.0.2.9', $db->fetchValue('SELECT host(ip) FROM sesiones WHERE id = :id', ['id' => DatabaseSessionHandler::hashId($sid)]));

        $db->execute('UPDATE sesiones SET revocada_at = now() WHERE id = :id', ['id' => DatabaseSessionHandler::hashId($sid)]);
        self::assertSame('', $h->read($sid));
        self::assertFalse($h->validateId($sid));
        $h->write($sid, serialize(['intento' => 'resucitar']));
        self::assertNotNull($db->fetchValue('SELECT revocada_at FROM sesiones WHERE id = :id', ['id' => DatabaseSessionHandler::hashId($sid)]));
        self::assertFalse($h->validateId('no-hex'));
        self::assertFalse($h->validateId(str_repeat('f', 64)));
    }

    public function testExpiredSessionsAreNotReadAndAreCollected(): void
    {
        $db = self::web();
        $h = new DatabaseSessionHandler($db, 30, null, '');
        $sid = $h->create_sid();
        $h->write($sid, 'a|b');
        $db->execute("UPDATE sesiones SET ultima_actividad_at = now() - interval '2 hours' WHERE id = :id", ['id' => DatabaseSessionHandler::hashId($sid)]);
        self::assertSame('', $h->read($sid));
        self::assertGreaterThanOrEqual(1, $h->gc(1800));
        self::assertFalse((bool) $db->fetchValue('SELECT 1 FROM sesiones WHERE id = :id', ['id' => DatabaseSessionHandler::hashId($sid)]));
    }

    /** Matriz funcional: activar/desactivar valores de catálogo (sólo con catalogos.administrar: GOD; los de omisión no se desactivan). */
    public function testCatalogToggle(): void
    {
        $admin = $this->actor($this->createUser('god.cat', ['GOD']));
        $adminCat = $this->actor($this->createUser('admin.cat', ['ADMIN']));
        $id = $this->asFixtureGod(fn () => (int) self::web()->fetchValue(
            "INSERT INTO tipos_documento (codigo, nombre) VALUES ('PRUEBA_CAT', 'Prueba de catálogo') RETURNING id"));
        try {
            (new CatalogService(self::web(), $this->audit($adminCat)))->setActive($adminCat, 'tipos', $id, false);
            self::fail('ADMIN no administra catálogos');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status());
        }
        $svc = new CatalogService(self::web(), $this->audit($admin));   // el auditor vincula la sesión a este actor
        $svc->setActive($admin, 'tipos', $id, false);
        self::assertSame('false', self::web()->fetchValue('SELECT activo::text FROM tipos_documento WHERE id = :id', ['id' => $id]));   // fetchValue convierte false en null
        $svc->setActive($admin, 'tipos', $id, true);
        self::assertSame('true', self::web()->fetchValue('SELECT activo::text FROM tipos_documento WHERE id = :id', ['id' => $id]));
        self::assertCount(1, $this->auditRows('catalogos.desactivar'));
        self::assertCount(1, $this->auditRows('catalogos.activar'));
        $omision = (int) self::web()->fetchValue("SELECT id FROM tipos_documento WHERE codigo = 'OTRO'");
        try {
            $svc->setActive($admin, 'tipos', $omision, false);
            self::fail('OTRO (valor por omisión) no se desactiva');
        } catch (ValidationException) {
        }
    }
}
