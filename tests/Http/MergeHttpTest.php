<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Http;

use ConciliaRH\Core\Database;
use ConciliaRH\Tests\Support\HttpTestCase;
use ConciliaRH\Tests\Support\IdentityScenario;

/**
 * Duplicados y fusión por HTTP (FASE 7): permisos, previsualización con huella, confirmación, asignación masiva, CSRF, IDOR,
 * XSS almacenado, separación (ADMIN o GOD, nunca CONSULTA) y SQL injection en las rutas nuevas. Datos FICTICIOS.
 */
final class MergeHttpTest extends HttpTestCase
{
    use IdentityScenario;

    private function as(string $role): int
    {
        $user = strtolower($role) . '.fus' . bin2hex(random_bytes(2));
        $id = $this->createUser($user, [$role]);
        $this->loginAs($user);
        return $id;
    }

    /** emp2 recibe el RFC de emp1 (corrección del maestro): posible duplicado de confianza ALTA. */
    private function duplicates(): array
    {
        $s = $this->identityScenario();
        $this->committedOwner(static function (): void {
        })->execute('UPDATE empleados SET rfc = :r WHERE id = :e', ['r' => self::fakeRfc(1), 'e' => $s['emp2']]);
        $this->committedOwner(static function (Database $db): void {
            $db->execute("SET conciliarh.identidad_mantenimiento = 'on'");
            $db->execute('TRUNCATE duplicados_empleados, fusiones_empleados RESTART IDENTITY CASCADE');
            $db->execute('RESET conciliarh.identidad_mantenimiento');
        })->execute("INSERT INTO duplicados_empleados (empleado_a, empleado_b, confianza, evidencias)
                     VALUES (:a, :b, 'ALTA', '[{\"codigo\": \"MISMO_RFC\", \"fuerza\": \"FUERTE\"}]')", ['a' => $s['emp1'], 'b' => $s['emp2']]);
        return $s;
    }

    /** @return list<string> conflictos graves que la previsualización pide aceptar */
    private static function graves(string $body): array
    {
        preg_match_all('/name="aceptados\[\]" value="([A-Z_]+)"/', $body, $m);
        return $m[1];
    }

    private static function huella(string $body): string
    {
        self::assertSame(1, preg_match('/name="huella" value="([0-9a-f]{64})"/', $body, $m), 'La previsualización trae la huella');
        return $m[1];
    }

    public function testMergeFlowPermissionsMassAssignmentXssAndSeparation(): void
    {
        $s = $this->duplicates();
        $this->as('CONSULTA');
        self::assertSame(403, $this->get('/empleados/duplicados')->status());
        self::assertSame(403, $this->get("/empleados/fusion?origen={$s['emp2']}&destino={$s['emp1']}")->status());
        self::assertSame(403, $this->post('/empleados/fusion', ['origen' => (string) $s['emp2'], 'destino' => (string) $s['emp1'],
            'motivo' => str_repeat('x', 30), 'huella' => str_repeat('a', 64)])->status());
        $userA = 'admina.fus' . bin2hex(random_bytes(2));
        $admin = $this->createUser($userA, ['ADMIN']);
        $this->loginAs($userA);
        self::assertStringContainsString('Posibles duplicados', $this->get('/empleados/duplicados')->body());
        $body = $this->get("/empleados/fusion?origen={$s['emp2']}&destino={$s['emp1']}")->body();
        self::assertStringContainsString('Mismo RFC válido', $body);
        self::assertStringContainsString('Cotejos anteriores (no cambian)', $body);
        self::assertNull(self::web()->fetchValue('SELECT fusionado_en_id FROM empleados WHERE id = :e', ['e' => $s['emp2']]), 'Previsualizar no modifica');
        $h = self::huella($body);
        $graves = self::graves($body);
        self::assertContains('NOMINA_SIMULTANEA_MISMO_LOTE', $graves, 'Ambos cobran en la misma carga: conflicto grave que debe aceptarse');
        // CSRF: sin token no hay fusión
        self::assertSame(403, $this->request('POST', '/empleados/fusion', ['origen' => (string) $s['emp2'], 'destino' => (string) $s['emp1'],
            'motivo' => 'Misma persona según expediente ficticio', 'huella' => $h], [], false)->status());
        // Motivo corto o sin huella: rechazado
        $r = $this->post('/empleados/fusion', ['origen' => (string) $s['emp2'], 'destino' => (string) $s['emp1'], 'motivo' => 'corto', 'huella' => $h]);
        self::assertSame(303, $r->status());
        self::assertNull(self::web()->fetchValue('SELECT fusionado_en_id FROM empleados WHERE id = :e', ['e' => $s['emp2']]));
        // Asignación masiva: autor, confianza y conflictos «aceptados» inventados se ignoran; XSS en el motivo se escapa
        $xss = '<img src=x onerror=alert(1)> Misma persona según expediente ficticio';
        // Sin aceptar el conflicto grave no hay fusión
        $this->post('/empleados/fusion', ['origen' => (string) $s['emp2'], 'destino' => (string) $s['emp1'], 'motivo' => $xss, 'huella' => $h]);
        self::assertNull(self::web()->fetchValue('SELECT fusionado_en_id FROM empleados WHERE id = :e', ['e' => $s['emp2']]));
        // FASE 8: con conflictos graves NO se fusiona: se SOLICITA (cuatro ojos). Campos de autoría/estado inventados se ignoran.
        $r = $this->post('/empleados/fusion', ['origen' => (string) $s['emp2'], 'destino' => (string) $s['emp1'], 'motivo' => $xss, 'huella' => $h,
            'usuario_id' => '1', 'confianza' => 'MEDIA', 'aceptados' => [...$graves, 'CODIGO_INVENTADO'], 'reapuntados' => '{1,2}',
            'solicitado_por' => '1', 'decidido_por' => '1', 'estado' => 'EJECUTADA', 'expira_at' => '2099-01-01']);
        self::assertSame(303, $r->status());
        self::assertMatchesRegularExpression('#^/empleados/solicitudes-fusion/\d+$#', (string) $r->header('Location'));
        $sid = (int) basename((string) $r->header('Location'));
        $sol = self::web()->fetchOne('SELECT * FROM solicitudes_fusion WHERE id = :id', ['id' => $sid]);
        self::assertSame(['PENDIENTE_APROBACION', $admin, null], [$sol['estado'], (int) $sol['solicitado_por'], $sol['decidido_por']]);
        self::assertStringContainsString('NOMINA_SIMULTANEA_MISMO_LOTE', (string) $sol['conflictos_graves']);
        self::assertStringNotContainsString('CODIGO_INVENTADO', (string) $sol['conflictos_graves']);
        self::assertLessThanOrEqual(72 * 3600 + 5, strtotime((string) $sol['expira_at']) - strtotime((string) $sol['solicitado_at']));
        self::assertNull(self::web()->fetchValue('SELECT fusionado_en_id FROM empleados WHERE id = :e', ['e' => $s['emp2']]), 'Nada se aplica con una firma');
        self::assertSame(0, (int) self::web()->fetchValue('SELECT count(*) FROM fusiones_empleados'));
        self::assertStringNotContainsString('Aprobar y aplicar', $this->get("/empleados/solicitudes-fusion/$sid")->body(), 'Quien solicita no ve la aprobación');
        $r = $this->post("/empleados/solicitudes-fusion/$sid/aprobar", ['motivo' => 'Autoaprobación que debe rechazarse']);
        self::assertSame(303, $r->status());
        self::assertSame('PENDIENTE_APROBACION', self::web()->fetchValue('SELECT estado FROM solicitudes_fusion WHERE id = :id', ['id' => $sid]));
        self::assertNull(self::web()->fetchValue('SELECT fusionado_en_id FROM empleados WHERE id = :e', ['e' => $s['emp2']]));
        $r = $this->post("/empleados/solicitudes-fusion/$sid/rechazar", ['motivo' => 'Autorrechazo que debe rechazarse']);
        self::assertSame(403, $r->status(), 'Quien solicita tampoco rechaza su propia solicitud');
        self::assertSame('PENDIENTE_APROBACION', self::web()->fetchValue('SELECT estado FROM solicitudes_fusion WHERE id = :id', ['id' => $sid]));
        $this->as('CONSULTA');
        self::assertSame(403, $this->post("/empleados/solicitudes-fusion/$sid/aprobar", ['motivo' => 'Aprobación sin permiso'])->status());
        self::assertSame(403, $this->post("/empleados/solicitudes-fusion/$sid/rechazar", ['motivo' => 'Rechazo sin permiso'])->status());
        self::assertSame('PENDIENTE_APROBACION', self::web()->fetchValue('SELECT estado FROM solicitudes_fusion WHERE id = :id', ['id' => $sid]));
        $aprobUser = 'adminb.fus' . bin2hex(random_bytes(2));
        $aprobador = $this->createUser($aprobUser, ['ADMIN']);
        $this->loginAs($aprobUser);
        self::assertStringContainsString('Aprobar y aplicar', $this->get("/empleados/solicitudes-fusion/$sid")->body());
        self::assertSame(403, $this->request('POST', "/empleados/solicitudes-fusion/$sid/aprobar", ['motivo' => 'Sin token CSRF'], [], false)->status());
        $r = $this->post("/empleados/solicitudes-fusion/$sid/aprobar", ['motivo' => 'Revisé el expediente ficticio', 'usuario_id' => (string) $admin,
            'decidido_por' => (string) $admin, 'solicitado_por' => (string) $aprobador]);
        self::assertSame(303, $r->status());
        self::assertMatchesRegularExpression('#^/empleados/fusiones/\d+$#', (string) $r->header('Location'));
        $f = self::web()->fetchOne('SELECT * FROM fusiones_empleados ORDER BY id DESC LIMIT 1');
        self::assertSame([$aprobador, $admin, $sid, (int) $s['emp2'], (int) $s['emp1'], 'ALTA', '{}'], [(int) $f['usuario_id'], (int) $f['solicitado_por'],
            (int) $f['solicitud_id'], (int) $f['origen_id'], (int) $f['destino_id'], $f['confianza'], $f['reapuntados']]);
        self::assertStringNotContainsString('CODIGO_INVENTADO', (string) $f['conflictos_aceptados']);
        self::assertStringContainsString('NOMINA_SIMULTANEA_MISMO_LOTE', (string) $f['conflictos_aceptados']);
        $sol = self::web()->fetchOne('SELECT estado, decidido_por, fusion_id FROM solicitudes_fusion WHERE id = :id', ['id' => $sid]);
        self::assertSame(['EJECUTADA', $aprobador, (int) $f['id']], [$sol['estado'], (int) $sol['decidido_por'], (int) $sol['fusion_id']]);
        self::assertSame((int) $s['emp1'], (int) self::web()->fetchValue('SELECT fusionado_en_id FROM empleados WHERE id = :e', ['e' => $s['emp2']]));
        self::assertSame(1, (int) self::web()->fetchValue("SELECT count(*) FROM auditoria WHERE accion = 'empleados.fusionar' AND entidad_id = :e AND creado_at >= now()",
            ['e' => (string) $s['emp2']]));
        self::assertSame(1, (int) self::web()->fetchValue("SELECT count(*) FROM auditoria WHERE accion = 'empleados.fusion_aprobar' AND entidad_id = :e AND usuario_id = :u",
            ['e' => (string) $sid, 'u' => $aprobador]));
        // Aprobada una vez: no se aprueba de nuevo
        $this->post("/empleados/solicitudes-fusion/$sid/aprobar", ['motivo' => 'Segunda aprobación imposible']);
        self::assertSame(1, (int) self::web()->fetchValue('SELECT count(*) FROM fusiones_empleados'));
        $this->loginAs($userA);
        $page = $this->get((string) $r->header('Location'))->body();
        self::assertStringNotContainsString('<img src=x', $page);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $page);
        // Nada se movió: los registros del origen siguen con él; la ficha del canónico lo lista como alias
        self::assertSame(1, (int) self::web()->fetchValue('SELECT count(*) FROM registros_tesoreria WHERE empleado_id = :e', ['e' => $s['emp2']]));
        self::assertStringContainsString('Identidad canónica de', $this->get('/empleados/' . $s['emp1'])->body());
        self::assertStringContainsString('está <strong>fusionado</strong>', $this->get('/empleados/' . $s['emp2'])->body());
        // La misma previsualización ya no sirve (REGISTRO_MODIFICADO / bloqueada): nunca una segunda fusión con datos viejos
        $this->post('/empleados/fusion', ['origen' => (string) $s['emp2'], 'destino' => (string) $s['emp1'], 'motivo' => 'Misma persona según expediente ficticio',
            'huella' => $h, 'aceptados' => $graves]);
        self::assertSame(1, (int) self::web()->fetchValue('SELECT count(*) FROM fusiones_empleados'));
        // IDOR / inexistentes
        self::assertSame(404, $this->get('/empleados/fusiones/999999')->status());
        self::assertSame(404, $this->get('/empleados/fusion?origen=999998&destino=999999')->status());
        $this->as('CONSULTA');
        self::assertSame(403, $this->post('/empleados/fusiones/' . $f['id'] . '/separar', ['motivo' => 'Corrección de una fusión equivocada ficticia'])->status());
        self::assertSame('APLICADA', self::web()->fetchValue('SELECT estado FROM fusiones_empleados WHERE id = :f', ['f' => $f['id']]));
        $this->loginAs($userA);
        self::assertSame(303, $this->post('/empleados/fusiones/' . $f['id'] . '/separar', ['motivo' => 'Corrección de una fusión equivocada ficticia'])->status());
        self::assertNull(self::web()->fetchValue('SELECT fusionado_en_id FROM empleados WHERE id = :e', ['e' => $s['emp2']]));
        self::assertSame('SEPARADA', self::web()->fetchValue('SELECT estado FROM fusiones_empleados WHERE id = :f', ['f' => $f['id']]));
    }

    /** FASE 8 (docs/CRITICAL_MERGE_APPROVAL.md): rechazo, cancelación, expiración, cuatro ojos en la BD y actor no suplantable. */
    public function testCriticalMergeRequestRejectCancelExpireAndFourEyesInDatabase(): void
    {
        $s = $this->duplicates();
        $godUser = 'god.sol' . bin2hex(random_bytes(2));
        $god = $this->createUser($godUser, ['GOD']);
        $aprobUser = 'aprob.sol' . bin2hex(random_bytes(2));
        $aprob = $this->createUser($aprobUser, ['ADMIN']);
        $solicitar = function () use ($s, $godUser): int {
            $this->loginAs($godUser);
            $body = $this->get("/empleados/fusion?origen={$s['emp2']}&destino={$s['emp1']}")->body();
            $r = $this->post('/empleados/fusion', ['origen' => (string) $s['emp2'], 'destino' => (string) $s['emp1'],
                'motivo' => 'Misma persona según expediente ficticio', 'huella' => self::huella($body), 'aceptados' => self::graves($body)]);
            self::assertMatchesRegularExpression('#^/empleados/solicitudes-fusion/\d+$#', (string) $r->header('Location'));
            return (int) basename((string) $r->header('Location'));
        };
        // 1. Cancelación: sólo quien solicitó; una segunda solicitud de la misma pareja no se admite mientras haya una pendiente
        $sid = $solicitar();
        $this->post('/empleados/fusion', ['origen' => (string) $s['emp1'], 'destino' => (string) $s['emp2'], 'motivo' => 'Duplicada en sentido contrario ficticia',
            'huella' => str_repeat('a', 64)]);
        self::assertSame(1, (int) self::web()->fetchValue("SELECT count(*) FROM solicitudes_fusion WHERE estado = 'PENDIENTE_APROBACION'"));
        $this->loginAs($aprobUser);
        self::assertSame(403, $this->post("/empleados/solicitudes-fusion/$sid/cancelar", ['motivo' => 'No es mía'])->status(), 'Sólo quien solicitó cancela');
        self::assertSame('PENDIENTE_APROBACION', self::web()->fetchValue('SELECT estado FROM solicitudes_fusion WHERE id = :id', ['id' => $sid]));
        $this->loginAs($godUser);
        self::assertSame(303, $this->post("/empleados/solicitudes-fusion/$sid/cancelar", ['motivo' => 'Me equivoqué de pareja'])->status());
        self::assertSame('CANCELADA', self::web()->fetchValue('SELECT estado FROM solicitudes_fusion WHERE id = :id', ['id' => $sid]));
        // 2. Rechazo por el aprobador: el solicitante recibe una notificación (sin datos personales)
        $sid = $solicitar();
        self::assertSame(0, (int) self::web()->fetchValue("SELECT count(*) FROM notificaciones WHERE usuario_id = :u", ['u' => $aprob]),
            'RLS: el solicitante no ve las notificaciones de otro usuario');
        $this->loginAs($aprobUser);
        $this->get('/');
        self::assertSame(1, (int) self::web()->fetchValue("SELECT count(*) FROM notificaciones n WHERE n.tipo = 'FUSION_PENDIENTE_APROBACION'
            AND n.usuario_id = :u AND n.entidad_id = :s", ['u' => $aprob, 's' => $sid]), 'El aprobador fue notificado');
        self::assertSame(303, $this->post("/empleados/solicitudes-fusion/$sid/rechazar", ['motivo' => 'Evidencia insuficiente en expediente'])->status());
        $row = self::web()->fetchOne('SELECT estado, decidido_por FROM solicitudes_fusion WHERE id = :id', ['id' => $sid]);
        self::assertSame(['RECHAZADA', $aprob], [$row['estado'], (int) $row['decidido_por']]);
        self::assertNull(self::web()->fetchValue('SELECT fusionado_en_id FROM empleados WHERE id = :e', ['e' => $s['emp2']]));
        // 3. Cuatro ojos en la BD aunque se evite PHP; y actor no suplantable (aprobar a nombre de otro)
        $sid = $solicitar();
        $this->assertSqlState('42501', self::web(), fn (Database $db) => $db->fetchValue('SELECT fn_aprobar_solicitud_fusion(:s, :u, :m)',
            ['s' => $sid, 'u' => $god, 'm' => 'Autoaprobación directa']));
        $this->assertSqlState('42501', self::web(), fn (Database $db) => $db->fetchValue('SELECT fn_aprobar_solicitud_fusion(:s, :u, :m)',
            ['s' => $sid, 'u' => $aprob, 'm' => 'Suplantando al aprobador']));
        $this->assertSqlState('42501', self::web(), fn (Database $db) => $db->execute(
            "UPDATE solicitudes_fusion SET estado = 'EJECUTADA', decidido_por = :u, decidido_at = now() WHERE id = :s", ['u' => $aprob, 's' => $sid]));
        $this->assertSqlState('42501', self::web(), fn (Database $db) => $db->execute('DELETE FROM solicitudes_fusion WHERE id = :s', ['s' => $sid]));
        // CHECK de cuatro ojos: ni el propietario del esquema puede registrar una decisión del propio solicitante
        $this->assertSqlState('23514', $this->owner(), fn (Database $db) => $db->execute(
            "INSERT INTO solicitudes_fusion (origen_id, destino_id, motivo, conflictos_graves, analisis, huella, solicitado_por, expira_at, estado,
                    decidido_por, decidido_at, motivo_decision)
             VALUES (:a, :b, 'Solicitud ficticia que se autoaprueba', '{RFC_DIFERENTE}', '{}', :h, :u, now() + interval '1 day', 'RECHAZADA', :u2, now(), 'misma persona')",
            ['a' => $s['emp1'], 'b' => $s['emp2'], 'h' => str_repeat('d', 64), 'u' => $god, 'u2' => $god]));
    }

    /** Expiración: una solicitud vencida nunca se aplica (queda EXPIRADA y se avisa al solicitante). Sólo conexión del propietario. */
    public function testExpiredCriticalRequestIsNeverApplied(): void
    {
        $s = $this->duplicates();
        $god = $this->createUser('god.exp' . bin2hex(random_bytes(2)), ['GOD']);
        $o = $this->owner();
        $aprob = (int) $o->fetchValue("INSERT INTO usuarios (username, nombre, password_hash) VALUES (:u, 'Aprobador ficticio', repeat('x', 30)) RETURNING id",
            ['u' => 'aprob.exp' . bin2hex(random_bytes(2))]);
        $o->execute("INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = 'ADMIN'", ['u' => $aprob]);
        $old = (int) $o->fetchValue("INSERT INTO solicitudes_fusion (origen_id, destino_id, motivo, conflictos_graves, analisis, huella, solicitado_por,
                solicitado_at, expira_at) VALUES (:a, :b, 'Solicitud ficticia antigua para vencer', '{RFC_DIFERENTE}', '{}', :h, :u, now() - interval '50 hours',
                now() - interval '2 hours') RETURNING id", ['a' => $s['emp2'], 'b' => $s['emp1'], 'h' => str_repeat('b', 64), 'u' => $god]);
        $token = bin2hex(random_bytes(32));
        $o->query('SELECT fn_sesion_vincular(:t, :u, NULL, :ua)', ['t' => $token, 'u' => $aprob, 'ua' => 'phpunit']);
        $o->bindActor($token);
        self::assertNull($o->fetchValue('SELECT fn_aprobar_solicitud_fusion(:s, :u, :m)', ['s' => $old, 'u' => $aprob, 'm' => 'Aprobación tardía']));
        self::assertSame('EXPIRADA', $o->fetchValue('SELECT estado FROM solicitudes_fusion WHERE id = :s', ['s' => $old]));
        self::assertNull($o->fetchValue('SELECT fusionado_en_id FROM empleados WHERE id = :e', ['e' => $s['emp2']]));
        self::assertSame(1, (int) $o->fetchValue("SELECT count(*) FROM notificaciones WHERE usuario_id = :u AND tipo = 'FUSION_DECIDIDA' AND entidad_id = :s",
            ['u' => $god, 's' => $old]));
        // Barrido del scheduler: marca las vencidas sin aplicar nada
        $o->execute("INSERT INTO solicitudes_fusion (origen_id, destino_id, motivo, conflictos_graves, analisis, huella, solicitado_por, solicitado_at, expira_at)
                     VALUES (:a, :b, 'Otra solicitud ficticia antigua a vencer', '{RFC_DIFERENTE}', '{}', :h, :u, now() - interval '80 hours', now() - interval '1 hour')",
            ['a' => $s['emp1'], 'b' => $s['emp2'], 'h' => str_repeat('c', 64), 'u' => $god]);
        self::assertSame(1, (int) $o->fetchValue('SELECT fn_solicitudes_fusion_expirar()'));
        $o->bindActor(null);
    }

    /** Revisión de seguridad: si el solicitante perdió el permiso de solicitar, la aprobación CANCELA (nunca aplica). Sólo propietario. */
    public function testApprovalCancelsWhenRequesterLostThePermission(): void
    {
        $s = $this->duplicates();
        $o = $this->owner();
        $sol = (int) $o->fetchValue("INSERT INTO usuarios (username, nombre, password_hash) VALUES (:u, 'Solicitante sin permiso', repeat('x', 30)) RETURNING id",
            ['u' => 'consulta.sp' . bin2hex(random_bytes(2))]);
        $o->execute("INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = 'CONSULTA'", ['u' => $sol]);
        $aprob = (int) $o->fetchValue("INSERT INTO usuarios (username, nombre, password_hash) VALUES (:u, 'Aprobador', repeat('x', 30)) RETURNING id",
            ['u' => 'aprob.sp' . bin2hex(random_bytes(2))]);
        $o->execute("INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = 'ADMIN'", ['u' => $aprob]);
        $an = (string) $o->fetchValue('SELECT fn_fusion_analisis(:a, :b)', ['a' => $s['emp2'], 'b' => $s['emp1']]);
        $id = (int) $o->fetchValue("INSERT INTO solicitudes_fusion (origen_id, destino_id, motivo, conflictos_graves, analisis, huella, solicitado_por, expira_at)
            VALUES (:a, :b, 'Solicitud ficticia cuyo autor pierde el permiso', '{NOMINA_SIMULTANEA_MISMO_LOTE}', CAST(:an AS jsonb),
                    fn_fusion_huella(CAST(:an2 AS jsonb)), :u, now() + interval '48 hours') RETURNING id",
            ['a' => $s['emp2'], 'b' => $s['emp1'], 'an' => $an, 'an2' => $an, 'u' => $sol]);
        $o->query('SELECT fn_sesion_vincular(:t, :u, NULL, :ua)', ['t' => $tok = bin2hex(random_bytes(32)), 'u' => $aprob, 'ua' => 'phpunit']);
        $o->bindActor($tok);
        self::assertNull($o->fetchValue('SELECT fn_aprobar_solicitud_fusion(:s, :u, :m)', ['s' => $id, 'u' => $aprob, 'm' => 'Aprobación ficticia']));
        $r = $o->fetchOne('SELECT estado, motivo_decision FROM solicitudes_fusion WHERE id = :s', ['s' => $id]);
        self::assertSame('CANCELADA', $r['estado']);
        self::assertStringContainsString('permiso', (string) $r['motivo_decision']);
        self::assertNull($o->fetchValue('SELECT fusionado_en_id FROM empleados WHERE id = :e', ['e' => $s['emp2']]));
        $o->bindActor(null);
    }

    public function testDismissNameOnlyAndSqlInjectionOnNewRoutes(): void
    {
        $s = $this->duplicates();
        $this->as('ADMIN');
        $d = (int) self::web()->fetchValue('SELECT id FROM duplicados_empleados LIMIT 1');
        self::assertSame(303, $this->post("/empleados/duplicados/$d/descartar", ['motivo' => 'Personas distintas: expediente ficticio', 'estado' => 'FUSIONADO',
            'decidido_por' => '1'])->status());
        $row = self::web()->fetchOne('SELECT estado, decidido_por, confianza_al_decidir FROM duplicados_empleados WHERE id = :d', ['d' => $d]);
        self::assertSame('DESCARTADO', $row['estado']);
        self::assertSame('ALTA', $row['confianza_al_decidir']);
        // Una sola señal débil (nombre) nunca es fusionable, ni forzando la huella
        $this->as('ADMIN');
        $body = $this->get("/empleados/fusion?origen={$s['emp1']}&destino={$s['emp2']}")->body();
        self::assertStringContainsString('Mismo RFC válido', $body, 'El par sí tiene evidencia fuerte');
        // SQL injection (booleana, UNION, por tiempo, por error, segundo orden) en filtros y parámetros nuevos: sin 500, sin fuga, sin demora
        $payloads = ["' OR '1'='1", "1 UNION SELECT username, password_hash FROM usuarios--", "1; SELECT pg_sleep(3)--", "1' AND 1=CAST((SELECT version()) AS int)--",
                     "'||(SELECT pg_sleep(3))||'"];
        foreach ($payloads as $p) {
            $q = rawurlencode($p);
            foreach (["/empleados/duplicados?q=$q", "/empleados/duplicados?estado=$q&confianza=$q", "/empleados/fusion?origen=$q&destino=1",
                      "/reportes/generados?tipo=$q&estado=$q&formato=$q&periodo=$q&desde=$q&hasta=$q"] as $url) {
                $t = microtime(true);
                $r = $this->get($url);
                self::assertLessThan(500, $r->status(), $url);
                self::assertLessThan(2.5, microtime(true) - $t, "Sin demora: $url");
                self::assertStringNotContainsString('$argon2', $r->body(), $url);
                self::assertStringNotContainsString('PostgreSQL 1', $r->body(), $url);
            }
        }
        // Segundo orden: un nombre de programación malicioso se guarda como dato y se consulta sin reinterpretarse
        $this->post('/reportes/programados', ['nombre' => "x'); DROP TABLE usuarios;--", 'tipo' => 'resumen', 'formato' => 'PDF', 'frecuencia' => 'MANUAL',
                                              'hora' => '7', 'retencion_dias' => '30']);
        self::assertSame(200, $this->get('/reportes/programados')->status());
        self::assertGreaterThan(0, (int) self::web()->fetchValue('SELECT count(*) FROM usuarios'));
    }

    public function testDuplicatesAlertIsShownToAdminButNotToConsulta(): void
    {
        $this->duplicates();
        $this->as('ADMIN');
        self::assertStringContainsString('posible(s) duplicado(s) con evidencia alta o media', $this->get('/')->body());
        $this->as('CONSULTA');
        self::assertStringNotContainsString('posible(s) duplicado(s) con evidencia alta o media', $this->get('/')->body());
    }

    public function testReviewAnalysisNeedsPermissionAndDetectionIsOneAtATime(): void
    {
        $s = $this->duplicates();
        $this->as('CONSULTA');
        $this->get('/');
        self::web()->execute('SAVEPOINT s');
        try {
            self::web()->fetchValue('SELECT fn_fusion_analisis(:a, :b)', ['a' => $s['emp1'], 'b' => $s['emp2']]);
            self::fail('CONSULTA obtuvo la comparación de identificadores');
        } catch (\PDOException $e) {
            self::assertSame('42501', $e->errorInfo[0]);
        }
        self::web()->execute('ROLLBACK TO SAVEPOINT s');
        $this->as('ADMIN');
        self::assertSame(303, $this->post('/empleados/duplicados/detectar')->status());
        $this->post('/empleados/duplicados/detectar');
        self::assertSame(1, (int) self::web()->fetchValue("SELECT count(*) FROM procesos WHERE tipo = 'DETECTAR_DUPLICADOS' AND estado = 'PENDIENTE' AND created_at >= now()"),
            'Una detección a la vez (toma el candado global)');
    }
}
