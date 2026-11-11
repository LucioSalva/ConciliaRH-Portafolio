<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Integration;

use ConciliaRH\Core\Database;
use ConciliaRH\Domain\Audit\AuditContext;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\LoginThrottle;
use ConciliaRH\Domain\Auth\PasswordHasher;
use ConciliaRH\Domain\Settings\SettingsService;
use ConciliaRH\Domain\Users\GodProvisioner;
use ConciliaRH\Domain\Users\GodRecovery;
use ConciliaRH\Domain\Users\UserRepository;
use ConciliaRH\Domain\ValidationException;
use ConciliaRH\Tests\Support\DatabaseTestCase;
use RuntimeException;

/**
 * Recuperación de emergencia de la cuenta GOD (FASE 10, docs/BREAK_GLASS_RECOVERY.md): sólo la cuenta GOD, sin segundo GOD,
 * auditada como SYSTEM_BREAK_GLASS y sin secretos en la auditoría. Las pruebas de la CLI están en tests/Http/BreakGlassCliTest.
 */
final class GodRecoveryTest extends DatabaseTestCase
{
    private const MOTIVO = 'Prueba automática de recuperación de emergencia';
    private const NUEVA = 'Recuperada-Segura-2027';

    private function service(Database $db): GodRecovery
    {
        $ctx = new AuditContext();
        $audit = new AuditLogger($db, $ctx);
        $settings = new SettingsService($db, $audit);
        return new GodRecovery($db, new UserRepository($db), new PasswordHasher(), new LoginThrottle($db, $settings, str_repeat('k', 64)), $settings, $audit, $ctx);
    }

    /** Deja UNA sola cuenta GOD (creada por el alta real, que es la que registra la auditoría) dentro de la transacción del propietario. */
    private function soloUnGod(Database $db, string $username = 'god.emergencia'): int
    {
        $db->execute("DELETE FROM usuario_rol WHERE rol_id = (SELECT id FROM roles WHERE codigo = 'GOD')");
        $ctx = new AuditContext();
        return (new GodProvisioner($db, new UserRepository($db), new PasswordHasher(), new AuditLogger($db, $ctx), $ctx))
            ->create($username, 'Responsable De Prueba', 'Clave-Inicial-2026', 12, 'tester');
    }

    private function hash(Database $db, int $id): string
    {
        return (string) $db->fetchValue('SELECT password_hash FROM usuarios WHERE id = :id', ['id' => $id]);
    }

    public function testStatusIsReadOnlyAndNeverExposesHashes(): void
    {
        $db = $this->owner();
        $id = $this->soloUnGod($db);
        $antes = (int) $db->fetchValue('SELECT count(*) FROM auditoria');
        $s = $this->service($db)->status();
        self::assertSame([1, 1, []], [$s['cuentas_god'], $s['cuentas_god_activas'], $s['problemas']]);
        self::assertSame('god.emergencia', $s['cuenta']['usuario']);
        self::assertTrue($s['cuenta']['activa']);
        self::assertStringNotContainsString('argon2', (string) json_encode($s));
        self::assertStringNotContainsString($this->hash($db, $id), (string) json_encode($s));
        self::assertSame($antes, (int) $db->fetchValue('SELECT count(*) FROM auditoria'), 'status no escribe nada');
    }

    public function testResetPasswordForcesChangeRevokesSessionsAndIsAuditedWithoutSecrets(): void
    {
        $db = $this->owner();
        $id = $this->soloUnGod($db);
        $db->execute("INSERT INTO sesiones (id, usuario_id, datos) VALUES (:s, :u, '')", ['s' => hash('sha256', 'sesion-robada'), 'u' => $id]);
        $viejo = $this->hash($db, $id);
        $usuarios = (int) $db->fetchValue('SELECT count(*) FROM usuarios');

        $r = $this->service($db)->resetPassword(self::NUEVA, 12, self::MOTIVO, 'root (operador: soporte)', 'god.emergencia');

        self::assertSame('god.emergencia', $r['usuario']);
        self::assertSame(1, $r['sesiones_revocadas']);
        $nuevo = $this->hash($db, $id);
        self::assertNotSame($viejo, $nuevo);
        self::assertTrue((new PasswordHasher())->verify(self::NUEVA, $nuevo));
        self::assertTrue($db->fetchValue('SELECT debe_cambiar_password FROM usuarios WHERE id = :id', ['id' => $id]));
        self::assertSame(0, (int) $db->fetchValue('SELECT count(*) FROM sesiones WHERE usuario_id = :u AND revocada_at IS NULL', ['u' => $id]));
        self::assertSame($usuarios, (int) $db->fetchValue('SELECT count(*) FROM usuarios'), 'no crea usuarios');
        self::assertSame(1, (int) $db->fetchValue("SELECT count(*) FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id WHERE r.codigo = 'GOD'"), 'no hay segundo GOD');

        $a = $db->fetchOne("SELECT usuario_id, username, roles, origen, entidad_id, valor_nuevo::text AS nuevo, contexto FROM auditoria
                             WHERE accion = 'emergencia.god_reset_password' ORDER BY secuencia DESC LIMIT 1");
        self::assertNotNull($a);
        self::assertSame([null, GodRecovery::ACTOR, null, 'CLI', (string) $id], [$a['usuario_id'], $a['username'], $a['roles'], $a['origen'], $a['entidad_id']]);
        self::assertSame(true, json_decode((string) $a['nuevo'], true)['cambio_obligatorio'], 'el registro dice qué cambió, sin campos redactados');
        self::assertStringNotContainsString('REDACTADO', (string) $a['nuevo']);
        $ctx = json_decode((string) $a['contexto'], true);
        self::assertSame(['SYSTEM_BREAK_GLASS', 'reset_password', self::MOTIVO, 'root (operador: soporte)'], [$ctx['tipo'], $ctx['operacion'], $ctx['motivo'], $ctx['usuario_so']]);
        // Ni la contraseña ni su hash (ni el anterior) quedan en NINGÚN registro de auditoría
        $todo = (string) $db->fetchValue("SELECT string_agg(coalesce(valor_anterior::text,'') || coalesce(valor_nuevo::text,'') || coalesce(contexto::text,''), ' ') FROM auditoria");
        foreach ([self::NUEVA, $nuevo, $viejo, 'argon2'] as $secreto) {
            self::assertStringNotContainsString($secreto, $todo);
        }
        // El cambio de credenciales además lo registra la propia BD (trigger), con la conexión que lo hizo
        self::assertGreaterThanOrEqual(1, (int) $db->fetchValue("SELECT count(*) FROM auditoria WHERE accion = 'usuarios.cambio_credenciales' AND entidad_id = :id", ['id' => (string) $id]));
    }

    public function testWeakPasswordOrShortReasonChangesNothing(): void
    {
        $db = $this->owner();
        $id = $this->soloUnGod($db);
        $viejo = $this->hash($db, $id);
        $n = (int) $db->fetchValue("SELECT count(*) FROM auditoria WHERE accion LIKE 'emergencia.%'");
        foreach ([['corta', self::MOTIVO], ['god.emergencia', self::MOTIVO], [self::NUEVA, 'corto']] as [$pass, $motivo]) {
            try {
                $this->service($db)->resetPassword($pass, 12, $motivo, 'root', 'god.emergencia');
                self::fail('Debió rechazar');
            } catch (ValidationException $e) {
                self::assertNotSame([], $e->errors());
            }
        }
        self::assertSame($viejo, $this->hash($db, $id));
        self::assertSame($n, (int) $db->fetchValue("SELECT count(*) FROM auditoria WHERE accion LIKE 'emergencia.%'"));
    }

    public function testNeverTouchesAUserThatIsNotGod(): void
    {
        $db = $this->owner();
        $this->soloUnGod($db);
        $otro = (int) $db->fetchValue("INSERT INTO usuarios (username, nombre, password_hash) VALUES ('admin.ajeno', 'Admin Ajeno', :h) RETURNING id", ['h' => self::passwordHash()]);
        $db->execute("INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = 'ADMIN'", ['u' => $otro]);
        $s = $this->service($db);
        foreach (['admin.ajeno', 'no.existe', "x' OR '1'='1", '../../etc/passwd'] as $u) {
            foreach ([fn () => $s->resetPassword(self::NUEVA, 12, self::MOTIVO, 'root', $u), fn () => $s->reactivate(self::MOTIVO, 'root', $u),
                      fn () => $s->unlock(self::MOTIVO, 'root', $u), fn () => $s->revokeSessions(self::MOTIVO, 'root', $u)] as $fn) {
                try {
                    $fn();
                    self::fail("Debió rechazar $u");
                } catch (RuntimeException $e) {
                    self::assertStringContainsString('sólo opera sobre la cuenta GOD', $e->getMessage());
                }
            }
        }
        self::assertSame(self::passwordHash(), $this->hash($db, $otro));
        self::assertSame(['ADMIN'], (new UserRepository($db))->roleCodes($otro), 'no se le asigna GOD');
    }

    public function testReactivateOnlyWhenNoOtherActiveGod(): void
    {
        $db = $this->owner();
        $id = $this->soloUnGod($db);
        $db->execute('UPDATE usuarios SET activo = false WHERE id = :id', ['id' => $id]);
        self::assertSame(['No hay ninguna cuenta GOD activa.'], $this->service($db)->status()['problemas']);
        $r = $this->service($db)->reactivate(self::MOTIVO, 'root', 'god.emergencia');
        self::assertTrue($r['activa']);
        self::assertTrue($db->fetchValue('SELECT activo FROM usuarios WHERE id = :id', ['id' => $id]));
        // Una segunda cuenta con el rol, inactiva, no se reactiva mientras la primera esté activa
        $otra = (int) $db->fetchValue("INSERT INTO usuarios (username, nombre, password_hash, activo) VALUES ('god.antiguo', 'God Antiguo', :h, false) RETURNING id", ['h' => self::passwordHash()]);
        $db->execute("INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = 'GOD'", ['u' => $otra]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no es la GOD activa');
        $this->service($db)->reactivate(self::MOTIVO, 'root', 'god.antiguo');
    }

    public function testUnlockClearsFailedAttemptsOfTheGodOnly(): void
    {
        $db = $this->owner();
        $this->soloUnGod($db);
        $s = $this->service($db);
        $th = new LoginThrottle($db, new SettingsService($db, new AuditLogger($db, new AuditContext())), str_repeat('k', 64));
        for ($i = 0; $i < 6; $i++) {
            $th->record('god.emergencia', '203.0.113.9', false);
            $th->record('otra.persona', '203.0.113.9', false);
        }
        self::assertTrue($th->isLocked('god.emergencia', '203.0.113.9'));
        $antes = $s->status()['cuenta'];
        self::assertSame([true, 6], [$antes['bloqueada_por_intentos'], $antes['intentos_fallidos_recientes']], 'status ve el bloqueo habitual (5 fallos desde un origen)');
        self::assertSame(6, $s->unlock(self::MOTIVO, 'root', 'god.emergencia')['intentos_eliminados']);
        self::assertFalse($th->isLocked('god.emergencia', '203.0.113.9'), 'desbloqueada desde el MISMO origen que la bloqueó');
        $despues = $s->status()['cuenta'];
        self::assertSame([false, 0], [$despues['bloqueada_por_intentos'], $despues['intentos_fallidos_recientes']]);
        self::assertTrue($th->isLocked('otra.persona', '203.0.113.9'), 'los intentos de otras cuentas no se tocan');
    }

    public function testRepairRestoresTheRoleOnlyToTheAuditedGodAndLeavesItUnusableUntilReset(): void
    {
        $db = $this->owner();
        $id = $this->soloUnGod($db);
        $s = $this->service($db);
        self::assertSame([], $s->repair(self::MOTIVO, 'root', 'god.emergencia')['cambios'], 'con los invariantes en orden no cambia nada');
        // La cuenta pierde el rol y, ya sin la protección de GOD, alguien le cambia la contraseña, le da ADMIN y abre sesión
        $db->execute('DELETE FROM usuario_rol WHERE usuario_id = :u', ['u' => $id]);
        $db->execute("INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = 'ADMIN'", ['u' => $id]);
        $db->execute('UPDATE usuarios SET password_hash = :h, debe_cambiar_password = false WHERE id = :id', ['h' => self::passwordHash(), 'id' => $id]);
        $db->execute("INSERT INTO sesiones (id, usuario_id, datos) VALUES (:s, :u, '')", ['s' => hash('sha256', 'sesion-del-intruso'), 'u' => $id]);
        $st = $s->status();
        self::assertSame([0, 'god.emergencia'], [$st['cuentas_god'], $st['candidata_repair']], 'status dice a quién se le devolvería el rol');

        try {
            $s->repair(self::MOTIVO, 'root', 'otra.cuenta');
            self::fail('Debió exigir la cuenta confirmada');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('ya no es la que se confirmó', $e->getMessage());
        }
        $r = $s->repair(self::MOTIVO, 'root', 'god.emergencia');
        self::assertSame(['god.emergencia', ['rol_god_restituido', 'credencial_inutilizada'], ['ADMIN'], 1], [$r['usuario'], $r['cambios'], $r['roles_quitados'], $r['sesiones_revocadas']]);
        self::assertSame(['GOD'], (new UserRepository($db))->roleCodes($id), 'sólo GOD: los roles que le dieron mientras tanto se quitan');
        self::assertFalse((new PasswordHasher())->verify(self::PASSWORD, $this->hash($db, $id)), 'la contraseña que alguien le puso ya no sirve');
        self::assertTrue($db->fetchValue('SELECT debe_cambiar_password FROM usuarios WHERE id = :id', ['id' => $id]));
        self::assertSame(0, (int) $db->fetchValue('SELECT count(*) FROM sesiones WHERE usuario_id = :u AND revocada_at IS NULL', ['u' => $id]));
        self::assertSame(1, (int) $db->fetchValue("SELECT count(*) FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id WHERE r.codigo = 'GOD'"));
        self::assertSame([], $s->repair(self::MOTIVO, 'root', 'god.emergencia')['cambios']);
        // Sólo vuelve a ser utilizable con una contraseña nueva, puesta por la CLI
        $s->resetPassword(self::NUEVA, 12, self::MOTIVO, 'root', 'god.emergencia');
        self::assertTrue((new PasswordHasher())->verify(self::NUEVA, $this->hash($db, $id)));
    }

    public function testConfirmedAccountMustStillBeTheGodWhenTheOperationRuns(): void
    {
        $db = $this->owner();
        $this->soloUnGod($db);
        // El operador confirmó «god.anterior», pero la cuenta GOD es otra: no se opera sobre la que no se confirmó
        foreach ([fn ($s) => $s->resetPassword(self::NUEVA, 12, self::MOTIVO, 'root', 'god.anterior'), fn ($s) => $s->unlock(self::MOTIVO, 'root', 'god.anterior'),
                  fn ($s) => $s->repair(self::MOTIVO, 'root', 'god.anterior')] as $fn) {
            try {
                $fn($this->service($db));
                self::fail('Debió rechazar');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('sólo opera sobre la cuenta GOD', $e->getMessage());
            }
        }
        self::assertSame(0, (int) $db->fetchValue("SELECT count(*) FROM auditoria WHERE accion LIKE 'emergencia.%' AND contexto->>'motivo' = :m", ['m' => self::MOTIVO]));
    }

    public function testDevelopmentGodAccountsAreReportedAndBlockReactivation(): void
    {
        $db = $this->owner();
        $id = $this->soloUnGod($db);
        $dev = (int) $db->fetchValue("INSERT INTO usuarios (username, nombre, password_hash, es_desarrollo) VALUES ('dev.god.bg', 'Dev God', :h, true) RETURNING id", ['h' => self::passwordHash()]);
        $db->execute("INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = 'GOD'", ['u' => $dev]);
        $s = $this->service($db);
        self::assertStringContainsString('DESARROLLO', implode(' ', $s->status()['problemas']));
        self::assertSame('god.emergencia', $s->status()['cuenta']['usuario'], 'nunca opera sobre la cuenta de desarrollo');
        $db->execute('UPDATE usuarios SET activo = false WHERE id = :id', ['id' => $id]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cuenta de desarrollo');
        $s->reactivate(self::MOTIVO, 'root', 'god.emergencia');
    }

    public function testTheWebRoleCannotPerformARecovery(): void
    {
        $id = $this->createUser('god.intocable.bg', ['GOD']);
        $viejo = $this->hash(self::web(), $id);
        // Con la conexión de la aplicación web la BD rechaza cada paso de una «recuperación»: no existe la vía web
        $this->assertSqlState('42501', self::web(), fn ($db) => $db->execute(
            'UPDATE usuarios SET password_hash = :h, debe_cambiar_password = true WHERE id = :id', ['h' => self::passwordHash() . 'x', 'id' => $id]
        ));
        $this->assertSqlState('42501', self::web(), fn ($db) => $db->execute('UPDATE usuarios SET activo = false WHERE id = :id', ['id' => $id]));
        $ctx = new AuditContext();
        $ctx->origin = 'CLI';
        $ctx->setActor(null, GodRecovery::ACTOR, null);
        $this->assertSqlState('42501', self::web(), fn ($db) => (new AuditLogger($db, $ctx))->record('emergencia.god_reset_password', 'usuarios', $id));
        self::assertSame($viejo, (string) $this->owner()->fetchValue('SELECT password_hash FROM usuarios WHERE id = :id', ['id' => $id]));
    }
}
