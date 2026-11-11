<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Http;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\View;
use ConciliaRH\Domain\Audit\AuditContext;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Conciliation\ConciliationComparison;
use ConciliaRH\Domain\Conciliation\ConciliationService;
use ConciliaRH\Domain\Reports\ReportJobRunner;
use ConciliaRH\Domain\Reports\ReportRenderer;
use ConciliaRH\Domain\Reports\ReportService;
use ConciliaRH\Domain\Reports\ScheduledReportService;
use ConciliaRH\Domain\Settings\SettingsService;
use ConciliaRH\Tests\Support\ConciliationScenario;
use ConciliaRH\Tests\Support\HttpTestCase;
use PDOException;

/**
 * Reportes programados de extremo a extremo (FASE 7, casos 22–33 y 36–40). La programación y los usuarios se confirman por el
 * propietario (el scheduler usa otra conexión: el rol de servicio); la descarga pasa por HTTP. Datos FICTICIOS.
 */
final class ScheduledReportsHttpTest extends HttpTestCase
{
    use ConciliationScenario;

    private ?Database $svcDb = null;

    private function svc(): Database
    {
        return $this->svcDb ??= new Database(self::dbConfig('SCHEDULER_DB_USERNAME', 'SCHEDULER_DB_PASSWORD', 'conciliarh_scheduler'));
    }

    private function runner(?string $storage = null): ReportJobRunner
    {
        $db = $this->svc();
        $ctx = new AuditContext();
        $ctx->origin = 'WORKER';
        $audit = new AuditLogger($db, $ctx);
        $settings = new SettingsService($db, $audit);
        $root = $storage ?? $this->storageRoot;
        $view = \ConciliaRH\Core\App::container(dirname(__DIR__, 2))->get(View::class);
        return new ReportJobRunner($db, new ReportService($db, new ConciliationService($db, $audit), new ConciliationComparison($db), $settings),
            new ReportRenderer($view, $root . '/trabajo/reportes', dirname(__DIR__, 2)), $audit, $settings, $root, 'phpunit-reportes', 'America/Mexico_City', true, self::reportCipher());
    }

    /** Usuario CONFIRMADO (lo ve el rol de servicio) con contraseña de pruebas; se elimina al terminar. */
    private function committedRole(string $code, array $perms): void
    {
        $o = $this->committedOwner(static function (Database $db) use ($code): void {
            $db->execute('DELETE FROM usuario_rol WHERE rol_id IN (SELECT id FROM roles WHERE codigo = :c)', ['c' => $code]);
            $db->execute('DELETE FROM rol_permiso WHERE rol_id IN (SELECT id FROM roles WHERE codigo = :c)', ['c' => $code]);
            $db->execute('DELETE FROM roles WHERE codigo = :c', ['c' => $code]);
        });
        $id = (int) $o->fetchValue("INSERT INTO roles (codigo, nombre) VALUES (:c, 'Rol de prueba ficticio') RETURNING id", ['c' => $code]);
        foreach ($perms as $perm) {
            $o->execute('INSERT INTO rol_permiso (rol_id, permiso_id) SELECT :r, id FROM permisos WHERE codigo = :p', ['r' => $id, 'p' => $perm]);
        }
    }

    private function committedUser(string $role): array
    {
        $name = strtolower($role) . '.prog' . bin2hex(random_bytes(3));
        $o = $this->committedOwner(static function (Database $db): void {
            $db->execute("SET conciliarh.identidad_mantenimiento = 'on'");
            $db->execute('TRUNCATE reportes_generados, reportes_programados RESTART IDENTITY');
            $db->execute("DELETE FROM procesos WHERE tipo IN ('GENERAR_REPORTE_PROGRAMADO', 'LIMPIAR_REPORTES_EXPIRADOS')");
            $db->execute('RESET conciliarh.identidad_mantenimiento');
            $db->execute("DELETE FROM usuario_rol WHERE usuario_id IN (SELECT id FROM usuarios WHERE username LIKE '%.prog%')");
            $db->execute("DELETE FROM usuarios WHERE username LIKE '%.prog%'");
        });
        $id = (int) $o->fetchValue('INSERT INTO usuarios (username, nombre, password_hash) VALUES (:u, :n, :h) RETURNING id',
            ['u' => $name, 'n' => 'Usuario ' . $role, 'h' => self::passwordHash()]);
        $o->execute('INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = :r', ['u' => $id, 'r' => $role]);
        return ['id' => $id, 'username' => $name];
    }

    /** Programación confirmada (como la dejaría la web) con la próxima ejecución vencida. */
    private function committedSchedule(int $owner, array $over = []): int
    {
        $v = $over + ['nombre' => 'Resumen quincenal ficticio', 'tipo' => 'resumen', 'formato' => 'PDF', 'frecuencia' => 'QUINCENAL', 'hora' => 7,
                      'sensible' => false, 'retencion_dias' => 30];
        return (int) $this->committedOwner(static function (): void {
        })->fetchValue(
            "INSERT INTO reportes_programados (nombre, tipo, formato, frecuencia, hora, sensible, retencion_dias, creado_por, proxima_ejecucion_at)
             VALUES (:n, :t, :f, :fr, :h, :s, :r, :u, now() - interval '1 minute') RETURNING id",
            ['n' => $v['nombre'], 't' => $v['tipo'], 'f' => $v['formato'], 'fr' => $v['frecuencia'], 'h' => $v['hora'],
             's' => $v['sensible'] ? 'true' : 'false', 'r' => $v['retencion_dias'], 'u' => $owner]);
    }

    private function generated(int $prog): array
    {
        return $this->svc()->fetchOne('SELECT * FROM reportes_generados WHERE programacion_id = :p ORDER BY id DESC LIMIT 1', ['p' => $prog]) ?? [];
    }

    public function testCase33_36_38_39ProgrammingPermissionsXssAndMassAssignment(): void
    {
        $this->conciliationScenario();
        $this->createUser('consulta.prog0', ['CONSULTA']);
        $this->loginAs('consulta.prog0');
        self::assertSame(403, $this->get('/reportes/programados')->status(), 'CONSULTA no programa');
        self::assertSame(403, $this->post('/reportes/programados', ['nombre' => 'X', 'tipo' => 'resumen', 'formato' => 'PDF', 'frecuencia' => 'DIARIO'])->status());
        $ana = $this->createUser('admin.prog0', ['ADMIN']);
        $otro = $this->createUser('admin.prog1', ['ADMIN']);
        $this->loginAs('admin.prog0');
        // 39. Asignación masiva: dueño, versión, estado de ejecución y huella nunca se aceptan del formulario
        $r = $this->post('/reportes/programados', ['nombre' => '<script>alert(1)</script> Diferencias', 'tipo' => 'diferencias', 'formato' => 'XLSX',
            'frecuencia' => 'SEMANAL', 'dia_semana' => '1', 'hora' => '8', 'retencion_dias' => '60', 'creado_por' => (string) $otro, 'version' => '99',
            'ultimo_estado' => 'DISPONIBLE', 'proxima_ejecucion_at' => '2000-01-01', 'activo' => '0']);
        self::assertRedirect($r, '/reportes/programados');
        $p = self::web()->fetchOne("SELECT * FROM reportes_programados WHERE nombre LIKE '%Diferencias'");
        self::assertSame([$ana, 1, null, true], [(int) $p['creado_por'], (int) $p['version'], $p['ultimo_estado'], (bool) $p['activo']]);
        self::assertGreaterThan(time(), strtotime((string) $p['proxima_ejecucion_at']));
        // 35. XSS almacenado: el nombre se muestra escapado
        $html = $this->get('/reportes/programados')->body();
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $role = (int) $this->asFixtureGod(fn () => self::web()->fetchValue("INSERT INTO roles (codigo, nombre) VALUES ('PROGRAMA_X', 'Programa sin RFC') RETURNING id"));
        foreach (['dashboard.ver', 'conciliaciones.ver', 'reportes.ver', 'reportes.exportar', 'reportes.programar'] as $perm) {
            $this->asFixtureGod(fn () => self::web()->execute('INSERT INTO rol_permiso (rol_id, permiso_id) SELECT :r, id FROM permisos WHERE codigo = :p', ['r' => $role, 'p' => $perm]));
        }
        $sinSensible = $this->createUser('sinsens.prog0', ['PROGRAMA_X']);
        $this->loginAs('sinsens.prog0');
        $r = $this->post('/reportes/programados', ['nombre' => 'Sensible', 'tipo' => 'empleados', 'formato' => 'CSV', 'frecuencia' => 'DIARIO', 'hora' => '7',
                                                   'retencion_dias' => '30', 'sensible' => '1']);
        self::assertSame(422, $r->status());
        self::assertSame(0, (int) self::web()->fetchValue("SELECT count(*) FROM reportes_programados WHERE nombre = 'Sensible'"));
        self::web()->execute('SAVEPOINT s');
        try {
            self::web()->execute("INSERT INTO reportes_programados (nombre, tipo, formato, frecuencia, sensible, creado_por) VALUES ('Directo', 'empleados', 'CSV', 'MANUAL', true, :u)",
                ['u' => $sinSensible]);
            self::fail('La BD aceptó una programación sensible de alguien sin reportes.exportar_sensible');
        } catch (PDOException $e) {
            self::assertSame('42501', $e->errorInfo[0]);
        }
        self::web()->execute('ROLLBACK TO SAVEPOINT s');
        // 36. IDOR: la programación de otro usuario no existe para él
        $this->loginAs('sinsens.prog0');
        self::assertSame(404, $this->get('/reportes/programados/' . $p['id'])->status());
        self::assertSame(404, $this->post('/reportes/programados/' . $p['id'] . '/generar')->status());
        self::assertSame(404, $this->post('/reportes/programados/' . $p['id'] . '/pausar', ['motivo' => 'Intento ajeno'])->status());
        // 37. CSRF
        self::assertSame(403, $this->request('POST', '/reportes/programados', ['nombre' => 'Sin token', 'tipo' => 'resumen', 'formato' => 'PDF',
                                                                              'frecuencia' => 'MANUAL'], [], false)->status());
    }

    public function testCase26_27_31_32_40SchedulerGenerationProtectedDownloadAndTamperedFile(): void
    {
        $s = $this->conciliationScenario();
        $this->committedRole('PROPIOS_X', ['dashboard.ver', 'conciliaciones.ver', 'reportes.ver', 'reportes.exportar', 'reportes.programar']);
        $a = $this->committedUser('ADMIN');
        $b = $this->committedUser('PROPIOS_X');
        $prog = $this->committedSchedule($a['id']);
        $runner = $this->runner();
        // 26/40. Dos schedulers: el candado deja pasar a uno; la BD no encola dos veces la misma hora programada
        $other = new Database(self::dbConfig('SCHEDULER_DB_USERNAME', 'SCHEDULER_DB_PASSWORD', 'conciliarh_scheduler'));
        $other->fetchValue('SELECT pg_advisory_lock(:k)', ['k' => ReportJobRunner::SCHEDULER_LOCK]);
        self::assertSame(['omitido' => 'OTRO_SCHEDULER_EN_CURSO'], $runner->schedule());
        $other->fetchValue('SELECT pg_advisory_unlock(:k)', ['k' => ReportJobRunner::SCHEDULER_LOCK]);
        $due = $this->svc()->fetchValue('SELECT proxima_ejecucion_at FROM reportes_programados WHERE id = :p', ['p' => $prog]);
        $r1 = $runner->schedule();
        self::assertSame(1, $r1['encolados']);
        self::assertSame(0, $runner->schedule()['encolados'], 'Idempotente: la próxima ejecución ya avanzó');
        self::assertNull($this->svc()->fetchValue("SELECT fn_reporte_encolar(:p, CAST(:t AS timestamptz), 'PROGRAMADO')", ['p' => $prog, 't' => $due]),
            'La misma hora programada no se encola dos veces');
        self::assertSame(1, (int) $this->svc()->fetchValue("SELECT count(*) FROM procesos p JOIN reportes_generados g ON g.proceso_id = p.id
                                                             WHERE p.tipo = 'GENERAR_REPORTE_PROGRAMADO' AND g.programacion_id = :p", ['p' => $prog]));
        // 40. Dos generaciones del mismo reporte: mientras una está en curso, otra (a otra hora) no se encola
        self::assertNull($this->svc()->fetchValue("SELECT fn_reporte_encolar(:p, now(), 'PROGRAMADO')", ['p' => $prog]));
        self::assertSame(1, (int) $this->svc()->fetchValue('SELECT count(*) FROM reportes_generados WHERE programacion_id = :p', ['p' => $prog]));
        // 27. Generación con los permisos vigentes del dueño; archivo fuera de public/ con SHA-256 registrado
        self::assertSame(1, $runner->runPending()['generados']);
        $g = $this->generated($prog);
        self::assertSame(['DISPONIBLE', (int) $s['conc'], 'PDF'], [$g['estado'], (int) $g['conciliacion_id'], $g['formato']]);
        $path = $this->storageRoot . '/reportes/' . $g['ruta'];
        self::assertFileExists($path);
        self::assertStringStartsWith('%PDF', (string) file_get_contents($path));
        self::assertSame($g['sha256'], hash_file('sha256', $path));
        self::assertSame(1, (int) $this->svc()->fetchValue("SELECT count(*) FROM reportes_generados WHERE programacion_id = :p", ['p' => $prog]));
        self::assertSame('DISPONIBLE', $this->svc()->fetchValue('SELECT ultimo_estado FROM reportes_programados WHERE id = :p', ['p' => $prog]));
        self::assertSame(1, (int) self::web()->fetchValue("SELECT count(*) FROM auditoria WHERE accion = 'reportes.generar' AND entidad_id = :g
                                                             AND creado_at >= now() AND contexto->>'tipo' = 'resumen'", ['g' => (string) $g['id']]),
            'Generación auditada (sin contenido)');
        // Ni el servicio reescribe la huella de un reporte ya generado (antes de que la web toque la fila en su transacción)
        try {
            $this->svc()->execute("UPDATE reportes_generados SET sha256 = repeat('0', 64) WHERE id = :g", ['g' => $g['id']]);
            self::fail('El servicio reescribió la huella');
        } catch (PDOException $e) {
            self::assertSame('23514', $e->errorInfo[0]);
        }
        // 31. Descarga protegida: el dueño sí (copia verificada, no-store); otro usuario no la ve; CONSULTA no llega
        $this->loginAs($a['username']);
        self::assertStringContainsString('#' . $g['id'], $this->get('/reportes/generados')->body());
        $res = $this->post('/reportes/generados/' . $g['id'] . '/descargar');
        self::assertSame(200, $res->status());
        self::assertSame('no-store, max-age=0', $res->header('Cache-Control'));
        self::assertTrue($res->isTempStream());
        self::assertSame($g['sha256'], hash_file('sha256', (string) $res->streamPath()));
        self::assertStringNotContainsString('/reportes/generados/', (string) $res->streamPath(), 'Se entrega una copia verificada, nunca el archivo guardado');
        @unlink((string) $res->streamPath());
        self::assertSame(1, (int) self::web()->fetchValue("SELECT count(*) FROM auditoria WHERE accion = 'reportes.descargar_generado' AND entidad_id = :g",
            ['g' => (string) $g['id']]));
        $this->loginAs($b['username']);
        self::assertStringNotContainsString('#' . $g['id'], $this->get('/reportes/generados')->body());
        self::assertSame(404, $this->post('/reportes/generados/' . $g['id'] . '/descargar')->status(), 'Compartir el id no concede nada');
        $this->createUser('consulta.prog2', ['CONSULTA']);
        $this->loginAs('consulta.prog2');
        self::assertSame(403, $this->post('/reportes/generados/' . $g['id'] . '/descargar')->status());
        // 32. Archivo alterado: no se entrega, queda ALTERADO y se audita la incidencia
        file_put_contents($path, "\n%%alterado", FILE_APPEND);
        $this->loginAs($a['username']);
        $res = $this->post('/reportes/generados/' . $g['id'] . '/descargar');
        self::assertSame(409, $res->status());
        self::assertSame('ALTERADO', self::web()->fetchValue('SELECT estado FROM reportes_generados WHERE id = :g', ['g' => $g['id']]));
        self::assertSame(1, (int) self::web()->fetchValue("SELECT count(*) FROM auditoria WHERE accion = 'reportes.integridad_fallida' AND entidad_id = :g",
            ['g' => (string) $g['id']]));
        // La sensibilidad y la huella de un generado no se cambian (ni la web ni el servicio)
        foreach (["UPDATE reportes_generados SET sensible = true WHERE id = {$g['id']}", "UPDATE reportes_generados SET sha256 = repeat('0', 64) WHERE id = {$g['id']}"] as $sql) {
            self::web()->execute('SAVEPOINT s');
            try {
                self::web()->execute($sql);
                self::fail("La web ejecutó: $sql");
            } catch (PDOException $e) {
                self::assertSame('42501', $e->errorInfo[0]);
            }
            self::web()->execute('ROLLBACK TO SAVEPOINT s');
        }
    }

    public function testCase28_29_30FailureRetryPermissionRevocationAndExpiration(): void
    {
        $this->conciliationScenario();
        $a = $this->committedUser('ADMIN');
        $prog = $this->committedSchedule($a['id'], ['tipo' => 'empleados', 'formato' => 'CSV']);
        // 28. Falla (almacenamiento inaccesible): ningún archivo corrupto, motivo seguro y reintento según la política de la cola
        $bad = $this->storageRoot . '/no-es-directorio';
        file_put_contents($bad, 'x');
        $this->runner($bad)->schedule();
        $this->runner($bad)->runPending();
        $g = $this->generated($prog);
        self::assertSame(['PENDIENTE', 'ERROR_GENERACION'], [$g['estado'], $g['error_codigo']]);
        self::assertNull($g['ruta']);
        self::assertSame([], glob($this->storageRoot . '/reportes/generados/*/*/*') ?: []);
        $job = $this->svc()->fetchOne('SELECT estado, intentos FROM procesos WHERE id = :p', ['p' => $g['proceso_id']]);
        self::assertSame(['PENDIENTE', 1], [$job['estado'], (int) $job['intentos']]);
        // 29. Reintento: corregida la causa, el mismo trabajo genera el reporte
        $this->svc()->execute('UPDATE procesos SET disponible_desde = now() WHERE id = :p', ['p' => $g['proceso_id']]);
        self::assertSame(1, $this->runner()->runPending()['generados']);
        $g = $this->generated($prog);
        self::assertSame('DISPONIBLE', $g['estado']);
        // FASE 8: detalle por persona ⇒ CIFRADO en reposo (nunca el claro en storage); la descarga lo verifica y lo descifra al transmitir
        $enc = (string) file_get_contents($this->storageRoot . '/reportes/' . $g['ruta']);
        self::assertStringEndsWith('.csv.enc', (string) $g['ruta']);
        self::assertSame([true, self::reportCipher()->activeKeyId()], [(bool) $g['cifrado'], $g['cifrado_llave']]);
        self::assertStringStartsWith("CRHRPT1\n", $enc);
        self::assertStringNotContainsString('Número', $enc);
        self::assertStringNotContainsString('FICTICI', $enc);
        self::assertSame([], glob($this->storageRoot . '/trabajo/reportes/reporte-*') ?: [], 'Sin claro temporal tras generar');
        // 30. Expiración: se borra el archivo y se CONSERVA la fila (huella y metadatos); no se borra si se está descargando
        $file = $this->storageRoot . '/reportes/' . $g['ruta'];
        $this->committedOwner(static function (): void {
        })->execute("UPDATE reportes_generados SET expira_at = now() - interval '1 day' WHERE id = :g", ['g' => $g['id']]);
        $lock = new Database(self::dbConfig('DB_USERNAME', 'DB_PASSWORD', 'conciliarh_web'));
        $lock->fetchValue('SELECT pg_advisory_lock_shared(:k)', ['k' => ScheduledReportService::fileLock((int) $g['id'])]);
        self::assertSame(['expirados' => 0, 'en_uso' => 1, 'sin_archivo' => 0], $this->runner()->cleanup());
        self::assertFileExists($file);
        $lock->fetchValue('SELECT pg_advisory_unlock_shared(:k)', ['k' => ScheduledReportService::fileLock((int) $g['id'])]);
        self::assertSame(1, $this->runner()->cleanup()['expirados']);
        self::assertFileDoesNotExist($file);
        $g2 = $this->generated($prog);
        self::assertSame(['EXPIRADO', $g['sha256'], $g['ruta']], [$g2['estado'], $g2['sha256'], $g2['ruta']]);
        self::assertNotNull($g2['archivo_eliminado_at']);
        self::assertSame(1, (int) $this->svc()->fetchValue("SELECT count(*) FROM procesos WHERE tipo = 'LIMPIAR_REPORTES_EXPIRADOS'"), 'El scheduler la encoló');
        self::assertNull($this->svc()->fetchValue('SELECT fn_reportes_encolar_limpieza()'), 'Una limpieza a la vez y como máximo una al día');
        // Permiso revocado: el dueño pierde el rol → no se genera nada y la programación se pausa
        $this->committedOwner(static function (): void {
        })->execute('DELETE FROM usuario_rol WHERE usuario_id = :u', ['u' => $a['id']]);
        $this->committedOwner(static function (): void {
        })->execute("UPDATE reportes_programados SET proxima_ejecucion_at = now() - interval '5 minutes' WHERE id = :p", ['p' => $prog]);
        self::assertSame(1, $this->runner()->schedule()['encolados']);
        $this->runner()->runPending();
        $g3 = $this->generated($prog);
        self::assertSame(['ERROR', 'PERMISO_REVOCADO', null], [$g3['estado'], $g3['error_codigo'], $g3['ruta']]);
        $p = $this->svc()->fetchOne('SELECT activo, pausa_motivo FROM reportes_programados WHERE id = :p', ['p' => $prog]);
        self::assertFalse((bool) $p['activo']);
        self::assertNotNull($p['pausa_motivo']);
        // Ruta fuera del patrón del sistema (recorrido de directorios): rechazada
        $svc = new ScheduledReportService(self::web(), new AuditLogger(self::web(), new AuditContext()), new SettingsService(self::web(),
            new AuditLogger(self::web(), new AuditContext())), $this->storageRoot, 'America/Mexico_City');
        $this->expectException(\RuntimeException::class);
        $svc->resolve('generados/2031/04/../../../../etc/passwd');
    }

    /** FASE 8 (docs/REPORT_ENCRYPTION.md): descarga de un reporte cifrado y llave equivocada en el servidor. */
    public function testEncryptedReportDownloadDecryptsOnTheFlyAndWrongKeyNeverMarksTampering(): void
    {
        $this->conciliationScenario();
        $a = $this->committedUser('ADMIN');
        $prog = $this->committedSchedule($a['id'], ['tipo' => 'diferencias', 'formato' => 'CSV']);
        $this->runner()->schedule();
        self::assertSame(1, $this->runner()->runPending()['generados']);
        $g = $this->generated($prog);
        self::assertSame(['DISPONIBLE', true], [$g['estado'], (bool) $g['cifrado']]);
        self::assertStringEndsWith('.csv.enc', (string) $g['ruta']);
        $this->loginAs($a['username']);
        $res = $this->post('/reportes/generados/' . $g['id'] . '/descargar');
        self::assertSame(200, $res->status());
        self::assertTrue($res->hasStreamWriter());
        $csv = $res->renderStream();
        self::assertStringContainsString('Número', $csv);
        self::assertSame((string) strlen($csv), $res->header('Content-Length'));
        self::assertSame([], glob($this->storageRoot . '/trabajo/reportes/*') ?: [], 'La copia cifrada temporal se borra y nunca hubo claro en disco');
        self::assertTrue((bool) self::web()->fetchValue("SELECT (contexto->>'cifrado')::boolean FROM auditoria WHERE accion = 'reportes.descargar_generado'
            AND entidad_id = :g ORDER BY secuencia DESC LIMIT 1", ['g' => (string) $g['id']]));
        // Llave equivocada en el servidor (mismo id, otro secreto): 503 y el reporte NO se marca alterado
        $wrong = \ConciliaRH\Domain\Reports\ReportCipher::fromConfig(self::reportCipher()->activeKeyId() . ':' . str_repeat('7', 64), self::reportCipher()->activeKeyId());
        $svcWrong = new ScheduledReportService(self::web(), new AuditLogger(self::web(), new AuditContext()), new SettingsService(self::web(),
            new AuditLogger(self::web(), new AuditContext())), $this->storageRoot, 'America/Mexico_City', $wrong);
        try {
            $svcWrong->download($this->actor($a['id']), (int) $g['id']);
            self::fail('Descifró con una llave equivocada');
        } catch (\ConciliaRH\Core\HttpException $e) {
            self::assertSame(503, $e->status());
        }
        self::assertSame('DISPONIBLE', self::web()->fetchValue('SELECT estado FROM reportes_generados WHERE id = :g', ['g' => $g['id']]));
    }

    public function testReviewQuotaManualLimitAndOnlyTheOwnerEditsTheDefinition(): void
    {
        $this->conciliationScenario();
        $cfg = $this->committedOwner(static function (Database $db): void {
            $db->execute("UPDATE configuracion SET valor = '20' WHERE clave = 'reportes.programados_max_por_usuario'");
            $db->execute("UPDATE configuracion SET valor = '10' WHERE clave = 'reportes.generar_manual_por_hora'");
        });
        $cfg->execute("UPDATE configuracion SET valor = '1' WHERE clave IN ('reportes.programados_max_por_usuario', 'reportes.generar_manual_por_hora')");
        $ana = $this->createUser('operador.prog7', ['ADMIN']);
        $this->loginAs('operador.prog7');
        $form = ['tipo' => 'grupos', 'formato' => 'PDF', 'frecuencia' => 'MANUAL', 'hora' => '7', 'retencion_dias' => '30'];
        self::assertSame(303, $this->post('/reportes/programados', ['nombre' => 'Primera'] + $form)->status());
        self::assertSame(422, $this->post('/reportes/programados', ['nombre' => 'Segunda'] + $form)->status(), 'Cuota de programaciones activas');
        $p1 = (int) self::web()->fetchValue("SELECT id FROM reportes_programados WHERE nombre = 'Primera'");
        $this->post("/reportes/programados/$p1/pausar", ['motivo' => 'Pausa de prueba']);
        self::assertSame(303, $this->post('/reportes/programados', ['nombre' => 'Segunda'] + $form)->status());
        // Reactivar la primera excedería la cuota: la BD lo impide (no se evade pausando y reactivando)
        $this->post("/reportes/programados/$p1/reactivar");
        self::assertFalse((bool) self::web()->fetchValue('SELECT activo FROM reportes_programados WHERE id = :p', ['p' => $p1]));
        // Límite de generaciones manuales por hora
        $p2 = (int) self::web()->fetchValue("SELECT id FROM reportes_programados WHERE nombre = 'Segunda'");
        $this->post("/reportes/programados/$p2/generar");
        self::assertSame(1, (int) self::web()->fetchValue("SELECT count(*) FROM reportes_generados WHERE programacion_id = :p", ['p' => $p2]));
        $cfg->execute("UPDATE configuracion SET valor = '5' WHERE clave = 'reportes.programados_max_por_usuario'");
        self::web()->execute('UPDATE reportes_programados SET activo = true WHERE id = :p', ['p' => $p1]);
        $r = $this->post("/reportes/programados/$p1/generar");
        self::assertSame(0, (int) self::web()->fetchValue("SELECT count(*) FROM reportes_generados WHERE programacion_id = :p", ['p' => $p1]), 'Límite por hora');
        self::assertSame(303, $r->status());
        // Un administrador sólo pausa o reactiva programaciones ajenas: la definición es del dueño
        $this->createUser('admin.prog7', ['ADMIN']);
        $this->loginAs('admin.prog7');
        $r = $this->post("/reportes/programados/$p2", ['nombre' => 'Retargeteada', 'version' => '1'] + $form + ['tipo' => 'resumen']);
        self::assertSame(422, $r->status());
        self::assertSame('Segunda', self::web()->fetchValue('SELECT nombre FROM reportes_programados WHERE id = :p', ['p' => $p2]));
        self::web()->execute('SAVEPOINT s');
        try {
            self::web()->execute("UPDATE reportes_programados SET tipo = 'empleados', actualizado_por = (SELECT id FROM usuarios WHERE username = 'admin.prog7') WHERE id = :p",
                ['p' => $p2]);
            self::fail('La BD aceptó que un administrador cambiara la definición ajena');
        } catch (PDOException $e) {
            self::assertSame('42501', $e->errorInfo[0]);
        }
        self::web()->execute('ROLLBACK TO SAVEPOINT s');
        self::assertSame(303, $this->post("/reportes/programados/$p2/pausar", ['motivo' => 'Pausa administrativa'])->status());
        self::assertFalse((bool) self::web()->fetchValue('SELECT activo FROM reportes_programados WHERE id = :p', ['p' => $p2]));
        self::assertSame($ana, (int) self::web()->fetchValue('SELECT creado_por FROM reportes_programados WHERE id = :p', ['p' => $p2]));
    }
}
