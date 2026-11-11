<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Http;

use ConciliaRH\Core\Database;
use ConciliaRH\Tests\Support\HttpTestCase;
use ConciliaRH\Tests\Support\IdentityScenario;

final class OperationsHttpTest extends HttpTestCase
{
    use IdentityScenario;

    public const CLABE_PEN = '002180000000099999';

    private function as(string $role): int
    {
        $user = strtolower($role) . '.ops' . bin2hex(random_bytes(2));
        $id = $this->createUser($user, [$role]);
        $this->loginAs($user);
        return $id;
    }

    private function db(): Database
    {
        return $this->committedOwner(static function (): void {
        });
    }

    private function pensionSuggestion(array $s, string $conf = 'BAJA'): void
    {
        [$h, $c, $k] = self::protect(self::CLABE_PEN);
        $this->db()->execute(
            "UPDATE registros_tesoreria SET asociacion_estado = 'SUGERENCIA', asociacion_confianza = :conf, tipo_cuenta = 'CLABE', cuenta_hmac = :h,
                    cuenta_hmac_key_id = fn_hmac_key_activa(), cuenta_ultimos4 = '9999', cuenta_cifrada = CAST(:c AS bytea), cuenta_key_id = :k,
                    asociacion_evidencia = CAST(:ev AS jsonb) WHERE id = :id",
            ['h' => $h, 'c' => $c, 'k' => $k, 'id' => $s['pen'], 'conf' => $conf,
             'ev' => json_encode(['motivo' => 'IMPORTE_RETENCION_UNICA', 'candidatos' => [$s['emp2']], 'senales' => ['IMPORTE_RETENCION_UNICA']])]);
    }

    private function version(int $id): int
    {
        return (int) self::web()->fetchValue('SELECT asociacion_version FROM registros_tesoreria WHERE id = :id', ['id' => $id]);
    }

    /** @return array{token:string,items:list<string>,body:string} */
    private function previewed(string $accion, array $ids, array $extra = []): array
    {
        $r = $this->post('/revision-masiva/previsualizar', ['accion' => $accion,
            'items' => array_map(fn ($id) => $id . ':' . $this->version($id), $ids)] + $extra);
        self::assertSame(200, $r->status(), substr(strip_tags($r->body()), 0, 400));
        $body = $r->body();
        preg_match('/name="token" value="([^"]+)"/', $body, $t);
        preg_match_all('/name="items\[\]" value="([^"]+)"/', $body, $it);
        return ['token' => html_entity_decode($t[1] ?? ''), 'items' => array_map('html_entity_decode', $it[1]), 'body' => $body];
    }

    public function testCase6BulkConfirmationIsTraceablePerRecord(): void
    {
        $s = $this->identityScenario();
        $uid = $this->as('ADMIN');
        $list = $this->get('/revision-masiva?categoria=NOMINA')->body();
        self::assertStringContainsString('PERSONA FICTICIA UNO', $list);
        self::assertStringContainsString('CLABE nueva', $list);
        $p = $this->previewed('CONFIRMAR_PROPUESTA', [$s['sug'], $s['conf']], ['confirmar_cuenta' => '1']);
        self::assertStringContainsString('Confirmará 1 asociaciones por un total de $700.00', $p['body']);
        self::assertStringContainsString('Requiere decision individual', $p['body'], 'El conflicto no entra en bloque');
        self::assertCount(1, $p['items']);
        self::assertStringContainsString('Cuentas que se crearán', $p['body']);
        $r = $this->post('/revision-masiva/ejecutar', ['accion' => 'CONFIRMAR_PROPUESTA', 'items' => $p['items'], 'token' => $p['token'],
            'confirmar_cuenta' => '1', 'motivo' => 'Revisión de sugerencias con expediente ficticio']);
        self::assertSame(303, $r->status());
        self::assertMatchesRegularExpression('#/revision-masiva/acciones/\d+$#', (string) $r->header('Location'));
        $reg = self::web()->fetchOne('SELECT asociacion_estado, empleado_id, asociado_por FROM registros_tesoreria WHERE id = :id', ['id' => $s['sug']]);
        self::assertSame(['ASOCIADO_MANUAL', $s['emp1'], $uid], [$reg['asociacion_estado'], (int) $reg['empleado_id'], (int) $reg['asociado_por']]);
        $am = self::web()->fetchOne('SELECT * FROM acciones_masivas ORDER BY id DESC LIMIT 1');
        self::assertSame([1, '700.00', 1, 1], [(int) $am['registros'], $am['importe_total'], (int) $am['empleados'], (int) $am['cuentas']]);
        $det = self::web()->fetchAll('SELECT * FROM acciones_masivas_registros WHERE accion_masiva_id = :a', ['a' => $am['id']]);
        self::assertSame([[$s['sug'], 'SUGERENCIA', 'ASOCIADO_MANUAL']], array_map(static fn ($d) => [(int) $d['registro_id'], $d['estado_anterior'], $d['estado_nuevo']], $det));
        self::assertNotNull($det[0]['cuenta_id'], 'La cuenta confirmada queda ligada al desglose');
        self::assertSame(2, (int) self::web()->fetchValue('SELECT count(*) FROM asociaciones_historial WHERE accion_masiva_id = :a', ['a' => $am['id']]),
            'ASOCIAR + CONFIRMAR_CUENTA ligados a la acción masiva');
        self::assertCount(1, $this->auditRows('asociaciones.masivo'));
        self::assertStringContainsString('PERSONA FICTICIA UNO', $this->get('/revision-masiva/acciones/' . $am['id'])->body());
    }

    public function testCase7_8ConflictsConcurrencyAndTampering(): void
    {
        $s = $this->identityScenario();
        $this->as('ADMIN');
        $p = $this->previewed('CONFIRMAR_PROPUESTA', [$s['conf']]);
        self::assertSame('', $p['token'], 'Sin registros elegibles no hay firma');
        $p = $this->previewed('CONFIRMAR_PROPUESTA', [$s['sug']]);
        $this->db()->execute("UPDATE registros_tesoreria SET asociacion_version = asociacion_version + 1 WHERE id = :id", ['id' => $s['sug']]);
        $r = $this->post('/revision-masiva/ejecutar', ['accion' => 'CONFIRMAR_PROPUESTA', 'items' => $p['items'], 'token' => $p['token'], 'motivo' => 'Intento tardío de confirmación']);
        self::assertSame(303, $r->status());
        self::assertStringContainsString('REGISTRO_MODIFICADO', $this->flashText());
        self::assertSame('SUGERENCIA', self::web()->fetchValue('SELECT asociacion_estado FROM registros_tesoreria WHERE id = :id', ['id' => $s['sug']]));
        self::assertSame(0, (int) self::web()->fetchValue('SELECT count(*) FROM acciones_masivas'));
        $p = $this->previewed('CONFIRMAR_PROPUESTA', [$s['sug']]);
        [$id, $v] = explode(':', $p['items'][0]);
        foreach ([["$id:$v:" . $s['emp2']], [...$p['items'], $s['conf'] . ':0:' . $s['emp1']]] as $items) {
            $this->post('/revision-masiva/ejecutar', ['accion' => 'CONFIRMAR_PROPUESTA', 'items' => $items, 'token' => $p['token'], 'motivo' => 'Selección alterada']);
            self::assertStringContainsString('alterada', $this->flashText());
        }
        $this->post('/revision-masiva/ejecutar', ['accion' => 'MARCAR_NO_IDENTIFICADO', 'items' => $p['items'], 'token' => $p['token'], 'motivo' => 'Otra acción']);
        self::assertStringContainsString('alterada', $this->flashText());
        $r = $this->post('/revision-masiva/ejecutar', ['accion' => 'CONFIRMAR_PROPUESTA', 'items' => $p['items'], 'token' => $p['token'],
            'motivo' => 'Con campos adicionales maliciosos', 'usuario_id' => '1', 'asociado_por' => '1', 'estado' => 'ASOCIADO_AUTOMATICO',
            'empleado_id' => (string) $s['emp2'], 'importe' => '1.00', 'registros' => '999']);
        self::assertSame(303, $r->status());
        $reg = self::web()->fetchOne('SELECT asociacion_estado, empleado_id, importe FROM registros_tesoreria WHERE id = :id', ['id' => $s['sug']]);
        self::assertSame(['ASOCIADO_MANUAL', $s['emp1'], '700.00'], [$reg['asociacion_estado'], (int) $reg['empleado_id'], $reg['importe']]);
        self::assertSame(1, (int) self::web()->fetchValue('SELECT registros FROM acciones_masivas ORDER BY id DESC LIMIT 1'));
        $p2 = $this->previewed('MARCAR_NO_IDENTIFICADO', [$s['pen']]);
        $this->as('ADMIN');
        $this->post('/revision-masiva/ejecutar', ['accion' => 'MARCAR_NO_IDENTIFICADO', 'items' => $p2['items'], 'token' => $p2['token'], 'motivo' => 'Firma ajena']);
        self::assertStringContainsString('alterada', $this->flashText());
    }

    public function testBulkPermissionsCsrfAndPensionBeneficiary(): void
    {
        $s = $this->identityScenario();
        $this->pensionSuggestion($s, 'MEDIA');
        $this->as('CONSULTA');
        self::assertSame(200, $this->get('/revision-masiva')->status());
        self::assertStringNotContainsString('name="items[]"', $this->get('/revision-masiva')->body(), 'CONSULTA no selecciona');
        self::assertSame(403, $this->post('/revision-masiva/previsualizar', ['accion' => 'CONFIRMAR_PROPUESTA', 'items' => [$s['sug'] . ':0']])->status());
        self::assertSame(403, $this->post('/revision-masiva/ejecutar', ['accion' => 'CONFIRMAR_PROPUESTA', 'items' => [], 'token' => 'x'])->status());
        $this->as('ADMIN');
        self::assertSame(403, $this->request('POST', '/revision-masiva/previsualizar', ['accion' => 'CONFIRMAR_PROPUESTA', 'items' => [$s['pen'] . ':0']], [], false)->status(), 'CSRF');
        $p = $this->previewed('CONFIRMAR_PROPUESTA', [$s['pen']], ['registrar_beneficiario' => '1']);
        self::assertStringContainsString('1 beneficiario(s)', $p['body']);
        $this->post('/revision-masiva/ejecutar', ['accion' => 'CONFIRMAR_PROPUESTA', 'items' => $p['items'], 'token' => $p['token'],
            'registrar_beneficiario' => '1', 'motivo' => 'Pensión confirmada con oficio ficticio']);
        $b = self::web()->fetchOne('SELECT * FROM beneficiarios_pension');
        self::assertSame(['CONFIRMACION_MANUAL', 'BENEFICIARIA FICTICIA'], [$b['origen'], $b['nombre_original']]);
        self::assertSame((int) $b['id'], (int) self::web()->fetchValue('SELECT beneficiario_id FROM registros_tesoreria WHERE id = :id', ['id' => $s['pen']]));
        self::assertSame((int) $b['id'], (int) self::web()->fetchValue("SELECT beneficiario_id FROM cuentas_bancarias WHERE uso = 'PENSION_BENEFICIARIO'"));
        $rel = self::web()->fetchOne('SELECT * FROM beneficiario_trabajadores');
        self::assertSame([$s['emp2'], 'CONFIRMACION_MANUAL'], [(int) $rel['empleado_id'], $rel['origen']]);
        self::assertNotNull($rel['accion_masiva_id']);
    }

    public function testBeneficiaryModuleMaskingRevealIdorXssAndCorrection(): void
    {
        $s = $this->identityScenario();
        $this->pensionSuggestion($s, 'MEDIA');
        $this->as('ADMIN');
        $p = $this->previewed('CONFIRMAR_PROPUESTA', [$s['pen']], ['registrar_beneficiario' => '1']);
        $this->post('/revision-masiva/ejecutar', ['accion' => 'CONFIRMAR_PROPUESTA', 'items' => $p['items'], 'token' => $p['token'],
            'registrar_beneficiario' => '1', 'motivo' => 'Pensión confirmada con oficio ficticio']);
        $b = (int) self::web()->fetchValue('SELECT id FROM beneficiarios_pension');
        $cta = (int) self::web()->fetchValue('SELECT id FROM cuentas_bancarias WHERE beneficiario_id = :b', ['b' => $b]);
        $this->post("/beneficiarios/$b/observaciones", ['observaciones' => '<script>alert(1)</script><img src=x onerror=alert(2)>']);
        $page = $this->get("/beneficiarios/$b")->body();
        self::assertStringNotContainsString('<script>alert(1)', $page);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page);
        self::assertStringContainsString('parentesco no registrado', $page);
        foreach (['esposa', 'hijo', 'padre', 'madre', 'cónyuge'] as $w) {
            self::assertStringNotContainsStringIgnoringCase(">$w<", $page);
        }
        $this->as('CONSULTA');
        $c = $this->get("/beneficiarios/$b?tab=cuentas")->body();
        self::assertStringContainsString('••••9999', $c);
        self::assertStringNotContainsString(self::CLABE_PEN, $c);
        self::assertStringNotContainsString('/revelar"', $c);
        self::assertSame(403, $this->post("/beneficiarios/$b/cuentas/$cta/revelar")->status());
        self::assertSame(403, $this->post("/beneficiarios/$b/observaciones", ['observaciones' => 'x'])->status());
        $this->as('ADMIN');
        $r = $this->post("/beneficiarios/$b/cuentas/$cta/revelar");
        self::assertStringContainsString(self::CLABE_PEN, $r->body());
        self::assertStringContainsString('no-store', (string) $r->header('Cache-Control'));
        self::assertCount(1, $this->auditRows('cuentas.revelar'));
        self::assertSame(404, $this->post("/beneficiarios/$b/cuentas/{$s['cuenta2']}/revelar")->status());
        self::assertSame(404, $this->get('/beneficiarios/999999')->status());
        $rel = (int) self::web()->fetchValue('SELECT id FROM beneficiario_trabajadores');
        $this->post("/beneficiarios/$b/trabajadores/$rel/desactivar", ['motivo' => 'Relación confirmada por error']);
        self::assertSame('INACTIVA', self::web()->fetchValue('SELECT estado FROM beneficiario_trabajadores WHERE id = :id', ['id' => $rel]));
        self::assertSame(1, (int) self::web()->fetchValue('SELECT count(*) FROM beneficiario_trabajadores'));
        self::assertSame(404, $this->post('/beneficiarios/' . ($b + 1) . "/trabajadores/$rel/desactivar", ['motivo' => 'IDOR de relación'])->status());
    }

    private function flashText(): string
    {
        return strip_tags($this->get('/revision-masiva')->body());
    }

    public function testDashboardAlertsAreDynamicNotHardcoded(): void
    {
        $s = $this->identityScenario();
        $this->as('ADMIN');
        $n = (int) self::web()->fetchValue("SELECT count(*) FROM registros_tesoreria WHERE categoria = 'NOMINA'
                                             AND asociacion_estado IN ('SUGERENCIA', 'AMBIGUO', 'CONFLICTO', 'NO_IDENTIFICADO')");
        self::assertGreaterThan(0, $n);
        self::assertMatchesRegularExpression('#attention__count">' . $n . '</span>\s*<span class="attention__text">[^<]*pago\(s\) de nómina sin número de empleado por identificar#u',
            $this->get('/')->body());
        $p = $this->previewed('CONFIRMAR_PROPUESTA', [$s['sug']]);
        $this->post('/revision-masiva/ejecutar', ['accion' => 'CONFIRMAR_PROPUESTA', 'items' => $p['items'], 'token' => $p['token'], 'motivo' => 'Resuelto para la prueba']);
        $home = $this->get('/')->body();
        $m = $n - 1;
        self::assertTrue($m === 0 ? !str_contains($home, 'de nómina sin número de empleado por identificar')
            : preg_match('#attention__count">' . $m . '</span>\s*<span class="attention__text">[^<]*de nómina sin número de empleado por identificar#u', $home) === 1,
            'La tarea se recalcula con los datos');
    }

    public function testBulkNeverTouchesConflictsLowConfidenceOrSplitClabeGroups(): void
    {
        $s = $this->identityScenario();
        $this->pensionSuggestion($s);                      // SUGERENCIA de pensión con confianza BAJA (sólo importe)
        $this->as('ADMIN');
        $p = $this->previewed('MARCAR_NO_IDENTIFICADO', [$s['conf']]);
        self::assertSame('', $p['token']);
        self::assertStringContainsString('Requiere decision individual', $p['body']);
        $p = $this->previewed('CONFIRMAR_PROPUESTA', [$s['pen']], ['registrar_beneficiario' => '1']);
        self::assertSame('', $p['token']);
        self::assertStringContainsString('Confianza baja requiere revision individual', $p['body']);
        [$h, $c, $k] = self::protect(self::CLABE);
        $this->db()->execute("UPDATE registros_tesoreria SET cuenta_hmac = :h, cuenta_hmac_key_id = fn_hmac_key_activa(), cuenta_ultimos4 = '2345', cuenta_cifrada = CAST(:c AS bytea), cuenta_key_id = :k,
                                     tipo_cuenta = 'CLABE' WHERE id = :id", ['h' => $h, 'c' => $c, 'k' => $k, 'id' => $s['conf']]);
        $p = $this->previewed('CONFIRMAR_PROPUESTA', [$s['sug']], ['confirmar_cuenta' => '1']);
        self::assertSame('', $p['token']);
        self::assertStringContainsString('Clabe con candidatos distintos', $p['body']);
    }

    public function testDeactivatingARelationAlsoStopsItsClabeFromReassociating(): void
    {
        $s = $this->identityScenario();
        $this->pensionSuggestion($s, 'MEDIA');
        $this->as('ADMIN');
        $p = $this->previewed('CONFIRMAR_PROPUESTA', [$s['pen']], ['registrar_beneficiario' => '1']);
        $this->post('/revision-masiva/ejecutar', ['accion' => 'CONFIRMAR_PROPUESTA', 'items' => $p['items'], 'token' => $p['token'],
            'registrar_beneficiario' => '1', 'motivo' => 'Pensión confirmada con oficio ficticio']);
        $b = (int) self::web()->fetchValue('SELECT id FROM beneficiarios_pension');
        $rel = (int) self::web()->fetchValue('SELECT id FROM beneficiario_trabajadores');
        $this->post("/beneficiarios/$b/trabajadores/$rel/desactivar", ['motivo' => 'Relación confirmada por error']);
        self::assertSame('INACTIVA', self::web()->fetchValue('SELECT estado FROM cuentas_bancarias WHERE beneficiario_id = :b', ['b' => $b]),
            'La CLABE deja de asociar automáticamente a ese trabajador');
        self::assertNotNull(self::web()->fetchValue('SELECT vigente_hasta_periodo_id FROM beneficiario_trabajadores WHERE id = :id', ['id' => $rel]));
        self::assertCount(1, $this->auditRows('cuentas.desactivar'));
    }
}
