<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Integration;

use ConciliaRH\Core\HttpException;
use ConciliaRH\Core\Logger;
use ConciliaRH\Core\Session\ArraySessionStore;
use ConciliaRH\Core\Session\Session;
use ConciliaRH\Domain\Audit\AuditContext;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\AuthService;
use ConciliaRH\Domain\Auth\LoginThrottle;
use ConciliaRH\Domain\Auth\PasswordHasher;
use ConciliaRH\Domain\Settings\SettingsService;
use ConciliaRH\Domain\Users\GodProvisioner;
use ConciliaRH\Domain\Users\UserRepository;
use ConciliaRH\Domain\Users\UserService;
use ConciliaRH\Domain\ValidationException;
use ConciliaRH\Tests\Support\DatabaseTestCase;
use RuntimeException;

final class AuthAndUsersTest extends DatabaseTestCase
{
    private ArraySessionStore $store;

    private function auth(): AuthService
    {
        $db = self::web();
        $ctx = new AuditContext();
        $this->store = new ArraySessionStore();
        return new AuthService(
            $db, new PasswordHasher(),
            new LoginThrottle($db, new SettingsService($db, new AuditLogger($db, $ctx)), str_repeat('c', 64)),
            new Session($this->store), new AuditLogger($db, $ctx), $ctx, new Logger(sys_get_temp_dir()),
        );
    }

    private function users(?\ConciliaRH\Domain\Auth\CurrentUser $actor = null): UserService
    {
        $db = self::web();
        $audit = $this->audit($actor);
        return new UserService($db, new UserRepository($db), new PasswordHasher(), new SettingsService($db, $audit), $audit);
    }

    public function testSuccessfulLoginRegeneratesSessionAndAudits(): void
    {
        $id = $this->createUser('operador1', ['ADMIN']);
        $auth = $this->auth();
        $r = $auth->attempt('  OPERADOR1 ', self::PASSWORD, '192.0.2.5');
        self::assertTrue($r['ok']);
        self::assertSame($id, $r['user_id']);
        self::assertSame(1, $this->store->regenerations);
        self::assertSame($id, $this->store->get('_auth')['user_id']);
        $row = $this->auditRows('auth.login')[0];
        self::assertSame((string) $id, $row['entidad_id']);
        self::assertSame('operador1', $row['username']);
        self::assertNotNull(self::web()->fetchValue('SELECT ultimo_acceso_at FROM usuarios WHERE id = :id', ['id' => $id]));
    }

    public function testGenericMessageForWrongPasswordUnknownUserAndInactiveUser(): void
    {
        $this->createUser('activo', ['CONSULTA']);
        $this->createUser('inactivo', ['CONSULTA'], active: false);
        $auth = $this->auth();
        $wrong = $auth->attempt('activo', 'incorrecta-123456', '192.0.2.6');
        $unknown = $auth->attempt('no.existe', self::PASSWORD, '192.0.2.6');
        $inactive = $auth->attempt('inactivo', self::PASSWORD, '192.0.2.6');
        foreach ([$wrong, $unknown, $inactive] as $r) {
            self::assertFalse($r['ok']);
            self::assertSame(AuthService::GENERIC_ERROR, $r['message']);
        }
        self::assertNull($this->store->get('_auth'));
    }

    public function testLockoutPerUserAndIpEvenWithCorrectPassword(): void
    {
        $this->createUser('victima', ['CONSULTA']);
        $auth = $this->auth();
        for ($i = 0; $i < 5; $i++) {
            $auth->attempt('victima', 'incorrecta-' . $i . 'xxxxxxx', '192.0.2.11');
        }
        $r = $auth->attempt('victima', self::PASSWORD, '192.0.2.11');
        self::assertFalse($r['ok']);
        self::assertStringContainsString('Demasiados intentos', (string) $r['message']);
        self::assertCount(1, $this->auditRows('auth.bloqueo'));
        self::assertTrue($auth->attempt('victima', self::PASSWORD, '192.0.2.22')['ok']);
        self::assertSame(0, (int) self::web()->fetchValue("SELECT count(*) FROM intentos_login WHERE username_hmac = 'victima'"));
    }

    public function testGlobalLockoutStopsDistributedAttack(): void
    {
        $this->createUser('objetivo.dist', ['CONSULTA']);
        $auth = $this->auth();
        for ($i = 0; $i < 20; $i++) {
            $auth->attempt('objetivo.dist', 'incorrecta-' . $i . 'zzzz', '198.51.100.' . $i);
        }
        self::assertStringContainsString('Demasiados intentos', (string) $auth->attempt('objetivo.dist', self::PASSWORD, '203.0.113.1')['message']);
    }

    public function testLockoutAlsoAppliesToUnknownUsersSoItCannotEnumerate(): void
    {
        $auth = $this->auth();
        for ($i = 0; $i < 5; $i++) {
            $auth->attempt('fantasma', 'x-incorrecta-' . $i, '192.0.2.33');
        }
        self::assertStringContainsString('Demasiados intentos', (string) $auth->attempt('fantasma', 'otra-cosa-123', '192.0.2.33')['message']);
    }

    public function testDummyVerificationCostsTheSameAsARealOne(): void
    {
        $real = (new PasswordHasher())->hash('x');
        $ref = new \ReflectionClassConstant(PasswordHasher::class, 'DUMMY_ARGON2ID');
        self::assertSame(password_get_info($real)['options'], password_get_info((string) $ref->getValue())['options']);
    }

    public function testCurrentUserLoadsPermissionsAndExcludesInactive(): void
    {
        $god = $this->createUser('god.test', ['GOD']);
        $cons = $this->createUser('consulta1', ['CONSULTA']);
        $off = $this->createUser('apagado', ['ADMIN'], active: false);
        $auth = $this->auth();
        self::assertTrue($auth->loadUser($god)->can('roles.administrar'));
        self::assertFalse($auth->loadUser($cons)->can('usuarios.ver'));
        self::assertTrue($auth->loadUser($cons)->can('dashboard.ver'));
        self::assertNull($auth->loadUser($off));
    }

    public function testGodProvisioningIsUniqueAndAudited(): void
    {
        $db = $this->owner();   // el alta de GOD sólo es posible con el rol propietario (bin/create-god.php)
        $db->execute("UPDATE usuarios SET activo = false WHERE id IN (SELECT usuario_id FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id WHERE r.codigo = 'GOD')");
        $ctx = new AuditContext();
        $prov = new GodProvisioner($db, new UserRepository($db), new PasswordHasher(), new AuditLogger($db, $ctx), $ctx);
        $id = $prov->create('Responsable.Unico', 'Responsable Único', 'Clave-Maestra-2026', 12, 'tester');
        self::assertSame(['GOD'], (new UserRepository($db))->roleCodes($id));
        self::assertSame('responsable.unico', $db->fetchValue('SELECT username FROM usuarios WHERE id = :id', ['id' => $id]));
        $audit = $db->fetchAll("SELECT origen, valor_nuevo, contexto FROM auditoria WHERE accion = 'usuarios.crear_god' ORDER BY secuencia");
        self::assertSame('CLI', end($audit)['origen']);
        self::assertStringNotContainsString('Clave-Maestra', json_encode($audit));
        $this->expectException(RuntimeException::class);
        $prov->create('segundo.god', 'Segundo', 'Clave-Maestra-2026', 12);
    }

    public function testGodRoleCannotBeGrantedByTheWebRoleEvenWithDirectSql(): void
    {
        $id = $this->createUser('intento.god', ['CONSULTA']);
        $this->assertSqlState('42501', self::web(), fn ($db) => $db->execute(
            "INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = 'GOD'", ['u' => $id]
        ));
        $godId = $this->createUser('god.intocable', ['GOD']);
        $this->assertSqlState('42501', self::web(), fn ($db) => $db->execute(
            "DELETE FROM usuario_rol WHERE usuario_id = :u", ['u' => $godId]
        ));
    }

    public function testGodProvisioningValidatesInput(): void
    {
        $db = $this->owner();
        $db->execute("UPDATE usuarios SET activo = false WHERE id IN (SELECT usuario_id FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id WHERE r.codigo = 'GOD')");
        $ctx = new AuditContext();
        $prov = new GodProvisioner($db, new UserRepository($db), new PasswordHasher(), new AuditLogger($db, $ctx), $ctx);
        try {
            $prov->create('x', 'Y', 'corta', 12);
            self::fail('Debió validar');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('username', $e->errors());
            self::assertArrayHasKey('nombre', $e->errors());
            self::assertArrayHasKey('password', $e->errors());
        }
    }

    public function testAdminCreatesOperationalUsersButCannotGrantProtectedRoles(): void
    {
        $admin = $this->actor($this->createUser('admin1', ['ADMIN']));
        self::assertFalse($admin->can('usuarios.crear'));
        foreach (['CONSULTA', 'ADMIN', 'GOD', 'INVENTADO'] as $role) {
            try {
                self::web()->transaction(fn () => $this->users($admin)->create($admin, 'otro.' . strtolower($role), 'Otro Usuario', 'Temporal-Segura-1', [$role]));
                self::fail("ADMIN no debe poder crear usuarios ($role)");
            } catch (ValidationException $e) {
                self::assertArrayHasKey('roles', $e->errors());
            } catch (\PDOException $e) {
                self::assertSame('42501', $e->getCode());
            }
        }
        self::assertSame(0, (int) self::web()->fetchValue("SELECT count(*) FROM usuarios WHERE username LIKE 'otro.%'"));
        $god = $this->actor($this->createUser('god.crea', ['GOD']));
        $id = $this->users($god)->create($god, 'Nuevo.Lector', 'Nuevo   Lector', 'Temporal-Segura-1', ['CONSULTA']);
        $row = self::web()->fetchOne('SELECT username, nombre, debe_cambiar_password, creado_por FROM usuarios WHERE id = :id', ['id' => $id]);
        self::assertSame(['nuevo.lector', 'Nuevo Lector', true, $god->id], array_values($row));
        self::assertStringNotContainsString('Temporal-Segura-1', json_encode($this->auditRows('usuarios.crear')));
    }

    public function testGodCanGrantAdminButNeverGodFromWeb(): void
    {
        $god = $this->actor($this->createUser('god.web', ['GOD']));
        $svc = $this->users($god);
        $id = $svc->create($god, 'nuevo.admin', 'Nuevo Admin', 'Temporal-Segura-1', ['ADMIN']);
        self::assertSame(['ADMIN'], (new UserRepository(self::web()))->roleCodes($id));
        $this->expectException(ValidationException::class);
        $svc->create($god, 'otro.god', 'Otro God', 'Temporal-Segura-1', ['GOD']);
    }

    public function testCreationValidations(): void
    {
        $god = $this->actor($this->createUser('god.val', ['GOD']));
        $svc = $this->users($god);
        $svc->create($god, 'duplicado', 'Usuario Duplicado', 'Temporal-Segura-1', ['CONSULTA']);
        try {
            $svc->create($god, 'DUPLICADO', 'X', 'corta', []);
            self::fail('Debió validar');
        } catch (ValidationException $e) {
            self::assertSame(['username', 'nombre', 'password', 'roles'], array_keys($e->errors()));
        }
    }

    public function testAdminCannotManageGodOrOtherAdmins(): void
    {
        $admin = $this->actor($this->createUser('admin2', ['ADMIN']));
        $godId = $this->createUser('god.x', ['GOD']);
        $otherAdmin = $this->createUser('admin3', ['ADMIN']);
        $svc = $this->users($admin);
        foreach ([$godId, $otherAdmin] as $target) {
            foreach ([
                fn () => $svc->update($admin, $target, 'Cambio', ['CONSULTA']),
                fn () => $svc->setActive($admin, $target, false),
                fn () => $svc->delete($admin, $target, 'motivo suficiente'),
                fn () => $svc->resetPassword($admin, $target, 'Temporal-Segura-9'),
                fn () => $svc->revokeSessions($admin, $target),
            ] as $action) {
                try {
                    $action();
                    self::fail('ADMIN no debe gestionar cuentas protegidas');
                } catch (HttpException $e) {
                    self::assertSame(403, $e->status());
                }
            }
        }
    }

    public function testNobodyCanChangeTheirOwnRoles(): void
    {
        $god = $this->actor($this->createUser('god.propio', ['GOD']));
        $svc = $this->users($god);
        $svc->update($god, $god->id, 'Nombre Cambiado', []);   // GOD: sus roles no se tocan
        self::assertSame(['GOD'], (new UserRepository(self::web()))->roleCodes($god->id));
        $roleId = (int) $this->asFixtureGod(fn () => self::web()->fetchValue("INSERT INTO roles (codigo, nombre) VALUES ('SUPERVISOR_RH', 'Supervisor') RETURNING id"));
        foreach (['dashboard.ver', 'usuarios.ver', 'usuarios.editar'] as $p) {
            $this->asFixtureGod(fn () => self::web()->execute('INSERT INTO rol_permiso (rol_id, permiso_id) SELECT :r, id FROM permisos WHERE codigo = :p', ['r' => $roleId, 'p' => $p]));
        }
        $supId = $this->createUser('supervisor1', ['SUPERVISOR_RH']);
        $sup = $this->actor($supId);
        try {
            $this->users($sup)->update($sup, $supId, 'Supervisor Uno', ['SUPERVISOR_RH', 'CONSULTA']);
            self::fail('No debe poder cambiarse sus propios roles');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('roles', $e->errors());
        }
    }

    public function testNonGodCannotAssignRolesWithPermissionsTheyLack(): void
    {
        $roleId = (int) $this->asFixtureGod(fn () => self::web()->fetchValue("INSERT INTO roles (codigo, nombre) VALUES ('CONFIG_VIEW', 'Configuración') RETURNING id"));
        foreach (['dashboard.ver', 'procesos.ver_detalle_tecnico', 'usuarios.crear'] as $p) {
            $this->asFixtureGod(fn () => self::web()->execute('INSERT INTO rol_permiso (rol_id, permiso_id) SELECT :r, id FROM permisos WHERE codigo = :p', ['r' => $roleId, 'p' => $p]));
        }
        $limitedRole = (int) $this->asFixtureGod(fn () => self::web()->fetchValue("INSERT INTO roles (codigo, nombre) VALUES ('ALTAS', 'Altas') RETURNING id"));
        foreach (['dashboard.ver', 'usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'periodos.ver', 'lotes.ver', 'archivos.ver', 'analisis.ver',
                  'empleados.ver', 'asociaciones.ver', 'conciliaciones.ver', 'incidencias.ver', 'beneficiarios.ver', 'reportes.ver', 'reportes.exportar'] as $p) {
            $this->asFixtureGod(fn () => self::web()->execute('INSERT INTO rol_permiso (rol_id, permiso_id) SELECT :r, id FROM permisos WHERE codigo = :p', ['r' => $limitedRole, 'p' => $p]));
        }
        $actor = $this->actor($this->createUser('altas1', ['ALTAS']));
        $codes = array_column($this->users($actor)->assignableRoles($actor), 'codigo');
        self::assertContains('CONSULTA', $codes);
        self::assertNotContains('CONFIG_VIEW', $codes, 'Tiene procesos.ver_detalle_tecnico, que el actor no posee');
        self::assertNotContains('ADMIN', $codes);
        self::assertNotContains('GOD', $codes);
    }

    public function testRoleSyncKeepsRolesOutsideTheActorScope(): void
    {
        $admin = $this->actor($this->createUser('god.sync', ['GOD']));
        $inactive = (int) $this->asFixtureGod(fn () => self::web()->fetchValue("INSERT INTO roles (codigo, nombre) VALUES ('HISTORICO', 'Histórico') RETURNING id"));
        $this->asFixtureGod(fn () => self::web()->execute('UPDATE roles SET activo = false WHERE id = :r', ['r' => $inactive]));
        $target = $this->createUser('conserva', ['CONSULTA']);
        $this->asFixtureGod(fn () => self::web()->execute('INSERT INTO usuario_rol (usuario_id, rol_id) VALUES (:u, :r)', ['u' => $target, 'r' => $inactive]));
        $this->users($admin)->update($admin, $target, 'Conserva Roles', ['CONSULTA', 'ADMIN']);
        self::assertSame(['ADMIN', 'CONSULTA', 'HISTORICO'], (new UserRepository(self::web()))->roleCodes($target));
    }

    public function testCannotDeactivateOrDeleteSelf(): void
    {
        $god = $this->actor($this->createUser('god.self', ['GOD']));
        $svc = $this->users($god);
        $this->expectException(ValidationException::class);
        $svc->setActive($god, $god->id, false);
    }

    public function testDeactivateDeleteAndResetRevokeSessions(): void
    {
        $admin = $this->actor($this->createUser('admin4', ['ADMIN']));
        $target = $this->createUser('objetivo', ['CONSULTA']);
        $db = self::web();
        $newSession = fn () => $this->openSessionFor($target);
        $active = fn (): int => (int) $db->fetchValue('SELECT count(*) FROM sesiones WHERE usuario_id = :u AND revocada_at IS NULL', ['u' => $target]);
        $svc = $this->users($admin);

        $newSession();
        $svc->resetPassword($admin, $target, 'Temporal-Nueva-22');
        self::assertSame(0, $active());
        self::assertTrue((bool) $db->fetchValue('SELECT debe_cambiar_password FROM usuarios WHERE id = :u', ['u' => $target]));

        $newSession();
        $svc->setActive($admin, $target, false);
        self::assertSame(0, $active());

        try {
            $svc->delete($admin, $target, 'no');
            self::fail('Motivo obligatorio');
        } catch (ValidationException) {
        }
        $svc->delete($admin, $target, 'Baja del área de nómina');
        $row = $db->fetchOne('SELECT activo, deleted_at, deleted_by FROM usuarios WHERE id = :u', ['u' => $target]);
        self::assertFalse($row['activo']);
        self::assertNotNull($row['deleted_at']);
        self::assertSame($admin->id, $row['deleted_by']);
        self::assertSame('Baja del área de nómina', json_decode($this->auditRows('usuarios.eliminar')[0]['contexto'], true)['motivo']);
        $this->expectException(ValidationException::class);
        $svc->update($admin, $target, 'Revivir', ['CONSULTA']);
    }

    public function testRoleChangeRevokesSessionsAndIsAudited(): void
    {
        $god = $this->actor($this->createUser('god.roles', ['GOD']));
        $target = $this->createUser('promovido', ['CONSULTA']);
        $this->openSessionFor($target);
        $this->users($god)->update($god, $target, 'Usuario Promovido', ['ADMIN', 'CONSULTA']);
        self::assertSame(['ADMIN', 'CONSULTA'], (new UserRepository(self::web()))->roleCodes($target));
        self::assertSame(0, (int) self::web()->fetchValue('SELECT count(*) FROM sesiones WHERE usuario_id = :u AND revocada_at IS NULL', ['u' => $target]));
        $row = $this->auditRows('usuarios.editar')[0];
        self::assertSame(['CONSULTA'], json_decode($row['valor_anterior'], true)['roles']);
    }

    public function testChangeOwnPassword(): void
    {
        $id = $this->createUser('cambia', ['CONSULTA'], mustChange: true);
        $me = $this->actor($id);
        $svc = $this->users($me);
        $cases = [
            ['incorrecta', 'Nueva-Clave-2026x', 'Nueva-Clave-2026x', 'password_actual'],
            [self::PASSWORD, 'Nueva-Clave-2026x', 'Distinta-2026xx', 'password_confirmacion'],
            [self::PASSWORD, self::PASSWORD, self::PASSWORD, 'password_nueva'],
            [self::PASSWORD, 'corta', 'corta', 'password_nueva'],
        ];
        foreach ($cases as [$cur, $new, $conf, $field]) {
            try {
                $svc->changeOwnPassword($me, $cur, $new, $conf, str_repeat('a', 64));
                self::fail("Debió fallar en $field");
            } catch (ValidationException $e) {
                self::assertArrayHasKey($field, $e->errors());
            }
        }
        $this->openSessionFor($id, str_repeat('d', 64));
        $keep = hash('sha256', $this->actorTokenFor($me));
        $svc->changeOwnPassword($me, self::PASSWORD, 'Nueva-Clave-2026x', 'Nueva-Clave-2026x', $keep);
        self::assertFalse((bool) self::web()->fetchValue('SELECT debe_cambiar_password FROM usuarios WHERE id = :id', ['id' => $id]));
        $open = array_column(self::web()->fetchAll('SELECT id FROM sesiones WHERE usuario_id = :u AND revocada_at IS NULL', ['u' => $id]), 'id');
        self::assertSame([$keep], $open);
    }

    public function testCannotManageUsersWithPermissionsTheActorLacks(): void
    {
        $roleId = (int) $this->asFixtureGod(fn () => self::web()->fetchValue("INSERT INTO roles (codigo, nombre) VALUES ('SOPORTE_X', 'Soporte') RETURNING id"));
        foreach (['dashboard.ver', 'usuarios.ver', 'usuarios.restablecer_password', 'usuarios.desactivar'] as $p) {
            $this->asFixtureGod(fn () => self::web()->execute('INSERT INTO rol_permiso (rol_id, permiso_id) SELECT :r, id FROM permisos WHERE codigo = :p', ['r' => $roleId, 'p' => $p]));
        }
        $soporte = $this->actor($this->createUser('soporte.x', ['SOPORTE_X']));
        $lector = $this->createUser('consulta.objetivo', ['CONSULTA']);
        $consulta = $this->createUser('sin.permisos.extra', []);
        $svc = $this->users($soporte);
        try {
            $svc->resetPassword($soporte, $lector, 'Contrasena-Nueva-2026!');
            self::fail('No debe restablecer la contraseña de alguien con permisos que no posee');
        } catch (\ConciliaRH\Core\HttpException $e) {
            self::assertSame(403, $e->status());
        }
        try {
            $svc->setActive($soporte, $lector, false);
            self::fail('Ni desactivarlo');
        } catch (\ConciliaRH\Core\HttpException $e) {
            self::assertSame(403, $e->status());
        }
        $svc->resetPassword($soporte, $consulta, 'Contrasena-Nueva-2026!');
        $this->addToAssertionCount(1);
    }
}
