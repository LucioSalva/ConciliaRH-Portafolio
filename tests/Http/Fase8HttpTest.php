<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Http;

use ConciliaRH\Core\Database;
use ConciliaRH\Tests\Support\HttpTestCase;
use ConciliaRH\Tests\Support\ConciliationScenario;

final class Fase8HttpTest extends HttpTestCase
{
    use ConciliationScenario;   // incluye IdentityScenario

    public const CLABE_PEN = '002180000000077777';

    private function committedUser(string $role): array
    {
        $name = strtolower($role) . '.f8' . bin2hex(random_bytes(3));
        $o = $this->committedOwner(static function (Database $db): void {
            $db->execute("DELETE FROM usuario_rol WHERE usuario_id IN (SELECT id FROM usuarios WHERE username LIKE '%.f8%')");
            $db->execute("DELETE FROM usuarios WHERE username LIKE '%.f8%'");
        });
        $id = (int) $o->fetchValue('INSERT INTO usuarios (username, nombre, password_hash) VALUES (:u, :n, :h) RETURNING id',
            ['u' => $name, 'n' => 'Usuario ' . $role, 'h' => self::passwordHash()]);
        $o->execute('INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = :r', ['u' => $id, 'r' => $role]);
        return ['id' => $id, 'username' => $name];
    }

    private function notify(int $user, string $titulo, string $url = '/conciliaciones', string $clave = ''): int
    {
        $o = $this->committedOwner(static function (): void {
        });
        $o->fetchValue("SELECT fn_notificar(:u, 'SISTEMA', 'NORMAL', :t, 'Mensaje de prueba ficticio', :url, NULL, NULL, :c)",
            ['u' => $user, 't' => $titulo, 'url' => $url, 'c' => $clave !== '' ? $clave : null]);
        return (int) $o->fetchValue('SELECT max(id) FROM notificaciones WHERE usuario_id = :u', ['u' => $user]);
    }

    public function testNotificationsAreStrictlyPersonalAndSafe(): void
    {
        $a = $this->committedUser('ADMIN');
        $b = $this->committedUser('CONSULTA');
        $xss = '<script>alert(1)</script> Conciliación ficticia';
        $na = $this->notify($a['id'], $xss, '/conciliaciones/1');
        $nb = $this->notify($b['id'], 'Aviso ajeno ficticio', '/reportes');
        $this->loginAs($a['username']);
        $page = $this->get('/notificaciones')->body();
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page);
        self::assertStringNotContainsString('<script>alert(1)', $page);
        self::assertStringNotContainsString('Aviso ajeno ficticio', $page);
        $api = json_decode($this->get('/api/notificaciones/resumen')->body(), true);
        self::assertSame(1, $api['data']['no_leidas']);
        self::assertSame([$na], array_column($api['data']['ultimas'], 'id'));
        self::assertSame(404, $this->post("/notificaciones/$nb/abrir")->status());
        self::assertSame(404, $this->post("/notificaciones/$nb/eliminar")->status());
        self::assertSame(0, (int) self::web()->fetchValue('SELECT count(*) FROM notificaciones WHERE id = :n', ['n' => $nb]), 'RLS: invisible para A');
        self::web()->execute('UPDATE notificaciones SET leida_at = now() WHERE id = :n', ['n' => $nb]);
        self::web()->execute('DELETE FROM notificaciones WHERE id = :n', ['n' => $nb]);
        $o = $this->committedOwner(static function (): void {
        });
        self::assertSame(1, (int) $o->fetchValue('SELECT count(*) FROM notificaciones WHERE id = :n', ['n' => $nb]), 'RLS: A no la borró');
        self::assertNull($o->fetchValue('SELECT leida_at FROM notificaciones WHERE id = :n', ['n' => $nb]), 'RLS: A no la marcó');
        $this->assertSqlState('42501', self::web(), fn (Database $db) => $db->execute(
            "INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje) VALUES (:u, 'SISTEMA', 'Falsa', 'x')", ['u' => $b['id']]));
        $this->assertSqlState('42501', self::web(), fn (Database $db) => $db->fetchValue(
            "SELECT fn_notificar(:u, 'SISTEMA', 'NORMAL', 'Falsa', 'x', NULL, NULL, NULL, NULL)", ['u' => $b['id']]));
        $this->assertSqlState('42501', self::web(), fn (Database $db) => $db->execute('UPDATE notificaciones SET usuario_id = :u WHERE id = :n',
            ['u' => $b['id'], 'n' => $na]));
        self::assertSame(403, $this->request('POST', "/notificaciones/$na/abrir", [], [], false)->status());
        self::assertSame(403, $this->request('POST', '/notificaciones/leer-todas', [], [], false)->status());
        $r = $this->post("/notificaciones/$na/abrir");
        self::assertSame([303, '/conciliaciones/1'], [$r->status(), $r->header('Location')]);
        self::assertSame(0, json_decode($this->get('/api/notificaciones/resumen')->body(), true)['data']['no_leidas']);
        $this->assertSqlState('23514', $this->owner(), fn (Database $db) => $db->fetchValue(
            "SELECT fn_notificar(:u, 'SISTEMA', 'NORMAL', 'Enlace externo', 'x', '//evil.example/x', NULL, NULL, NULL)", ['u' => $a['id']]));
        $this->assertSqlState('23514', $this->owner(), fn (Database $db) => $db->fetchValue(
            "SELECT fn_notificar(:u, 'SISTEMA', 'NORMAL', 'Enlace js', 'x', 'javascript:alert(1)', NULL, NULL, NULL)", ['u' => $a['id']]));
        $this->notify($a['id'], 'Repetida ficticia', '/reportes', 'normalizacion:77');
        $this->notify($a['id'], 'Repetida ficticia', '/reportes', 'normalizacion:77');
        self::assertSame(1, (int) $o->fetchValue("SELECT count(*) FROM notificaciones WHERE usuario_id = :u AND clave = 'normalizacion:77'", ['u' => $a['id']]));
        self::assertSame(303, $this->post('/notificaciones/leer-todas')->status());
        self::assertSame(0, json_decode($this->get('/api/notificaciones/resumen')->body(), true)['data']['no_leidas']);
        self::assertSame(303, $this->post("/notificaciones/$na/eliminar")->status());
        self::assertSame(303, $this->post('/notificaciones/eliminar-leidas')->status());
        self::assertStringContainsString('Sin notificaciones', $this->get('/notificaciones')->body());
        foreach (["' OR '1'='1", "1; SELECT pg_sleep(3)--", "NO_LEIDA' UNION SELECT password_hash FROM usuarios--"] as $p) {
            $t = microtime(true);
            $res = $this->get('/notificaciones?estado=' . rawurlencode($p) . '&tipo=' . rawurlencode($p));
            self::assertSame(200, $res->status());
            self::assertLessThan(2.5, microtime(true) - $t);
            self::assertStringNotContainsString('$argon2', $res->body());
        }
    }

    public function testNotificationTriggersCarryNoPersonalData(): void
    {
        $s = $this->identityScenario();
        $o = $this->committedOwner(static function (): void {
        });
        $godUser = 'god.f8n' . bin2hex(random_bytes(2));
        $god = $this->createUser($godUser, ['GOD']);
        $o = $this->owner();
        $arch = (int) $o->fetchValue("SELECT max(id) FROM archivos_importados");
        $o->execute("UPDATE archivos_importados SET estado = 'ANALIZANDO' WHERE id = :a AND estado NOT IN ('ANALIZANDO')", ['a' => $arch]);
        $o->execute("UPDATE archivos_importados SET estado = 'REVISION_MANUAL' WHERE id = :a", ['a' => $arch]);
        $n = $o->fetchAll("SELECT titulo, mensaje, url FROM notificaciones WHERE tipo = 'IMPORTACION_REVISION' AND entidad_id = :a", ['a' => $arch]);
        self::assertNotSame([], $n);
        foreach ($n as $x) {
            self::assertSame('/importaciones/' . $arch, $x['url']);
            self::assertDoesNotMatchRegularExpression('/FICTICI|[A-Z]{4}\d{6}|\d{18}/', $x['titulo'] . $x['mensaje'], 'Sin nombres, RFC ni CLABE');
        }
        self::assertGreaterThan(0, (int) $o->fetchValue("SELECT count(*) FROM notificaciones WHERE usuario_id = :g AND tipo = 'IMPORTACION_REVISION'", ['g' => $god]));
        self::assertIsArray($s);
    }

    public function testSystemHealthIsAuthorizedAndRevealsNoSecrets(): void
    {
        $pub = json_decode($this->get('/api/health')->body(), true);
        self::assertSame(['estado' => 'ok'], $pub['data']);
        self::assertNotSame(200, $this->get('/api/sistema/salud')->status());
        foreach (['CONSULTA', 'ADMIN'] as $role) {
            $u = strtolower($role) . '.sal' . bin2hex(random_bytes(2));
            $this->createUser($u, [$role]);
            $this->loginAs($u);
            self::assertSame(403, $this->get('/sistema/salud')->status(), $role);
            self::assertSame(403, $this->get('/api/sistema/salud')->status(), $role);
            self::assertSame(403, $this->post('/sistema/salud/almacenamiento')->status(), $role);
        }
        $god = 'god.sal' . bin2hex(random_bytes(2));
        $this->createUser($god, ['GOD']);
        $this->loginAs($god);
        $body = $this->get('/sistema/salud')->body();
        self::assertStringContainsString('Salud del sistema', $body);
        foreach (['PHP', 'PostgreSQL', 'Migraciones', 'Scheduler', 'Cola de trabajos', 'Espacio en disco'] as $t) {
            self::assertStringContainsString($t, $body, $t);
        }
        foreach (array_filter([getenv('APP_KEY'), getenv('DB_PASSWORD'), getenv('REPORT_ENC_KEYS'), getenv('ACCOUNT_ENC_KEYS'), getenv('DB_MIGRATE_PASSWORD')]) as $secret) {
            self::assertStringNotContainsString((string) $secret, $body, 'Sin secretos en la página de salud');
        }
        self::assertStringNotContainsString('/var/www', $body, 'Sin rutas absolutas');
        $api = json_decode($this->get('/api/sistema/salud')->body(), true);
        self::assertContains($api['data']['general'], ['OK', 'ADVERTENCIA', 'ERROR']);
        self::assertArrayHasKey('migraciones', $api['data']['verificaciones']);
        self::assertSame(403, $this->request('POST', '/sistema/salud/almacenamiento', [], [], false)->status(), 'CSRF');
        self::assertSame(303, $this->post('/sistema/salud/almacenamiento')->status());
        self::assertSame(1, (int) self::web()->fetchValue("SELECT count(*) FROM sistema_ejecuciones WHERE tipo = 'ALMACENAMIENTO' AND origen = 'WEB' AND fin_at >= now()"));
        self::assertSame(429, $this->post('/sistema/salud/almacenamiento')->status());
        $this->assertSqlState('42501', self::web(), fn (Database $db) => $db->execute(
            "INSERT INTO sistema_ejecuciones (tipo, origen, inicio_at, resultado) VALUES ('RESPALDO', 'CLI', now(), 'OK')"));
    }

    private function mediaPension(array $s): void
    {
        [$h, $c, $k] = self::protect(self::CLABE_PEN);
        $this->committedOwner(static function (): void {
        })->execute(
            "UPDATE registros_tesoreria SET asociacion_estado = 'SUGERENCIA', asociacion_confianza = 'MEDIA', tipo_cuenta = 'CLABE', cuenta_hmac = :h,
                    cuenta_hmac_key_id = fn_hmac_key_activa(), cuenta_ultimos4 = '7777', cuenta_cifrada = CAST(:c AS bytea), cuenta_key_id = :k,
                    asociacion_evidencia = CAST(:ev AS jsonb) WHERE id = :id",
            ['h' => $h, 'c' => $c, 'k' => $k, 'id' => $s['pen'],
             'ev' => json_encode(['motivo' => 'IMPORTE_RECURRENTE_MISMA_CLABE', 'candidatos' => [$s['emp2']], 'categoria' => 'MEDIA',
                                  'senales' => ['IMPORTE_RETENCION_UNICA', 'IMPORTE_RECURRENTE_MISMA_CLABE'], 'detalle_senales' => ['clabe_otros_periodos' => 1]])]);
    }

    public function testMediumPensionReviewRequiresAHumanDecision(): void
    {
        $s = $this->identityScenario();
        $this->mediaPension($s);
        $v = static fn () => (string) self::web()->fetchValue('SELECT asociacion_version FROM registros_tesoreria WHERE id = :id', ['id' => $s['pen']]);
        $u = 'consulta.pen' . bin2hex(random_bytes(2));
        $this->createUser($u, ['CONSULTA']);
        $this->loginAs($u);
        $list = $this->get('/revision-pensiones')->body();
        self::assertStringContainsString('BENEFICIARIA FICTICIA', $list);
        self::assertStringContainsString('Evidencia media', $list);
        self::assertStringContainsString('••••7777', $list, 'CLABE enmascarada');
        self::assertStringNotContainsString(self::CLABE_PEN, $list, 'Nunca la CLABE completa');
        self::assertSame(403, $this->post("/revision-pensiones/{$s['pen']}/confirmar", ['version' => $v(), 'motivo' => 'Sin permiso'])->status());
        self::assertSame('SUGERENCIA', self::web()->fetchValue('SELECT asociacion_estado FROM registros_tesoreria WHERE id = :id', ['id' => $s['pen']]));
        $a = 'admin.pen' . bin2hex(random_bytes(2));
        $this->createUser($a, ['ADMIN']);
        $this->loginAs($a);
        $show = $this->get("/revision-pensiones/{$s['pen']}")->body();
        self::assertStringContainsString('Historia de esta CLABE', $show);
        self::assertStringContainsString('este pago', $show);
        self::assertStringContainsString('Importe recurrente en la misma CLABE', $show);
        self::assertStringNotContainsString(self::CLABE_PEN, $show);
        self::assertSame(403, $this->request('POST', "/revision-pensiones/{$s['pen']}/pendiente", ['version' => $v(), 'motivo' => 'Sin token'], [], false)->status());
        self::assertSame(303, $this->post("/revision-pensiones/{$s['pen']}/pendiente", ['version' => $v(), 'motivo' => '<b>Falta el oficio</b> ficticio',
            'usuario_id' => '1'])->status());
        self::assertSame('SUGERENCIA', self::web()->fetchValue('SELECT asociacion_estado FROM registros_tesoreria WHERE id = :id', ['id' => $s['pen']]));
        $d = self::web()->fetchOne('SELECT usuario_id, motivo FROM revision_pendientes WHERE registro_tesoreria_id = :id', ['id' => $s['pen']]);
        self::assertNotSame(1, (int) $d['usuario_id'], 'El autor lo fija la sesión, no el formulario');
        self::assertStringContainsString('&lt;b&gt;Falta el oficio&lt;/b&gt;', $this->get("/revision-pensiones/{$s['pen']}")->body());
        $this->post("/revision-pensiones/{$s['pen']}/confirmar", ['version' => '999', 'motivo' => 'Versión equivocada']);
        self::assertSame('SUGERENCIA', self::web()->fetchValue('SELECT asociacion_estado FROM registros_tesoreria WHERE id = :id', ['id' => $s['pen']]));
        self::assertSame(303, $this->post("/revision-pensiones/{$s['pen']}/confirmar", ['version' => $v(), 'motivo' => 'Oficio de pensión ficticio revisado',
            'empleado_id' => (string) $s['emp1']])->status());
        $r = self::web()->fetchOne('SELECT asociacion_estado, empleado_id, beneficiario_id FROM registros_tesoreria WHERE id = :id', ['id' => $s['pen']]);
        self::assertSame(['ASOCIADO_MANUAL', (int) $s['emp2']], [$r['asociacion_estado'], (int) $r['empleado_id']], 'Se asocia al candidato, no a un id inventado');
        self::assertNotNull($r['beneficiario_id']);
        self::assertSame(1, (int) self::web()->fetchValue("SELECT count(*) FROM auditoria WHERE accion = 'pensiones.revision_confirmar' AND entidad_id = :e",
            ['e' => (string) $s['pen']]));
        self::assertStringNotContainsString('BENEFICIARIA FICTICIA', $this->get('/revision-pensiones')->body());
        self::assertSame(404, $this->get('/revision-pensiones/999999')->status());
        self::assertSame(404, $this->get("/revision-pensiones/{$s['sug']}")->status(), 'Un pago de nómina no es un caso de pensión');
    }

    public function testMediumPensionRejectAndSqlInjectionInFilters(): void
    {
        $s = $this->identityScenario();
        $this->mediaPension($s);
        $a = 'admin.pr' . bin2hex(random_bytes(2));
        $this->createUser($a, ['ADMIN']);
        $this->loginAs($a);
        $v = (string) self::web()->fetchValue('SELECT asociacion_version FROM registros_tesoreria WHERE id = :id', ['id' => $s['pen']]);
        self::assertSame(303, $this->post("/revision-pensiones/{$s['pen']}/rechazar", ['version' => $v, 'motivo' => 'El trabajador no tiene pensión ficticia'])->status());
        $r = self::web()->fetchOne('SELECT asociacion_estado, rechazados FROM registros_tesoreria WHERE id = :id', ['id' => $s['pen']]);
        self::assertSame('NO_IDENTIFICADO', $r['asociacion_estado']);
        self::assertStringContainsString((string) $s['emp2'], (string) $r['rechazados']);
        foreach (["' OR '1'='1", "1; SELECT pg_sleep(3)--", "x' UNION SELECT password_hash, null FROM usuarios--", '%_!'] as $p) {
            $q = rawurlencode($p);
            $t = microtime(true);
            $res = $this->get("/revision-pensiones?q=$q&banco=$q&revision=$q&page=$q");
            self::assertSame(200, $res->status(), $p);
            self::assertLessThan(2.5, microtime(true) - $t);
            self::assertStringNotContainsString('$argon2', $res->body());
        }
        self::assertSame(404, $this->get('/revision-pensiones/' . rawurlencode("1 OR 1=1"))->status());
    }

    public function testSecurityReviewFixesAreEnforcedInTheDatabase(): void
    {
        $s = $this->identityScenario();
        $adminId = $this->createUser('admin.sr' . bin2hex(random_bytes(2)), ['ADMIN']);
        $victima = $this->createUser('consulta.sr' . bin2hex(random_bytes(2)), ['CONSULTA']);
        $this->actingAs($this->actor($adminId));
        $this->assertSqlState('42501', self::web(), fn (Database $db) => $db->execute(
            "INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = 'ADMIN'", ['u' => $victima]));
        self::web()->bindActor(null);
        $this->assertSqlState('42501', self::web(), fn (Database $db) => $db->execute(
            "INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = 'ADMIN'", ['u' => $victima]));
        $this->actingAs($this->actor($adminId));
        foreach ([['RESPALDO', 'CLI'], ['SCHEDULER', 'WEB'], ['ALMACENAMIENTO', 'WEB'], ['ALMACENAMIENTO', 'CLI']] as [$t, $o]) {
            $this->assertSqlState('42501', self::web(), fn (Database $db) => $db->fetchValue(
                "SELECT fn_sistema_ejecucion(:t, :o, now(), 'OK', '{}'::jsonb)", ['t' => $t, 'o' => $o]));
        }
        $this->assertSqlState('42501', self::web(), fn (Database $db) => $db->execute(
            "INSERT INTO revision_pendientes (registro_tesoreria_id, asociacion_version, usuario_id, motivo) VALUES (:r, 0, :u, 'directo')",
            ['r' => $s['pen'], 'u' => $adminId]));
        $this->assertSqlState('40001', self::web(), fn (Database $db) => $db->fetchValue(
            "SELECT fn_revision_dejar_pendiente(:r, 0, :u, 'No es un caso MEDIA')", ['r' => $s['pen'], 'u' => $adminId]));
        $o = $this->owner();
        self::assertSame('{}', $o->fetchValue('SELECT fn_fusion_conflictos_extra(:a, :b)', ['a' => $s['emp1'], 'b' => $s['emp2']]));
        $o->execute("INSERT INTO empleado_historial (empleado_id, campo, valor_anterior, valor_nuevo, fuente, usuario_id, motivo)
                     VALUES (:e, 'rfc', 'XXXX000000XX0', NULL, 'MANUAL', :u, 'Borrado de prueba ficticio')", ['e' => $s['emp2'], 'u' => $this->fixtureGodForTest()]);
        self::assertSame('{IDENTIFICADOR_EDITADO}', $o->fetchValue('SELECT fn_fusion_conflictos_extra(:a, :b)', ['a' => $s['emp1'], 'b' => $s['emp2']]));
    }

    private function fixtureGodForTest(): int
    {
        return $this->createUser('god.sr' . bin2hex(random_bytes(2)), ['GOD']);
    }

    public function testImportPipelineShowsEachStepWithTextState(): void
    {
        $s = $this->conciliationScenario();
        $this->createUser('god.canal', ['GOD']);
        $this->loginAs('god.canal');
        $html = $this->get('/importaciones/' . $s['archivo_r'])->body();
        self::assertSame(1, preg_match('#<ol class="pipeline" aria-label="Avance del archivo">(.*?)</ol>#s', $html, $m));
        self::assertSame(5, substr_count($m[1], 'class="pipeline__step '));
        foreach (['Recibido', 'Verificado', 'Analizado', 'Normalizado', 'Conciliado'] as $paso) {
            self::assertMatchesRegularExpression('#' . $paso . '<span class="visually-hidden">: (completado|en curso|espera una decisión|con problema|detenido|pendiente)</span>#u', $m[1]);
        }
        self::assertLessThanOrEqual(1, substr_count($m[1], 'aria-current="step"'));
    }
}
