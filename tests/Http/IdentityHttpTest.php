<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Http;

use ConciliaRH\Tests\Support\HttpTestCase;
use ConciliaRH\Tests\Support\IdentityScenario;
use PHPUnit\Framework\Attributes\DataProvider;

final class IdentityHttpTest extends HttpTestCase
{
    use IdentityScenario;

    private function as(string $role): void
    {
        $user = strtolower($role) . '.idh' . bin2hex(random_bytes(2));
        $this->createUser($user, [$role]);
        $this->loginAs($user);
    }

    public function testCase26ConsultaSeesMaskedDataOnly(): void
    {
        $s = $this->identityScenario();
        $this->as('CONSULTA');
        $list = $this->get('/empleados')->body();
        self::assertStringContainsString('PERSONA FICTICIA UNO', $list);
        self::assertStringNotContainsString(self::fakeRfc(1), $list, 'RFC enmascarado');
        self::assertStringNotContainsString(self::fakeCurp(1), $list);
        $detail = $this->get('/empleados/' . $s['emp2'] . '?tab=cuentas')->body();
        self::assertStringContainsString('••••5678', $detail);
        self::assertStringNotContainsString(self::CUENTA_SANT, $detail);
        self::assertStringNotContainsString('Revelar', $detail);
        self::assertStringNotContainsString(self::fakeRfc(2), $this->get('/empleados/' . $s['emp2'] . '?tab=identidad')->body());
        self::assertStringContainsString('No hay empleados', $this->get('/empleados?q=' . self::fakeRfc(1))->body());
        $a = $this->get('/asociaciones/tesoreria/' . $s['sug'])->body();
        self::assertStringContainsString('PERSONA FICTICIA UNO', $a);
        self::assertStringNotContainsString('/asociar"', $a);
        self::assertStringNotContainsString(self::CLABE, $a);
        self::assertStringContainsString('••••2345', $a);
    }

    public function testOperatorSeesSensibleIdsAndConsultaNeverFullAccounts(): void
    {
        $s = $this->identityScenario();
        $this->as('CONSULTA');
        self::assertSame(403, $this->post('/empleados/' . $s['emp2'] . '/cuentas/' . $s['cuenta2'] . '/revelar')->status());
        self::assertStringNotContainsString(self::fakeRfc(1), $this->get('/empleados/' . $s['emp1'] . '?tab=identidad')->body());
        self::assertCount(0, $this->auditRows('cuentas.revelar'));
        $this->as('ADMIN');
        self::assertStringContainsString(self::fakeRfc(1), $this->get('/empleados/' . $s['emp1'] . '?tab=identidad')->body());
        self::assertStringContainsString('RFC/CURP exacto', $this->get('/empleados?q=' . self::fakeCurp(2))->body());
        $page = $this->get('/asociaciones/tesoreria/' . $s['sug'])->body();
        self::assertStringContainsString('Confirmar esta CLABE como medio de pago conocido', $page);
        self::assertStringContainsString('Rechazar', $page);
    }

    public function testAdminRevealsAccountOnceWithAuditAndNoStore(): void
    {
        $s = $this->identityScenario();
        $this->as('ADMIN');
        $r = $this->post('/empleados/' . $s['emp2'] . '/cuentas/' . $s['cuenta2'] . '/revelar');
        self::assertSame(200, $r->status());
        self::assertStringContainsString(self::CUENTA_SANT, $r->body());
        self::assertStringContainsString('no-store', (string) $r->header('Cache-Control'));
        self::assertCount(1, $this->auditRows('cuentas.revelar'));
        self::assertStringNotContainsString(self::CUENTA_SANT, (string) json_encode($this->auditRows('cuentas.revelar')));
        self::assertStringNotContainsString(self::CUENTA_SANT, $this->get('/empleados/' . $s['emp2'] . '?tab=cuentas')->body(), 'No persiste tras la respuesta');
        self::assertSame(404, $this->post('/empleados/' . $s['emp1'] . '/cuentas/' . $s['cuenta2'] . '/revelar')->status());
        self::assertSame(405, $this->get('/empleados/' . $s['emp2'] . '/cuentas/' . $s['cuenta2'] . '/revelar')->status());
        self::assertSame(403, $this->request('POST', '/empleados/' . $s['emp2'] . '/cuentas/' . $s['cuenta2'] . '/revelar', [], [], false)->status());
    }

    public function testManualAssociationFlowOverHttp(): void
    {
        $s = $this->identityScenario();
        $this->as('ADMIN');
        $v = (int) self::web()->fetchValue('SELECT asociacion_version FROM registros_tesoreria WHERE id = :id', ['id' => $s['sug']]);
        self::assertSame(403, $this->request('POST', '/asociaciones/tesoreria/' . $s['sug'] . '/asociar',
            ['empleado_id' => (string) $s['emp1'], 'version' => (string) $v, 'motivo' => 'Sin token'], [], false)->status());
        $r = $this->post('/asociaciones/tesoreria/' . $s['sug'] . '/asociar',
            ['empleado_id' => (string) $s['emp1'], 'version' => (string) $v, 'motivo' => 'Coincide con expediente', 'confirmar_cuenta' => '1']);
        self::assertSame(303, $r->status());
        self::assertSame('ASOCIADO_MANUAL', self::web()->fetchValue('SELECT asociacion_estado FROM registros_tesoreria WHERE id = :id', ['id' => $s['sug']]));
        self::assertSame(1, (int) self::web()->fetchValue("SELECT count(*) FROM cuentas_bancarias WHERE empleado_id = :e AND origen = 'CONFIRMACION_MANUAL'", ['e' => $s['emp1']]));
        $this->post('/asociaciones/tesoreria/' . $s['sug'] . '/asociar', ['empleado_id' => (string) $s['emp2'], 'version' => (string) $v, 'motivo' => 'Cambio tardío']);
        self::assertSame($s['emp1'], (int) self::web()->fetchValue('SELECT empleado_id FROM registros_tesoreria WHERE id = :id', ['id' => $s['sug']]));
        $vp = (int) self::web()->fetchValue('SELECT asociacion_version FROM registros_tesoreria WHERE id = :id', ['id' => $s['pen']]);
        $this->post('/asociaciones/tesoreria/' . $s['pen'] . '/no-identificado', ['version' => (string) $vp, 'motivo' => 'Sin trabajador identificable']);
        self::assertSame('NO_IDENTIFICADO_CONFIRMADO', self::web()->fetchValue('SELECT asociacion_estado FROM registros_tesoreria WHERE id = :id', ['id' => $s['pen']]));
        $list = $this->get('/asociaciones?estado=CONFLICTO')->body();
        self::assertStringContainsString('/asociaciones/tesoreria/' . $s['conf'], $list);
        self::assertStringContainsString('Evidencia fuerte contradictoria', $this->get('/asociaciones/tesoreria/' . $s['conf'])->body());
    }

    public function testImportPageShowsNormalizationSummaryWithoutPii(): void
    {
        $s = $this->identityScenario();
        $this->as('CONSULTA');
        $html = $this->get('/importaciones/' . $s['archivo_t'])->body();
        self::assertStringContainsString('Registros normalizados', $html);
        self::assertStringContainsString('Revisión manual', $html);
        self::assertStringNotContainsString('Normalizar de nuevo', $html, 'CONSULTA no tiene importacion.normalizar');
        $api = json_decode($this->get('/api/importaciones/' . $s['archivo_t'] . '/normalizacion', ['Accept' => 'application/json'])->body(), true);
        self::assertSame(['TESORERIA', 4], [$api['data']['normalizacion']['tipo_registro'], $api['data']['normalizacion']['registros']]);
        self::assertStringNotContainsString('FICTICIA', (string) json_encode($api));
        $rrhh = $this->get('/importaciones/' . $s['archivo_r'])->body();
        self::assertStringContainsString('Fuente confiable: crea y actualiza empleados', $rrhh);
    }

    public function testEmployeeDetailTabsAndTraceability(): void
    {
        $s = $this->identityScenario();
        $this->as('ADMIN');
        foreach (['resumen' => 'Origen del registro maestro', 'rrhh' => 'Snapshots de nómina RRHH', 'tesoreria' => 'Registros de Tesorería asociados',
                  'cuentas' => 'Cuentas y CLABE', 'incidencias' => 'Incidencias de identidad', 'auditoria' => 'Auditoría relacionada',
                  'identidad' => 'Corregir datos'] as $tab => $needle) {
            self::assertStringContainsString($needle, $this->get('/empleados/' . $s['emp2'] . '?tab=' . $tab)->body(), $tab);
        }
        $rrhh = $this->get('/empleados/' . $s['emp1'] . '?tab=rrhh')->body();
        self::assertStringContainsString('/importaciones/' . $s['archivo_r'], $rrhh, 'Empleado → registro → archivo');
        self::assertStringContainsString('/asociaciones/rrhh/' . $s['rrhh1'], $rrhh);
        self::assertSame(404, $this->get('/empleados/999999999')->status());
        $r = $this->post('/empleados/' . $s['emp1'], ['apellido_paterno' => 'FICTICIA', 'motivo' => 'Dato del expediente']);
        self::assertSame(303, $r->status());
        self::assertCount(1, $this->auditRows('empleados.editar'));
    }

    public function testLotRelationsOverHttp(): void
    {
        $s = $this->identityScenario();
        $this->as('CONSULTA');
        self::assertSame(403, $this->post('/lotes/' . $s['lote2'] . '/relaciones', ['destino' => (string) $s['lote'], 'tipo' => 'CORRIGE', 'motivo' => 'Sin permiso'])->status());
        $this->as('ADMIN');
        self::assertSame(303, $this->post('/lotes/' . $s['lote2'] . '/relaciones', ['destino' => (string) $s['lote'], 'tipo' => 'CORRIGE', 'motivo' => 'Corrige errores'])->status());
        $html = $this->get('/lotes/' . $s['lote'])->body();
        self::assertStringContainsString('Relaciones con otros lotes', $html);
        self::assertStringContainsString('corrige a', $html);
    }

    /** @return list<array{string,string,string,int}> */
    public static function matrix(): array
    {
        return [
            ['CONSULTA', 'GET', '/empleados', 200], ['CONSULTA', 'GET', '/asociaciones', 200], ['CONSULTA', 'GET', '/incidencias-identidad', 200],
            ['CONSULTA', 'GET', '/conceptos', 403], ['CONSULTA', 'POST', '/empleados/{emp1}', 403], ['CONSULTA', 'POST', '/asociaciones/tesoreria/{sug}/asociar', 403],
            ['CONSULTA', 'POST', '/importaciones/{archivo_t}/normalizar', 403], ['CONSULTA', 'POST', '/empleados/{emp2}/cuentas/{cuenta2}/desactivar', 403],
            ['CONSULTA', 'POST', '/conceptos/1', 403], ['CONSULTA', 'POST', '/lotes/{lote}/relaciones', 403],
            ['CONSULTA', 'POST', '/empleados/{emp2}/cuentas/{cuenta2}/revelar', 403],
            ['ADMIN', 'POST', '/asociaciones/tesoreria/{sug}/rechazar', 303], ['ADMIN', 'POST', '/importaciones/{archivo_t}/normalizar', 303],
            ['ADMIN', 'POST', '/empleados/{emp2}/cuentas/{cuenta2}/desactivar', 303], ['ADMIN', 'POST', '/lotes/{lote}/relaciones', 303],
            ['ADMIN', 'POST', '/empleados/{emp2}/cuentas/{cuenta2}/revelar', 200], ['ADMIN', 'POST', '/conceptos/1', 403], ['ADMIN', 'POST', '/empleados/{emp1}', 303],
            ['GOD', 'GET', '/asociaciones/rrhh/{rrhh1}', 200], ['GOD', 'POST', '/lotes/{lote}/relaciones', 303],
        ];
    }

    #[DataProvider('matrix')]
    public function testCase25PermissionMatrix(string $role, string $method, string $path, int $expected): void
    {
        $s = $this->identityScenario();
        $path = (string) preg_replace_callback('/\{([a-z0-9_]+)\}/', static fn ($m) => (string) $s[$m[1]], $path);
        $this->as($role);
        $v = (string) self::web()->fetchValue('SELECT asociacion_version FROM registros_tesoreria WHERE id = :id', ['id' => $s['sug']]);
        $r = $method === 'GET' ? $this->get($path) : $this->post($path, ['motivo' => 'Motivo de prueba suficiente', 'version' => $v,
            'empleado_id' => (string) $s['emp1'], 'destino' => (string) $s['lote2'], 'tipo' => 'RELACIONADO', 'modo' => 'reasociar', 'apellido_paterno' => 'X']);
        self::assertSame($expected, $r->status(), "$role $method $path");
    }
}
