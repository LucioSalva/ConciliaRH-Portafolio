<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Integration;

use ConciliaRH\Core\Migrator;
use ConciliaRH\Domain\Audit\AuditRepository;
use ConciliaRH\Tests\Support\DatabaseTestCase;
use RuntimeException;

final class DatabaseSecurityTest extends DatabaseTestCase
{
    public function testAllMigrationsAppliedWithChecksums(): void
    {
        $rows = $this->owner()->fetchAll('SELECT version, checksum FROM schema_migraciones ORDER BY version');
        self::assertSame(['0001', '0002', '0003', '0004', '0005', '0006', '0007', '0008', '0009', '0010', '0011', '0012', '0013', '0014', '0015', '0016', '0017', '0018', '0019', '0020', '0021', '0022', '0023', '0024', '0025', '0026', '0027', '0028', '0029'], array_column($rows, 'version'));
        foreach ($rows as $r) {
            $file = glob(dirname(__DIR__, 2) . '/database/migrations/' . $r['version'] . '_*.up.sql')[0];
            self::assertSame(hash_file('sha256', $file), $r['checksum'], 'Migración aplicada modificada: ' . $r['version']);
        }
    }

    public function testMigratorRefusesModifiedMigrationAndDownInProduction(): void
    {
        $dir = sys_get_temp_dir() . '/crh-mig-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/0001_seguridad.up.sql', '-- contenido distinto');
        try {
            (new Migrator($this->owner(), $dir, false))->up();
            self::fail('Debió detectar el checksum distinto');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('checksum', $e->getMessage());
        }
        try {
            (new Migrator($this->owner(), $dir, true))->down();
            self::fail('down() debe estar bloqueado en producción');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('producción', $e->getMessage());
        }
        unlink($dir . '/0001_seguridad.up.sql');
        rmdir($dir);
    }

    public function testSeededRolesAndLeastPrivilegeMapping(): void
    {
        $db = self::web();
        self::assertSame(['ADMIN', 'CONSULTA', 'GOD'], array_column($db->fetchAll('SELECT codigo FROM roles WHERE es_sistema ORDER BY codigo'), 'codigo'));
        self::assertSame(['ADMIN', 'GOD'], array_column($db->fetchAll('SELECT codigo FROM roles WHERE protegido ORDER BY codigo'), 'codigo'));
        self::assertSame(1, (int) $db->fetchValue("SELECT count(*) FROM rol_permiso rp JOIN roles r ON r.id = rp.rol_id JOIN permisos p ON p.id = rp.permiso_id
            WHERE r.codigo = 'ADMIN' AND p.codigo = 'empleados.fusionar_conflictos_aprobar'"));
        $godOnly = array_column($db->fetchAll('SELECT codigo FROM permisos WHERE solo_god ORDER BY codigo'), 'codigo');
        self::assertSame(['auditoria.administrar', 'catalogos.administrar', 'configuracion.editar', 'procesos.diagnostico', 'roles.administrar'], $godOnly);
        self::assertSame(0, (int) $db->fetchValue(
            "SELECT count(*) FROM rol_permiso rp JOIN roles r ON r.id = rp.rol_id JOIN permisos p ON p.id = rp.permiso_id WHERE p.solo_god AND r.codigo <> 'GOD'"
        ));
        self::assertSame(
            (int) $db->fetchValue('SELECT count(*) FROM permisos'),
            (int) $db->fetchValue("SELECT count(*) FROM rol_permiso rp JOIN roles r ON r.id = rp.rol_id WHERE r.codigo = 'GOD'")
        );
        self::assertSame(['analisis.ver', 'archivos.ver', 'asociaciones.ver', 'beneficiarios.ver', 'conciliaciones.ver', 'dashboard.ver', 'empleados.ver',
            'incidencias.ver', 'lotes.ver', 'periodos.ver', 'reportes.ver'], array_column($db->fetchAll(
            "SELECT p.codigo FROM rol_permiso rp JOIN roles r ON r.id = rp.rol_id JOIN permisos p ON p.id = rp.permiso_id WHERE r.codigo = 'CONSULTA' ORDER BY p.codigo"
        ), 'codigo'));
        self::assertSame(0, (int) $db->fetchValue(
            "SELECT count(*) FROM rol_permiso rp JOIN roles r ON r.id = rp.rol_id
              WHERE r.codigo = 'CONSULTA' AND rp.permiso_id NOT IN
                    (SELECT permiso_id FROM rol_permiso x JOIN roles a ON a.id = x.rol_id WHERE a.codigo = 'ADMIN')"
        ));
        self::assertSame(['auditoria.administrar', 'auditoria.ver', 'auditoria.verificar', 'catalogos.administrar', 'conceptos.editar',
            'configuracion.editar', 'configuracion.ver', 'procesos.cancelar', 'procesos.diagnostico', 'procesos.ver_detalle_tecnico',
            'roles.administrar', 'roles.ver', 'sistema.mantenimiento', 'sistema.salud', 'usuarios.cerrar_sesiones', 'usuarios.crear',
            'usuarios.desactivar', 'usuarios.editar', 'usuarios.eliminar', 'usuarios.restablecer_password', 'usuarios.ver'], array_column($db->fetchAll(
            "SELECT p.codigo FROM permisos p WHERE NOT EXISTS (SELECT 1 FROM rol_permiso rp JOIN roles r ON r.id = rp.rol_id
              WHERE rp.permiso_id = p.id AND r.codigo = 'ADMIN') ORDER BY p.codigo"
        ), 'codigo'), 'ADMIN no administra usuarios, roles, auditoría, configuración, conceptos ni el sistema');
    }

    public function testDatabaseRejectsGodOnlyPermissionOnOtherRoles(): void
    {
        $sql = "INSERT INTO rol_permiso (rol_id, permiso_id) SELECT r.id, p.id FROM roles r, permisos p WHERE r.codigo = 'ADMIN' AND p.codigo = 'roles.administrar'";
        $this->assertSqlState('42501', self::web(), fn ($db) => $db->execute($sql));
        $this->asFixtureGod(fn () => $this->assertSqlState('23514', self::web(), fn ($db) => $db->execute($sql)));
    }

    public function testUsernameConstraints(): void
    {
        $this->createUser('ana.lopez', ['CONSULTA']);
        $this->assertSqlState('23505', self::web(), fn ($db) => $this->createUser('ana.lopez', []));
        $this->assertSqlState('23514', self::web(), fn ($db) => $this->createUser('Ana.Lopez', []));
        $this->assertSqlState('23514', self::web(), fn ($db) => $this->createUser("x' OR 1=1", []));
    }

    public function testWebRoleCannotDeleteUsersOrTouchMigrations(): void
    {
        $this->assertSqlState('42501', self::web(), fn ($db) => $db->execute('DELETE FROM usuarios WHERE false'));
        $this->assertSqlState('42501', self::web(), fn ($db) => $db->execute('SELECT * FROM schema_migraciones'));
        $this->assertSqlState('42501', self::web(), fn ($db) => $db->execute('CREATE TABLE intruso (id int)'));
    }

    public function testColumnLevelPrivilegesProtectSecurityFlags(): void
    {
        $web = self::web();
        $this->assertSqlState('42501', $web, fn ($db) => $db->execute("UPDATE roles SET protegido = false WHERE codigo = 'ADMIN'"));
        $this->assertSqlState('42501', $web, fn ($db) => $db->execute("UPDATE roles SET codigo = 'X' WHERE codigo = 'CONSULTA'"));
        $this->assertSqlState('42501', $web, fn ($db) => $db->execute("UPDATE configuracion SET solo_god = false"));
        $this->assertSqlState('42501', $web, fn ($db) => $db->execute("UPDATE configuracion SET maximo = 1000000"));
        $this->assertSqlState('42501', $web, fn ($db) => $db->execute("UPDATE usuarios SET username = 'otro' WHERE false"));
        $this->assertSqlState('42501', $web, fn ($db) => $db->execute("INSERT INTO roles (codigo, nombre, es_sistema) VALUES ('FALSO', 'Falso', true)"));
        $this->assertSqlState('42501', $this->worker(), fn ($db) => $db->execute("UPDATE procesos SET parametros = '{}' WHERE false"));
        $this->assertSqlState('42501', $this->worker(), fn ($db) => $db->execute('UPDATE procesos SET creado_por = NULL WHERE false'));
        $this->asFixtureGod(fn () => $web->execute("UPDATE configuracion SET valor = valor WHERE clave = 'conciliacion.tolerancia_monetaria'"));
        $this->worker()->execute("UPDATE procesos SET mensaje = mensaje WHERE false");
        $this->addToAssertionCount(2);
    }

    public function testWorkerRoleHasNoAccessToCredentials(): void
    {
        $this->assertSqlState('42501', $this->worker(), fn ($db) => $db->execute('SELECT password_hash FROM usuarios'));
        $this->assertSqlState('42501', $this->worker(), fn ($db) => $db->execute('SELECT * FROM sesiones'));
        $this->assertSqlState('42501', $this->worker(), fn ($db) => $db->execute('SELECT * FROM auditoria'));
        $this->assertSqlState('42501', $this->worker(), fn ($db) => $db->execute('DELETE FROM procesos WHERE false'));
        $this->assertSqlState('42501', $this->worker(), fn ($db) => $db->execute("INSERT INTO procesos (tipo) VALUES ('DIAGNOSTICO')"));
    }

    public function testAuditIsHashChainedAndVerifiable(): void
    {
        $audit = $this->audit();
        $audit->record('prueba.uno', 'x', 1, ['a' => 1], ['a' => 2]);
        $audit->record('prueba.dos', 'x', 2);
        $rows = self::web()->fetchAll("SELECT secuencia, hash, hash_prev FROM auditoria WHERE accion LIKE 'prueba.%' ORDER BY secuencia");
        self::assertCount(2, $rows);
        self::assertSame($rows[0]['hash'], $rows[1]['hash_prev']);
        self::assertSame($rows[0]['secuencia'] + 1, $rows[1]['secuencia']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $rows[1]['hash']);
        self::assertTrue((new AuditRepository(self::web()))->verify()['integra']);
    }

    public function testAuditCannotBeModifiedByApplicationRoles(): void
    {
        $this->audit()->record('prueba.inmutable');
        $this->assertSqlState('42501', self::web(), fn ($db) => $db->execute("UPDATE auditoria SET accion = 'otra.cosa'"));
        $this->assertSqlState('42501', self::web(), fn ($db) => $db->execute('DELETE FROM auditoria'));
        $this->assertSqlState('42501', self::web(), fn ($db) => $db->execute('TRUNCATE auditoria'));
    }

    public function testAuditTriggerBlocksEvenTheOwner(): void
    {
        $owner = $this->owner();
        $owner->execute("INSERT INTO auditoria (accion, hash) VALUES ('prueba.owner', repeat('0', 64))");
        $this->assertSqlState('42501', $owner, fn ($db) => $db->execute("UPDATE auditoria SET accion = 'x.y' WHERE accion = 'prueba.owner'"));
        $this->assertSqlState('42501', $owner, fn ($db) => $db->execute("DELETE FROM auditoria WHERE accion = 'prueba.owner'"));
        $this->assertSqlState('42501', $owner, fn ($db) => $db->execute('TRUNCATE auditoria'));
    }

    public function testTamperingIsDetectedByChainVerification(): void
    {
        $owner = $this->owner();
        $owner->execute("INSERT INTO auditoria (accion, valor_nuevo, hash) VALUES ('prueba.original', '{\"monto\":\"100.00\"}', repeat('0', 64))");
        $owner->execute("INSERT INTO auditoria (accion, hash) VALUES ('prueba.siguiente', repeat('0', 64))");
        self::assertTrue((bool) $owner->fetchValue('SELECT integra FROM fn_auditoria_verificar()'));
        $owner->execute("SET LOCAL conciliarh.auditoria_mantenimiento = 'on'");
        $owner->execute("UPDATE auditoria SET valor_nuevo = '{\"monto\":\"999.00\"}' WHERE accion = 'prueba.original'");
        $r = $owner->fetchOne('SELECT * FROM fn_auditoria_verificar()');
        self::assertFalse((bool) $r['integra']);
        self::assertNotNull($r['primer_secuencia_invalida']);
    }

    public function testAuditChainV2CoversUserAgent(): void
    {
        $owner = $this->owner();
        $owner->execute("INSERT INTO auditoria (accion, user_agent, hash) VALUES ('prueba.ua', 'Navegador A', repeat('0', 64))");
        self::assertSame(2, (int) $owner->fetchValue("SELECT hash_version FROM auditoria WHERE accion = 'prueba.ua'"));
        self::assertTrue((bool) $owner->fetchValue('SELECT integra FROM fn_auditoria_verificar()'));
        $owner->execute("SET LOCAL conciliarh.auditoria_mantenimiento = 'on'");
        $owner->execute("UPDATE auditoria SET user_agent = 'Navegador B' WHERE accion = 'prueba.ua'");
        self::assertFalse((bool) $owner->fetchValue('SELECT integra FROM fn_auditoria_verificar()'));
    }

    public function testWorkerCanWriteAuditButNotReadIt(): void
    {
        $this->worker()->execute("INSERT INTO auditoria (origen, accion, hash) VALUES ('WORKER', 'prueba.worker', repeat('0', 64))");
        $this->addToAssertionCount(1);
    }
}
