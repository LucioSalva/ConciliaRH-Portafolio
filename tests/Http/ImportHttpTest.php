<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Http;

use ConciliaRH\Core\Logger;
use ConciliaRH\Core\Session\ArraySessionStore;
use ConciliaRH\Core\Session\Session;
use ConciliaRH\Domain\Audit\AuditContext;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\AuthService;
use ConciliaRH\Domain\Auth\LoginThrottle;
use ConciliaRH\Domain\Auth\PasswordHasher;
use ConciliaRH\Domain\Settings\SettingsService;
use ConciliaRH\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ImportHttpTest extends HttpTestCase
{
    /** Crea periodo + lote con un ADMIN y devuelve [periodoId, loteId]. @return array{int,int} */
    private function periodAndLot(): array
    {
        $this->createUser('admin.setup', ['ADMIN']);
        $this->loginAs('admin.setup');
        $r = $this->post('/periodos', ['anio' => '2032', 'mes' => '5', 'quincena' => '1']);
        $periodo = (int) basename((string) $r->header('Location'));
        $r = $this->post("/periodos/$periodo/lotes", ['origen' => 'Carpeta ficticia']);
        $lote = (int) basename((string) $r->header('Location'));
        $this->post('/logout');
        return [$periodo, $lote];
    }

    private function uploadBody(int $lote): array
    {
        return [
            'lote_id' => (string) $lote,
            'tipo_documento_id' => (string) self::web()->fetchValue("SELECT id FROM tipos_documento WHERE codigo = 'RRHH_TABULADO'"),
            'origen_administrativo_id' => (string) self::web()->fetchValue("SELECT id FROM origenes_administrativos WHERE codigo = 'RRHH'"),
            'observaciones' => 'Prueba HTTP',
        ];
    }

    public function testFullFlowPeriodLotUploadDetailAndList(): void
    {
        [$periodo, $lote] = $this->periodAndLot();
        $this->createUser('operador.flujo', ['ADMIN']);
        $this->loginAs('operador.flujo');
        self::assertStringContainsString('1RA QUINCENA MAYO 2032', $this->get("/periodos/$periodo")->body());
        self::assertStringContainsString('Lote 001', $this->get('/importaciones/nueva')->body());
        $r = $this->request('POST', '/importaciones', $this->uploadBody($lote), [], true, ['archivo' => $this->fileEntry('valid.xlsm', 'NÓMINA POLICÍA (1).xlsm')]);
        self::assertSame(303, $r->status());
        $id = (int) basename((string) $r->header('Location'));
        $show = $this->get("/importaciones/$id")->body();
        self::assertStringContainsString('NÓMINA POLICÍA (1).xlsm', $show);
        self::assertStringContainsString('Macros (no se ejecutan)', $show);
        self::assertStringContainsString('Huella pendiente', $show);
        self::assertStringContainsString('NÓMINA POLICÍA (1).xlsm', $this->get('/importaciones?q=policia')->body());
        self::assertStringContainsString('Recibido', $this->get("/lotes/$lote")->body());
        $api = json_decode($this->get("/api/importaciones/$id", ['Accept' => 'application/json'])->body(), true);
        self::assertSame(['success' => true], array_intersect_key($api, ['success' => 1]));
        self::assertSame('RECIBIDO', $api['data']['estado']);
        self::assertSame('PENDIENTE', $api['data']['proceso']['estado']);
        $this->post('/logout');
        $this->createUser('consulta.flujo', ['CONSULTA']);
        $this->loginAs('consulta.flujo');
        self::assertStringNotContainsString('Descargar original', $this->get("/importaciones/$id")->body(), 'CONSULTA no descarga originales');
        self::assertSame(403, $this->post("/importaciones/$id/descargar")->status());
        self::assertCount(0, $this->auditRows('archivos.descargar_original'));
    }

    public function testRejectedUploadShowsSafeErrorCode(): void
    {
        [, $lote] = $this->periodAndLot();
        $this->createUser('operador.rech', ['ADMIN']);
        $this->loginAs('operador.rech');
        $r = $this->request('POST', '/importaciones', $this->uploadBody($lote), [], true, ['archivo' => $this->fileEntry('fake-extension.xlsx')]);
        self::assertSame('/importaciones/nueva', $r->header('Location'));
        $form = $this->get('/importaciones/nueva')->body();
        self::assertStringContainsString('FIRMA_INVALIDA', $form);
        self::assertStringContainsString('Carga rechazada', $form);
        self::assertStringNotContainsString('FIRMA_INVALIDA', $this->get('/importaciones/nueva')->body(), 'El error se muestra una sola vez');
    }

    public function testUploadRequiresCsrf(): void
    {
        [, $lote] = $this->periodAndLot();
        $this->createUser('operador.csrf', ['ADMIN']);
        $this->loginAs('operador.csrf');
        $r = $this->request('POST', '/importaciones', $this->uploadBody($lote), [], false, ['archivo' => $this->fileEntry('valid.xlsx')]);
        self::assertSame(403, $r->status());
        self::assertSame(0, (int) self::web()->fetchValue('SELECT count(*) FROM archivos_importados WHERE lote_id = :l', ['l' => $lote]));
    }

    public function testOversizedBodyReturns413NotCsrfError(): void
    {
        $this->createUser('operador.grande', ['ADMIN']);
        $this->loginAs('operador.grande');
        $r = $this->request('POST', '/importaciones', [], [], false, [], ['CONTENT_LENGTH' => (string) (PHP_INT_MAX >> 1)]);
        self::assertSame(413, $r->status());
        self::assertStringContainsString('tamaño máximo', $r->body());
    }

    public function testDownloadIsAuthorizedAuditedAndSafeHeaders(): void
    {
        [, $lote] = $this->periodAndLot();
        $this->createUser('admin.desc', ['ADMIN']);
        $this->loginAs('admin.desc');
        $r = $this->request('POST', '/importaciones', $this->uploadBody($lote), [], true, ['archivo' => $this->fileEntry('valid.xls', 'NÓMINA "BOMBEROS"; 1.xls')]);
        $id = (int) basename((string) $r->header('Location'));
        self::assertSame(405, $this->get("/importaciones/$id/descargar")->status(), 'La descarga no es un GET (efecto auditado)');
        self::assertSame(403, $this->request('POST', "/importaciones/$id/descargar", [], [], false)->status(), 'Exige CSRF');
        $d = $this->post("/importaciones/$id/descargar");
        self::assertSame(200, $d->status());
        self::assertSame('application/octet-stream', $d->header('Content-Type'));
        self::assertStringStartsWith('attachment; filename="', (string) $d->header('Content-Disposition'));
        self::assertStringStartsWith('attachment; filename="NOMINA _BOMBEROS__ 1.xls";', (string) $d->header('Content-Disposition'), 'Comillas y ; del nombre neutralizados');
        self::assertStringContainsString("filename*=UTF-8''", (string) $d->header('Content-Disposition'));
        self::assertSame("default-src 'none'; sandbox", $d->header('Content-Security-Policy'));
        self::assertSame('nosniff', $d->header('X-Content-Type-Options'));
        self::assertSame('no-store, max-age=0', $d->header('Cache-Control'));
        self::assertNotNull($d->streamPath());
        self::assertStringStartsWith($this->storageRoot . '/imports/', (string) $d->streamPath());
        self::assertCount(1, $this->auditRows('archivos.descargar_original'));
        self::assertSame(404, $this->post('/importaciones/999999999/descargar')->status());
        self::assertSame(404, $this->post('/importaciones/..%2F..%2Fetc%2Fpasswd/descargar')->status());
    }

    public function testMacroWorkbookDownloadNeedsExplicitConfirmation(): void
    {
        [, $lote] = $this->periodAndLot();
        $this->createUser('admin.macro', ['ADMIN']);
        $this->loginAs('admin.macro');
        $r = $this->request('POST', '/importaciones', $this->uploadBody($lote), [], true, ['archivo' => $this->fileEntry('valid.xlsm')]);
        $id = (int) basename((string) $r->header('Location'));
        $show = $this->get("/importaciones/$id")->body();
        self::assertStringContainsString('El libro contiene macros', $show);
        self::assertStringContainsString('name="confirmar_macros"', $show);
        $sin = $this->post("/importaciones/$id/descargar");
        self::assertSame(303, $sin->status(), 'Sin confirmación no se descarga');
        self::assertNull($sin->streamPath());
        self::assertCount(0, $this->auditRows('archivos.descargar_original'));
        $con = $this->post("/importaciones/$id/descargar", ['confirmar_macros' => '1']);
        self::assertSame(200, $con->status());
        self::assertCount(1, $this->auditRows('archivos.descargar_original'));
    }

    public function testConsultaSeesVerificationStatusWithoutProcessDetails(): void
    {
        [, $lote] = $this->periodAndLot();
        $this->createUser('operador.sube', ['ADMIN']);
        $this->loginAs('operador.sube');
        $r = $this->request('POST', '/importaciones', $this->uploadBody($lote), [], true, ['archivo' => $this->fileEntry('valid.xlsx')]);
        $id = (int) basename((string) $r->header('Location'));
        $this->post('/logout');
        $this->createUser('consulta.ver', ['CONSULTA']);
        $this->loginAs('consulta.ver');
        $show = $this->get("/importaciones/$id")->body();
        self::assertStringContainsString('data-job-poll-url="/api/importaciones/' . $id . '"', $show, 'Sondea la API de importaciones (archivos.ver)');
        self::assertStringNotContainsString('href="/procesos/', $show, 'Sin procesos.ver no se enlaza el proceso');
        self::assertSame(200, $this->get("/api/importaciones/$id", ['Accept' => 'application/json'])->status());
    }

    /** @return list<array{string,string,string,int}> rol, método, ruta (con {p}/{l}), estado esperado */
    public static function matrix(): array
    {
        return [
            ['CONSULTA', 'GET', '/periodos', 200],
            ['CONSULTA', 'GET', '/periodos/{p}', 200],
            ['CONSULTA', 'GET', '/lotes/{l}', 200],
            ['CONSULTA', 'GET', '/importaciones', 200],
            ['CONSULTA', 'GET', '/periodos/nuevo', 403],
            ['CONSULTA', 'POST', '/periodos', 403],
            ['CONSULTA', 'POST', '/periodos/{p}/lotes', 403],
            ['CONSULTA', 'GET', '/importaciones/nueva', 403],
            ['CONSULTA', 'POST', '/importaciones', 403],
            ['CONSULTA', 'GET', '/catalogos', 403],
            ['CONSULTA', 'POST', '/lotes/{l}/cerrar', 403],
            ['CONSULTA', 'POST', '/lotes/{l}/anular', 403],
            ['CONSULTA', 'POST', '/lotes/{l}/oficial', 403],
            ['CONSULTA', 'POST', '/lotes/{l}/reabrir', 403],
            ['CONSULTA', 'POST', '/periodos/{p}/cerrar', 403],
            ['CONSULTA', 'POST', '/periodos/{p}/reabrir', 403],
            ['CONSULTA', 'POST', '/periodos/{p}/anular', 403],
            ['ADMIN', 'POST', '/periodos', 303],
            ['ADMIN', 'POST', '/periodos/{p}/lotes', 303],
            ['ADMIN', 'GET', '/importaciones/nueva', 200],
            ['ADMIN', 'POST', '/lotes/{l}/cerrar', 303],
            ['ADMIN', 'POST', '/periodos/{p}/cerrar', 303],
            ['ADMIN', 'POST', '/periodos/{p}/reabrir', 303],
            ['ADMIN', 'POST', '/periodos/{p}/anular', 303],
            ['ADMIN', 'POST', '/lotes/{l}/oficial', 303],
            ['ADMIN', 'POST', '/lotes/{l}/anular', 303],
            ['ADMIN', 'GET', '/catalogos', 403],
            ['ADMIN', 'POST', '/catalogos', 403],
            ['GOD', 'POST', '/lotes/{l}/oficial', 303],
            ['GOD', 'POST', '/catalogos', 303],
        ];
    }

    #[DataProvider('matrix')]
    public function testPermissionMatrix(string $role, string $method, string $path, int $expected): void
    {
        [$p, $l] = $this->periodAndLot();
        $user = strtolower($role) . '.mx';
        $this->createUser($role === 'GOD' ? 'god.mx' : $user, [$role]);
        $this->loginAs($role === 'GOD' ? 'god.mx' : $user);
        $path = str_replace(['{p}', '{l}'], [(string) $p, (string) $l], $path);
        $body = ['motivo' => 'Motivo de prueba suficiente', 'anio' => '2033', 'mes' => '1', 'quincena' => '1',
                 'catalogo' => 'origenes', 'codigo' => 'JURIDICO', 'nombre' => 'Jurídico'];
        $r = $method === 'GET' ? $this->get($path) : $this->post($path, $body);
        self::assertSame($expected, $r->status(), "$role $method $path");
    }

    public function testDevelopmentAccountsCannotLogInInProduction(): void
    {
        $id = $this->createUser('dev.prueba', ['ADMIN']);
        $this->assertSqlState('42501', self::web(), fn ($db) => $db->execute('UPDATE usuarios SET es_desarrollo = true WHERE id = :id', ['id' => $id]));
        $devId = (int) self::web()->fetchValue(
            "INSERT INTO usuarios (username, nombre, password_hash, es_desarrollo) VALUES ('dev.cuenta', '[DEV] Cuenta', :h, true) RETURNING id",
            ['h' => self::passwordHash()]
        );
        $db = self::web();
        $ctx = new AuditContext();
        $make = fn (bool $prod) => new AuthService($db, new PasswordHasher(),
            new LoginThrottle($db, new SettingsService($db, new AuditLogger($db, $ctx)), str_repeat('d', 64)),
            new Session(new ArraySessionStore()), new AuditLogger($db, $ctx), $ctx, new Logger(sys_get_temp_dir()), $prod);
        self::assertTrue($make(false)->attempt('dev.cuenta', self::PASSWORD, '192.0.2.88')['ok'], 'En desarrollo sí entra');
        self::assertFalse($make(true)->attempt('dev.cuenta', self::PASSWORD, '192.0.2.89')['ok'], 'Fuera de desarrollo NO entra');
        self::assertNull($make(true)->loadUser($devId), 'Una sesión existente de cuenta de desarrollo no es válida fuera de desarrollo');
    }
}
