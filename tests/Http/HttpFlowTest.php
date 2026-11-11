<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Http;

use ConciliaRH\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class HttpFlowTest extends HttpTestCase
{
    public function testLoginPageUsesUsernameNotEmailAndHasCsrf(): void
    {
        $r = $this->get('/login');
        self::assertSame(200, $r->status());
        self::assertStringContainsString('name="username"', $r->body());
        self::assertStringContainsString('name="_token"', $r->body());
        self::assertStringNotContainsString('type="email"', $r->body());
        foreach (['Registrarse', 'Crear cuenta', 'Sign up', 'href="/registro"'] as $signup) {
            self::assertStringNotContainsStringIgnoringCase($signup, $r->body());
        }
        self::assertStringContainsString("default-src 'self'", (string) $r->header('Content-Security-Policy'));
        self::assertSame('DENY', $r->header('X-Frame-Options'));
        self::assertSame('no-store, max-age=0', $r->header('Cache-Control'));
    }

    public function testNoPublicRegistrationRoute(): void
    {
        foreach (['/registro', '/register', '/signup'] as $path) {
            self::assertSame(404, $this->get($path)->status());
        }
    }

    public function testCsrfIsRequiredForStateChangingRequests(): void
    {
        $this->createUser('csrf.user', ['CONSULTA']);
        $r = $this->request('POST', '/login', ['username' => 'csrf.user', 'password' => self::PASSWORD], [], false);
        self::assertSame(403, $r->status());
        self::assertStringContainsString('expiró', $r->body());
        $r = $this->post('/login', ['_token' => str_repeat('0', 64), 'username' => 'csrf.user', 'password' => self::PASSWORD]);
        self::assertSame(403, $r->status());
        self::assertNull($this->store->get('_auth'));
    }

    public function testForeignOriginIsRejected(): void
    {
        $this->createUser('origin.user', ['CONSULTA']);
        $r = $this->post('/login', ['username' => 'origin.user', 'password' => self::PASSWORD], ['Origin' => 'https://atacante.example', 'Host' => 'localhost:8120']);
        self::assertSame(403, $r->status());
    }

    public function testLoginFlowRegeneratesSessionAndShowsDashboard(): void
    {
        $this->createUser('flujo', ['ADMIN']);
        $bad = $this->post('/login', ['username' => 'flujo', 'password' => 'incorrecta-12345']);
        self::assertRedirect($bad, '/login');
        self::assertStringContainsString('Usuario o contraseña incorrectos', $this->get('/login')->body());
        $this->loginAs('flujo');
        self::assertSame(1, $this->store->regenerations);
        $dash = $this->get('/');
        self::assertSame(200, $dash->status());
        self::assertStringContainsString('Tablero', $dash->body());
        self::assertStringNotContainsString('href="/procesos"', $dash->body(), 'ADMIN no ve Procesos en el menú (sólo GOD, 0024)');
        self::assertStringNotContainsString('href="/usuarios"', $dash->body(), 'ADMIN no ve Usuarios en el menú');
        self::assertStringNotContainsString('href="/auditoria"', $dash->body(), 'ADMIN no ve Auditoría en el menú (sólo GOD, 0026)');
        self::assertRedirect($this->get('/login'), '/');
    }

    public function testAdvancedMenuIsForAdminAndGodButNotConsulta(): void
    {
        foreach (['ADMIN' => true, 'GOD' => true, 'CONSULTA' => false] as $role => $sees) {
            $user = 'menu.' . strtolower($role);
            $this->createUser($user, [$role]);
            $this->loginAs($user);
            $body = $this->get('/')->body();
            foreach (['<div class="sidebar__section">Avanzado</div>', 'href="/importaciones"', 'href="/conciliaciones"', 'href="/revision-masiva"'] as $needle) {
                $sees ? self::assertStringContainsString($needle, $body, "$role $needle") : self::assertStringNotContainsString($needle, $body, "$role $needle");
            }
            self::assertSame($role === 'GOD', str_contains($body, '<div class="sidebar__section">Administración</div>'), "$role Administración");
            $this->post('/logout');
        }
    }

    public function testUnauthenticatedAccessRedirectsOrReturns401Json(): void
    {
        self::assertRedirect($this->get('/usuarios'), '/login');
        $api = $this->get('/api/procesos/1');
        self::assertSame(401, $api->status());
        self::assertSame(['success' => false, 'error' => ['code' => 'NO_AUTENTICADO', 'message' => 'Debe iniciar sesión.']], json_decode($api->body(), true));
    }

    /** @return list<array{string,string,string,int}> rol, método, ruta, estado esperado */
    public static function accessMatrix(): array
    {
        return [
            ['CONSULTA', 'GET', '/', 200],
            ['CONSULTA', 'GET', '/usuarios', 403],
            ['CONSULTA', 'GET', '/procesos', 403],
            ['CONSULTA', 'GET', '/auditoria', 403],
            ['CONSULTA', 'GET', '/roles', 403],
            ['CONSULTA', 'GET', '/configuracion', 403],
            ['CONSULTA', 'GET', '/sistema/salud', 403],
            ['ADMIN', 'GET', '/usuarios', 403],
            ['ADMIN', 'GET', '/usuarios/nuevo', 403],
            ['ADMIN', 'GET', '/auditoria', 403],
            ['GOD', 'GET', '/auditoria', 200],
            ['ADMIN', 'GET', '/roles', 403],
            ['ADMIN', 'GET', '/procesos', 403],
            ['ADMIN', 'GET', '/sistema/salud', 403],
            ['ADMIN', 'GET', '/catalogos', 403],
            ['ADMIN', 'GET', '/conceptos', 403],
            ['GOD', 'GET', '/procesos', 200],
            ['GOD', 'GET', '/conceptos', 200],
            ['ADMIN', 'GET', '/configuracion', 403],
            ['ADMIN', 'POST', '/roles', 403],
            ['ADMIN', 'POST', '/configuracion', 403],
            ['ADMIN', 'POST', '/procesos/diagnostico', 403],
            ['GOD', 'GET', '/usuarios', 200],
            ['GOD', 'POST', '/procesos/diagnostico', 303],
        ];
    }

    #[DataProvider('accessMatrix')]
    public function testBackendEnforcesPermissions(string $role, string $method, string $path, int $expected): void
    {
        $this->createUser('rbac.' . strtolower($role), [$role]);
        $this->loginAs('rbac.' . strtolower($role));
        $r = $method === 'GET' ? $this->get($path) : $this->post($path, ['codigo' => 'X_ROL', 'nombre' => 'Rol', 'clave' => 'conciliacion.tolerancia_monetaria', 'valor' => '0.02']);
        self::assertSame($expected, $r->status(), "$role $method $path");
    }

    public function testAdminCannotEditGodAccountThroughDirectRequest(): void
    {
        $godId = $this->createUser('god.http', ['GOD']);
        $this->createUser('admin.http', ['ADMIN']);
        $this->loginAs('admin.http');
        self::assertSame(403, $this->post("/usuarios/$godId", ['nombre' => 'Hackeado', 'roles' => ['CONSULTA']])->status());
        self::assertSame(403, $this->post("/usuarios/$godId/estado", ['activo' => '0'])->status());
        self::assertSame(403, $this->post("/usuarios/$godId/password", ['password' => 'Nueva-Clave-2026x'])->status());
        self::assertNotSame('Hackeado', self::web()->fetchValue('SELECT nombre FROM usuarios WHERE id = :id', ['id' => $godId]));
    }

    public function testMustChangePasswordBlocksEverythingElse(): void
    {
        $this->createUser('temporal', ['ADMIN'], mustChange: true);
        $this->loginAs('temporal');
        self::assertRedirect($this->get('/periodos'), '/cuenta/password');
        self::assertSame(403, $this->get('/api/sesion', ['Accept' => 'application/json'])->status());
        self::assertSame(200, $this->get('/cuenta/password')->status());
        $ok = $this->post('/cuenta/password', ['password_actual' => self::PASSWORD, 'password_nueva' => 'Definitiva-2026!', 'password_confirmacion' => 'Definitiva-2026!']);
        self::assertRedirect($ok, '/');
        self::assertSame(200, $this->get('/periodos')->status());
    }

    public function testChangePasswordAttemptsAreLimited(): void
    {
        $this->createUser('adivina', ['CONSULTA']);
        $this->loginAs('adivina');
        for ($i = 0; $i < 4; $i++) {
            self::assertRedirect($this->post('/cuenta/password', ['password_actual' => 'mala-' . $i, 'password_nueva' => 'Nueva-Clave-2026x', 'password_confirmacion' => 'Nueva-Clave-2026x']), '/cuenta/password');
        }
        $last = $this->post('/cuenta/password', ['password_actual' => 'mala-final', 'password_nueva' => 'Nueva-Clave-2026x', 'password_confirmacion' => 'Nueva-Clave-2026x']);
        self::assertRedirect($last, '/login');
        self::assertNull($this->store->get('_auth'), 'La sesión se cierra tras 5 intentos');
    }

    public function testExpiredSessionPostRedirectsToLoginInsteadOfCsrfError(): void
    {
        $this->createUser('expira.post', ['CONSULTA']);
        $this->loginAs('expira.post');
        $auth = $this->store->get('_auth');
        $auth['last_activity'] = time() - 7200;
        $this->store->set('_auth', $auth);
        self::assertRedirect($this->request('POST', '/logout', [], [], false), '/login');
    }

    public function testApiPollingDoesNotRenewIdleTimeout(): void
    {
        $this->createUser('sondeo', ['ADMIN']);
        $this->loginAs('sondeo');
        $auth = $this->store->get('_auth');
        $auth['last_activity'] = time() - 600;
        $this->store->set('_auth', $auth);
        $this->get('/api/sesion', ['Accept' => 'application/json']);
        self::assertSame($auth['last_activity'], $this->store->get('_auth')['last_activity']);
        $this->get('/');
        self::assertGreaterThan($auth['last_activity'], $this->store->get('_auth')['last_activity']);
    }

    public function testUserCreationEscapesOutputAgainstXss(): void
    {
        $this->createUser('god.xss', ['GOD']);
        $this->loginAs('god.xss');
        $r = $this->post('/usuarios', ['username' => 'xss.test', 'nombre' => '<script>alert(1)</script>', 'password' => 'Temporal-Segura-1', 'roles' => ['CONSULTA']]);
        self::assertSame(303, $r->status());
        $list = $this->get('/usuarios?q=xss')->body();
        self::assertStringNotContainsString('<script>alert(1)</script>', $list);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $list);
    }

    public function testValidationErrorsReturnToFormWithoutPassword(): void
    {
        $this->createUser('god.val.http', ['GOD']);
        $this->loginAs('god.val.http');
        $r = $this->post('/usuarios', ['username' => 'X', 'nombre' => 'Nombre Válido', 'password' => 'corta', 'roles' => ['CONSULTA']]);
        self::assertRedirect($r, '/usuarios/nuevo');
        $form = $this->get('/usuarios/nuevo')->body();
        self::assertStringContainsString('Revise los datos marcados', $form);
        self::assertStringContainsString('value="Nombre Válido"', $form);
        self::assertStringNotContainsString('value="corta"', $form);
    }

    public function testDiagnosticJobThroughWebAndJsonStatus(): void
    {
        $this->createUser('god.job', ['GOD']);
        $this->loginAs('god.job');
        $r = $this->post('/procesos/diagnostico');
        self::assertSame(303, $r->status());
        $id = (int) basename((string) $r->header('Location'));
        $api = json_decode($this->get("/api/procesos/$id", ['Accept' => 'application/json'])->body(), true);
        self::assertTrue($api['success']);
        self::assertSame('PENDIENTE', $api['data']['estado']);
        self::assertArrayNotHasKey('parametros', $api['data']);
        self::assertSame(200, $this->get("/procesos/$id")->status());
    }

    public function testErrorsNeverLeakTechnicalDetails(): void
    {
        $this->createUser('god.err', ['GOD']);
        $this->loginAs('god.err');
        $r = $this->get('/api/procesos/999999999', ['Accept' => 'application/json']);
        self::assertSame(404, $r->status());
        self::assertSame('NO_ENCONTRADO', json_decode($r->body(), true)['error']['code']);
        $html = $this->get('/usuarios/999999999');
        self::assertSame(404, $html->status());
        self::assertStringNotContainsString('Exception', $html->body());
        self::assertStringNotContainsString('/var/www', $html->body());
    }

    public function testHealthEndpointIsStateless(): void
    {
        $r = $this->get('/api/health');
        self::assertSame(200, $r->status());
        self::assertNull($this->store->get('_csrf'), 'health no debe iniciar sesión');
        self::assertSame(405, $this->request('POST', '/api/health', [], [], false)->status());
    }

    public function testLogoutInvalidatesSession(): void
    {
        $this->createUser('salida', ['CONSULTA']);
        $this->loginAs('salida');
        self::assertRedirect($this->post('/logout'), '/login');
        self::assertNull($this->store->get('_auth'));
        self::assertRedirect($this->get('/'), '/login');
        self::assertCount(1, $this->auditRows('auth.logout'));
    }

    public function testIdleSessionExpires(): void
    {
        $this->createUser('inactivo.s', ['CONSULTA']);
        $this->loginAs('inactivo.s');
        $auth = $this->store->get('_auth');
        $auth['last_activity'] = time() - 3600;
        $this->store->set('_auth', $auth);
        self::assertRedirect($this->get('/'), '/login');
        self::assertStringContainsString('Su sesión expiró', $this->get('/login')->body());
    }

    public function testDeactivatedUserIsLoggedOutOnNextRequest(): void
    {
        $id = $this->createUser('se.desactiva', ['CONSULTA']);
        $this->loginAs('se.desactiva');
        self::web()->execute('UPDATE usuarios SET activo = false WHERE id = :id', ['id' => $id]);
        self::assertRedirect($this->get('/'), '/login');
    }
}
