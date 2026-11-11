<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Unit;

use ConciliaRH\Core\Config;
use ConciliaRH\Core\Env;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Core\Paginator;
use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Core\Router;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CoreTest extends TestCase
{
    public function testEnvParserHandlesQuotesCommentsAndInvalidLines(): void
    {
        $vars = Env::parse(<<<'ENV'
            # comentario
            APP_ENV=production
            APP_URL="https://x.example"
            NAME='valor # no es comentario'
            PLAIN=abc # comentario final
            lower=ignorado
            =sin_clave
            ENV);
        self::assertSame('production', $vars['APP_ENV']);
        self::assertSame('https://x.example', $vars['APP_URL']);
        self::assertSame('valor # no es comentario', $vars['NAME']);
        self::assertSame('abc', $vars['PLAIN']);
        self::assertArrayNotHasKey('lower', $vars);
    }

    public function testEnvTypedAccessors(): void
    {
        $env = Env::fromArray(['A' => 'true', 'B' => '42', 'C' => 'x', 'D' => '']);
        self::assertTrue($env->bool('A'));
        self::assertSame(42, $env->int('B'));
        self::assertSame(7, $env->int('C', 7));
        self::assertSame('def', $env->string('D', 'def'));
    }

    private static function config(array $app, array $session = ['secure' => true]): Config
    {
        return new Config(['app' => $app + ['key' => str_repeat('a', 64), 'hmac_key' => str_repeat('b', 64), 'env' => 'production', 'debug' => false, 'url' => 'https://x',
            'enc_key_id' => 'k1', 'enc_keys' => 'k1:' . str_repeat('c', 64), 'report_enc_key_id' => 'r1', 'report_enc_keys' => 'r1:' . str_repeat('e', 64)],
            'session' => $session]);
    }

    public function testSecureConfigPasses(): void
    {
        self::config([])->assertSecure();
        $this->addToAssertionCount(1);
    }

    public function testProductionWithDebugIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_DEBUG debe ser false');
        self::config(['debug' => true])->assertSecure();
    }

    public function testAccountEncryptionKeyRingIsRequired(): void
    {
        foreach (['', 'k1:corta', 'k2:' . str_repeat('c', 64), 'K1:' . str_repeat('c', 64)] as $ring) {
            try {
                self::config(['enc_keys' => $ring])->assertSecure();
                self::fail('Debió rechazar el anillo: ' . $ring);
            } catch (RuntimeException $e) {
                self::assertStringContainsString('ACCOUNT_ENC_KEYS', $e->getMessage());
            }
        }
        $ring = Config::parseKeyRing('k1:' . str_repeat('c', 64) . ', k2:' . str_repeat('d', 64));
        self::assertSame(['k1', 'k2'], array_keys((array) $ring));
        self::assertSame(32, strlen((string) $ring['k2']));
    }

    /** FASE 8: el anillo de reportes es obligatorio y NUNCA reutiliza la llave de cuentas, la HMAC ni APP_KEY (separación de llaves). */
    public function testReportKeyRingIsRequiredAndSeparatedFromOtherKeys(): void
    {
        foreach (['', 'r1:corta', 'r2:' . str_repeat('e', 64), 'r1:' . str_repeat('c', 64), 'r1:' . str_repeat('a', 64), 'r1:' . str_repeat('b', 64),
                  'r1:' . str_repeat('e', 64) . ',r0:' . str_repeat('c', 64)] as $ring) {
            try {
                self::config(['report_enc_keys' => $ring])->assertSecure();
                self::fail('Debió rechazar el anillo de reportes: ' . $ring);
            } catch (RuntimeException $e) {
                self::assertStringContainsString('REPORT_ENC_KEYS', $e->getMessage());
            }
        }
    }

    public function testMissingKeysAreRejected(): void
    {
        $this->expectExceptionMessage('APP_KEY debe tener 64');
        self::config(['key' => 'corta'])->assertSecure();
    }

    public function testHttpsRequiresSecureCookies(): void
    {
        $this->expectExceptionMessage('SESSION_SECURE');
        self::config([], ['secure' => false])->assertSecure();
    }

    public function testDebugNeverActiveInProduction(): void
    {
        self::assertFalse((new Config(['app' => ['env' => 'production', 'debug' => true]]))->isDebug());
        self::assertTrue((new Config(['app' => ['env' => 'development', 'debug' => true]]))->isDebug());
    }

    public function testRouterMatchesNumericParamsOnly(): void
    {
        $r = new Router();
        $r->get('/usuarios/{id}', ['C', 'show'], [], 'u.show');
        $r->post('/usuarios/{id}', ['C', 'update']);
        $m = $r->match('GET', '/usuarios/15');
        self::assertSame(['id' => 15], $m['params']);
        self::assertSame('/usuarios/9', $r->url('u.show', ['id' => 9]));
        foreach (['/usuarios/abc', '/usuarios/0', '/usuarios/1;DROP', '/usuarios/-1'] as $bad) {
            try {
                $r->match('GET', $bad);
                self::fail("Debió rechazar $bad");
            } catch (HttpException $e) {
                self::assertSame(404, $e->status());
            }
        }
    }

    public function testRouterReturns405ForWrongMethod(): void
    {
        $r = new Router();
        $r->get('/x', ['C', 'a']);
        try {
            $r->match('DELETE', '/x');
            self::fail('405 esperado');
        } catch (HttpException $e) {
            self::assertSame(405, $e->status());
        }
    }

    public function testRouterGroupMiddlewareIsInherited(): void
    {
        $r = new Router();
        $r->group(['auth'], function (Router $r): void {
            $r->get('/a', ['C', 'a'], ['perm:x.y']);
        });
        self::assertSame(['auth', 'perm:x.y'], $r->match('GET', '/a')['route']['middleware']);
    }

    public function testRequestNormalizesPathAndIgnoresArraysInStringInput(): void
    {
        self::assertSame('/usuarios', Request::normalizePath('/usuarios/'));
        self::assertSame('/', Request::normalizePath(''));
        $req = new Request('POST', '/x', [], ['nombre' => ['<a>'], 'ok' => ' valor ', 'malo' => "a\xE9b", 'lista' => ['ok', "\xE9", ['x']]],
            ['HTTP_USER_AGENT' => "UA\xE9"]);
        self::assertSame('', $req->string('nombre'));
        self::assertSame('valor', $req->string('ok'));
        self::assertSame('', $req->string('malo'), 'UTF-8 inválido se descarta');
        self::assertSame(['ok'], $req->stringList('lista'));
        self::assertTrue(mb_check_encoding($req->userAgent(), 'UTF-8'));
    }

    public function testRedirectRejectsExternalLocations(): void
    {
        self::assertSame('/', Response::redirect('https://evil.example')->header('Location'));
        self::assertSame('/', Response::redirect('//evil.example')->header('Location'));
        self::assertSame('/', Response::redirect('/\\evil.example')->header('Location'));
        self::assertSame('/', Response::redirect("/ok\r\nSet-Cookie: x=1")->header('Location'));
        self::assertSame('/usuarios', Response::redirect('/usuarios')->header('Location'));
    }

    public function testApiEnvelopes(): void
    {
        $ok = json_decode(Response::apiOk(['a' => 1])->body(), true);
        self::assertSame(['success' => true, 'data' => ['a' => 1], 'message' => null], $ok);
        $err = Response::apiError('X', 'mensaje', 422);
        self::assertSame(422, $err->status());
        self::assertSame(['success' => false, 'error' => ['code' => 'X', 'message' => 'mensaje']], json_decode($err->body(), true));
    }

    public function testPaginator(): void
    {
        $p = new Paginator(3, 20, 95, '/usuarios', ['q' => 'ana', 'x' => ['arr']]);
        self::assertSame(5, $p->pages);
        self::assertSame(41, $p->from());
        self::assertSame(60, $p->to());
        self::assertSame('/usuarios?q=ana&page=4', $p->url(4));
        self::assertSame([1, 2, 3, 4, 5], $p->window());
        $big = new Paginator(10, 10, 1000, '/x', []);
        self::assertSame([1, null, 8, 9, 10, 11, 12, null, 100], $big->window());
        self::assertSame(1, Paginator::pageFrom(new Request('GET', '/', ['page' => '0'])));
        self::assertSame(1, Paginator::pageFrom(new Request('GET', '/', ['page' => '1 OR 1=1'])));
    }

    public function testEscapeHelper(): void
    {
        self::assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', e('<script>alert("x")</script>'));
        self::assertSame('&apos; onmouseover=&apos;x', e("' onmouseover='x"));
        self::assertSame('', e(null));
    }

    /** FASE 9 §34: el scheduler no arranca sin su rol, su anillo de reportes o el almacenamiento. */
    public function testSchedulerRefusesIncompleteConfiguration(): void
    {
        $base = ['app' => ['env' => 'production', 'debug' => false, 'report_enc_keys' => 'r1:' . str_repeat('ab', 32), 'report_enc_key_id' => 'r1'],
                 'scheduler' => ['password' => 'x'], 'storage' => ['path' => sys_get_temp_dir()]];
        (new \ConciliaRH\Core\Config($base))->assertScheduler();
        $this->addToAssertionCount(1);
        foreach ([['scheduler' => ['password' => '']], ['app' => ['report_enc_keys' => '']], ['app' => ['report_enc_key_id' => 'r9']],
                  ['storage' => ['path' => '/no/existe']], ['app' => ['debug' => true]]] as $mal) {
            $cfg = array_replace_recursive($base, $mal);
            try {
                (new \ConciliaRH\Core\Config($cfg))->assertScheduler();
                self::fail('Debió rechazar: ' . json_encode($mal));
            } catch (\RuntimeException $e) {
                self::assertStringNotContainsString(str_repeat('ab', 32), $e->getMessage(), 'Nunca muestra la llave');
            }
        }
    }

    /** FASE 9 §48: BUILD.json con formato estricto; un valor alterado no llega a la pantalla. */
    public function testBuildInfoIsValidated(): void
    {
        $dir = sys_get_temp_dir() . '/crh-build-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            self::assertSame('desarrollo', \ConciliaRH\Core\BuildInfo::read($dir, '1.0.0')['origen']);
            file_put_contents($dir . '/BUILD.json', json_encode(['version' => '0.1.0-rc.1', 'commit' => str_repeat('a1', 20), 'built_at' => '2026-10-01T12:00:00Z']));
            $b = \ConciliaRH\Core\BuildInfo::read($dir, '0.0.0');
            self::assertSame(['0.1.0-rc.1', str_repeat('a1', 20), '2026-10-01T12:00:00Z', 'paquete'], [$b['version'], $b['commit'], $b['built_at'], $b['origen']]);
            file_put_contents($dir . '/BUILD.json', json_encode(['version' => '<script>', 'commit' => 'zz<b>', 'built_at' => 'ayer']));
            $b = \ConciliaRH\Core\BuildInfo::read($dir, '0.0.0');
            self::assertSame(['0.0.0', null, null], [$b['version'], $b['commit'], $b['built_at']]);
        } finally {
            @unlink($dir . '/BUILD.json');
            @rmdir($dir);
        }
    }
}
