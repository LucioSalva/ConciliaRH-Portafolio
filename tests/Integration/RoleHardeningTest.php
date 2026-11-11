<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Integration;

use ConciliaRH\Tests\Support\DatabaseTestCase;
use ConciliaRH\Tests\Support\IdentityScenario;

/**
 * Migración 0023 (FASE 9): la BD restringe a los roles de SERVICIO y valida el RBAC y la configuración, aunque PHP fallara o
 * se filtraran las credenciales de un rol. Cada caso se ejecuta con el rol real que debe quedar bloqueado. Datos ficticios.
 */
final class RoleHardeningTest extends DatabaseTestCase
{
    use IdentityScenario;

    /** H-1: worker y scheduler sólo auditan como servicio; nunca a nombre de una persona ni con origen WEB. */
    public function testWorkerCannotForgeAuditRows(): void
    {
        $this->assertServiceCannotForgeAudit($this->worker());
    }

    public function testSchedulerCannotForgeAuditRows(): void
    {
        // (una prueba por servicio: la auditoría encadenada serializa las inserciones y cada conexión de prueba es una transacción abierta)
        $this->assertServiceCannotForgeAudit($this->scheduler());
    }

    private function assertServiceCannotForgeAudit(\ConciliaRH\Core\Database $db): void
    {
        $god = $this->createUser('god.audit23', ['GOD']);
        {
            $this->assertSqlState('42501', $db, fn ($d) => $d->execute(
                "INSERT INTO auditoria (usuario_id, origen, accion, hash) VALUES (:u, 'WEB', 'cuentas.confirmar', repeat('0', 64))", ['u' => $god]));
            $this->assertSqlState('42501', $db, fn ($d) => $d->execute(
                "INSERT INTO auditoria (usuario_id, origen, accion, hash) VALUES (:u, 'WORKER', 'prueba', repeat('0', 64))", ['u' => $god]));
            $this->assertSqlState('42501', $db, fn ($d) => $d->execute(
                "INSERT INTO auditoria (origen, roles, accion, hash) VALUES ('WORKER', 'GOD', 'prueba', repeat('0', 64))"));
            $this->assertSqlState('42501', $db, fn ($d) => $d->execute(
                "INSERT INTO auditoria (origen, accion, hash) VALUES ('SISTEMA', 'auth.sesion_vinculada', repeat('0', 64))"));
            // Lo legítimo sigue funcionando (el servicio no lee la auditoría: sólo inserta)
            $db->execute("INSERT INTO auditoria (origen, accion, hash) VALUES ('WORKER', 'prueba.servicio', repeat('0', 64))");
        }
    }

    /** M-1 y M-2: el worker no fabrica confirmaciones manuales ni reescribe el cifrado de cuentas. */
    public function testWorkerCannotFabricateManualConfirmationsOrRewriteCiphertext(): void
    {
        $w = $this->worker();
        $this->assertSqlState('42501', $w, fn ($d) => $d->execute(
            "INSERT INTO cuentas_bancarias (empleado_id, uso, tipo, cuenta_hmac, cuenta_ultimos4, cuenta_cifrada, key_id, origen, confirmado_por, confirmado_at)
             VALUES (1, 'NOMINA', 'CLABE', repeat('a', 64), '1234', '\\x00', 'k1', 'CONFIRMACION_MANUAL', 1, now())"));
        $this->assertSqlState('42501', $w, fn ($d) => $d->execute(
            "INSERT INTO beneficiarios_pension (nombre_original, nombre_normalizado, origen, confirmado_por, confirmado_at)
             VALUES ('FICTICIA', 'FICTICIA', 'CONFIRMACION_MANUAL', 1, now())"));
        $this->assertSqlState('42501', $w, fn ($d) => $d->execute("UPDATE cuentas_bancarias SET cuenta_cifrada = '\\x00' WHERE false"));
        $this->assertSqlState('42501', $w, fn ($d) => $d->execute("UPDATE cuentas_bancarias SET key_id = 'k9' WHERE false"));
        $this->assertSqlState('42501', $w, fn ($d) => $d->execute("UPDATE staging_registros SET cuenta_cifrada = NULL WHERE false"));
        // Atribuir a una persona una decisión que el registro no tiene (ni como decisión nueva ni como «traslado»)
        $this->assertSqlState('42501', $w, fn ($d) => $d->execute(
            "INSERT INTO asociaciones_historial (registro_tipo, registro_id, accion, estado_nuevo, usuario_id) VALUES ('RRHH', 0, 'MANUAL', 'ASOCIADO_MANUAL', 1)"));
        $this->assertSqlState('42501', $w, fn ($d) => $d->execute(
            "INSERT INTO asociaciones_historial (registro_tipo, registro_id, accion, estado_nuevo, usuario_id) VALUES ('RRHH', 0, 'TRASLADO', 'ASOCIADO_MANUAL', 1)"));
        // Historial del maestro: nada «MANUAL» ni a nombre de una persona
        $this->assertSqlState('42501', $w, fn ($d) => $d->execute(
            "INSERT INTO empleado_historial (empleado_id, campo, valor_anterior, valor_nuevo, fuente) VALUES (1, 'curp', 'X', 'Y', 'MANUAL')"));
        $this->assertSqlState('42501', $w, fn ($d) => $d->execute(
            "INSERT INTO empleado_historial (empleado_id, campo, valor_nuevo, fuente, usuario_id) VALUES (1, 'activo', 'true', 'RRHH_TABULADO', 1)"));
    }

    /** Revisión final (M-3): el worker no reasigna una decisión humana; sólo puede pasarla a CONFLICTO sin persona. */
    public function testWorkerCannotRepointHumanDecisions(): void
    {
        $s = $this->identityScenario();
        $godDecide = $this->createUser('god.decide', ['GOD']);
        $reg = (int) $s['sug'];
        $prev = self::web()->fetchOne('SELECT empleado_id, asociacion_estado, asociado_por, asociado_at FROM registros_tesoreria WHERE id = :r', ['r' => $reg]);
        $o = $this->committedOwner(static fn ($db) => $db->execute(
            'UPDATE registros_tesoreria SET empleado_id = :e, asociacion_estado = :st, asociado_por = :u, asociado_at = :at WHERE id = :r',
            ['e' => $prev['empleado_id'], 'st' => $prev['asociacion_estado'], 'u' => $prev['asociado_por'], 'at' => $prev['asociado_at'], 'r' => $reg]));
        // Decisión humana (preparada por el propietario, como la dejaría la pantalla de asociaciones)
        $o->execute("UPDATE registros_tesoreria SET empleado_id = :e, asociacion_estado = 'ASOCIADO_MANUAL', asociado_por = :u, asociado_at = now()
                      WHERE id = :r", ['e' => $s['emp1'], 'u' => $godDecide, 'r' => $reg]);
        try {
            $w = $this->worker();
            $this->assertSqlState('42501', $w, fn ($d) => $d->execute('UPDATE registros_tesoreria SET empleado_id = :e WHERE id = :r', ['e' => $s['emp2'], 'r' => $reg]));
            $this->assertSqlState('42501', $w, fn ($d) => $d->execute("UPDATE registros_tesoreria SET asociacion_estado = 'NO_IDENTIFICADO' WHERE id = :r", ['r' => $reg]));
            $w->pdo()->exec('SAVEPOINT conflicto');
            $w->execute("UPDATE registros_tesoreria SET empleado_id = NULL, asociacion_estado = 'CONFLICTO', asociado_por = NULL, asociado_at = NULL WHERE id = :r", ['r' => $reg]);
            self::assertSame('CONFLICTO', $w->fetchValue('SELECT asociacion_estado FROM registros_tesoreria WHERE id = :r', ['r' => $reg]),
                'Pasar a CONFLICTO sin persona sí está permitido (la cuenta resultó de otra persona)');
            $w->pdo()->exec('ROLLBACK TO SAVEPOINT conflicto');   // libera el candado de la fila antes de restaurarla
        } finally {
            // Se restaura aquí (antes de que tearDown borre el usuario GOD de prueba al que apunta asociado_por)
            $o->execute('UPDATE registros_tesoreria SET empleado_id = :e, asociacion_estado = :st, asociado_por = :u, asociado_at = :at WHERE id = :r',
                ['e' => $prev['empleado_id'], 'st' => $prev['asociacion_estado'], 'u' => $prev['asociado_por'], 'at' => $prev['asociado_at'], 'r' => $reg]);
        }
    }

    /** M-3: RBAC con permiso en la BD; los permisos del rol GOD no se tocan desde la web, ni siquiera siendo GOD. */
    public function testRbacIsEnforcedInTheDatabase(): void
    {
        $web = self::web();
        $consulta = $this->createUser('consulta.rbac', ['CONSULTA']);
        $this->actingAs($this->actor($consulta));
        $this->assertSqlState('42501', $web, fn ($d) => $d->execute("INSERT INTO roles (codigo, nombre) VALUES ('NUEVO_X', 'Nuevo')"));
        $this->assertSqlState('42501', $web, fn ($d) => $d->execute(
            "INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = 'ADMIN'", ['u' => $consulta]));
        $this->assertSqlState('42501', $web, fn ($d) => $d->execute(
            "DELETE FROM rol_permiso WHERE rol_id = (SELECT id FROM roles WHERE codigo = 'CONSULTA')"));
        $this->asFixtureGod(function () use ($web): void {
            $this->assertSqlState('42501', $web, fn ($d) => $d->execute(
                "DELETE FROM rol_permiso WHERE rol_id = (SELECT id FROM roles WHERE codigo = 'GOD')"));
            // GOD sí administra los demás roles
            $web->execute("INSERT INTO roles (codigo, nombre) VALUES ('AUDITOR_X', 'Auditor ficticio')");
        });
        self::assertSame(1, (int) $web->fetchValue("SELECT count(*) FROM roles WHERE codigo = 'AUDITOR_X'"));
    }

    /** M-4: una cuenta GOD sólo cambia sus propias credenciales; todo cambio queda auditado POR LA BD. */
    public function testGodAccountsAndCredentialChangesAreProtected(): void
    {
        $web = self::web();
        $god = $this->createUser('god.cred', ['GOD']);
        $admin = $this->createUser('admin.cred', ['ADMIN']);
        $this->actingAs($this->actor($admin));
        foreach (["password_hash = repeat('y', 40)", 'activo = false', 'deleted_at = now()', "username = 'otro.god'"] as $set) {
            $this->assertSqlState('42501', $web, fn ($d) => $d->execute("UPDATE usuarios SET $set WHERE id = :g", ['g' => $god]));
        }
        $this->actingAs($this->actor($god));
        $web->execute("UPDATE usuarios SET password_hash = repeat('z', 40) WHERE id = :g", ['g' => $god]);   // GOD cambia la suya
        $a = $web->fetchOne("SELECT usuario_id, origen, contexto FROM auditoria WHERE accion = 'usuarios.cambio_credenciales' AND entidad_id = :g
                              ORDER BY secuencia DESC LIMIT 1", ['g' => (string) $god]);
        self::assertSame([$god, 'SISTEMA'], [(int) $a['usuario_id'], $a['origen']]);
        self::assertStringContainsString('password', (string) $a['contexto']);
        self::assertStringNotContainsString('$argon2', (string) $a['contexto'], 'Nunca el hash');
        // El ADMIN sí cambia el estado de un usuario normal, y queda auditado por la BD con su autoría
        $normal = $this->createUser('normal.cred', ['CONSULTA']);
        $this->actingAs($this->actor($admin));
        $web->execute('UPDATE usuarios SET activo = false WHERE id = :u', ['u' => $normal]);
        self::assertSame($admin, (int) $web->fetchValue("SELECT usuario_id FROM auditoria WHERE accion = 'usuarios.cambio_credenciales'
                                                         AND entidad_id = :u ORDER BY secuencia DESC LIMIT 1", ['u' => (string) $normal]));
    }

    /** M-6: configuración con permiso, exclusividad GOD, tipo y rango en la BD. */
    public function testConfigurationIsValidatedInTheDatabase(): void
    {
        $web = self::web();
        $admin = $this->createUser('admin.cfg', ['ADMIN']);
        $this->actingAs($this->actor($admin));
        $this->assertSqlState('42501', $web, fn ($d) => $d->execute("UPDATE configuracion SET valor = '1000' WHERE clave = 'conciliacion.tolerancia_monetaria'"));
        $this->asFixtureGod(function () use ($web): void {
            $this->assertSqlState('23514', $web, fn ($d) => $d->execute("UPDATE configuracion SET valor = '1000000' WHERE clave = 'conciliacion.tolerancia_monetaria'"));
            $this->assertSqlState('23514', $web, fn ($d) => $d->execute("UPDATE configuracion SET valor = 'mucho' WHERE clave = 'conciliacion.tolerancia_monetaria'"));
            $this->assertSqlState('23514', $web, fn ($d) => $d->execute("UPDATE configuracion SET valor = '1.5' WHERE tipo = 'entero' AND clave = (SELECT min(clave) FROM configuracion WHERE tipo = 'entero')"));
            $web->execute("UPDATE configuracion SET valor = valor WHERE clave = 'conciliacion.tolerancia_monetaria'");
        });
        $this->addToAssertionCount(1);
    }

    /** L-4 y L-5: la web sólo encola sus tipos y sólo cancela; cada servicio registra sólo sus ejecuciones. */
    public function testQueueAndHealthRecordsAreRestrictedPerRole(): void
    {
        $web = self::web();
        $u = $this->createUser('admin.cola', ['ADMIN']);
        $this->actingAs($this->actor($u));
        $this->assertSqlState('42501', $web, fn ($d) => $d->execute(
            "INSERT INTO procesos (tipo, parametros, creado_por) VALUES ('CONCILIAR_LOTE', '{}', :u)", ['u' => $u]));
        $this->assertSqlState('42501', $web, fn ($d) => $d->execute(
            "INSERT INTO procesos (tipo, parametros, creado_por) VALUES ('DIAGNOSTICO', '{}', NULL)"));
        $id = (int) $web->fetchValue("INSERT INTO procesos (tipo, parametros, creado_por) VALUES ('DIAGNOSTICO', '{}', :u) RETURNING id", ['u' => $u]);
        $this->assertSqlState('42501', $web, fn ($d) => $d->execute("UPDATE procesos SET estado = 'COMPLETADO' WHERE id = :i", ['i' => $id]));
        $web->execute("UPDATE procesos SET estado = 'CANCELADO', cancelado_por = :u, finalizado_at = now() WHERE id = :i", ['u' => $u, 'i' => $id]);
        $this->assertSqlState('42501', $this->scheduler(), fn ($d) => $d->fetchValue(
            "SELECT fn_sistema_ejecucion('RESPALDO', 'CLI', now(), 'OK', '{}'::jsonb)"));
        self::assertGreaterThan(0, (int) $this->scheduler()->fetchValue("SELECT fn_sistema_ejecucion('SCHEDULER', 'CLI', now(), 'OK', '{}'::jsonb)"));
    }

    /** L-1, L-6, I-1: catálogo sin EXECUTE de PUBLIC en funciones SECURITY DEFINER, search_path fijo y esquema cerrado. */
    public function testCatalogHygiene(): void
    {
        $o = $this->owner();
        self::assertSame([], array_column($o->fetchAll(
            "SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
              WHERE n.nspname = 'public' AND p.prosecdef AND (p.proacl IS NULL OR p.proacl::text ~ '(^|[{,])=X')"), 'proname'),
            'Ninguna función SECURITY DEFINER ejecutable por PUBLIC');
        self::assertSame([], array_column($o->fetchAll(
            "SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace JOIN pg_language l ON l.oid = p.prolang
              WHERE n.nspname = 'public' AND l.lanname = 'plpgsql' AND p.proconfig IS NULL"), 'proname'),
            'Toda función plpgsql con search_path fijo');
        self::assertFalse((bool) $o->fetchValue("SELECT has_schema_privilege('public', 'public', 'USAGE')"), 'PUBLIC sin USAGE en el esquema');
    }
}
