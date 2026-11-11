<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Unit;

use ConciliaRH\Core\Logger;
use ConciliaRH\Core\Session\ArraySessionStore;
use ConciliaRH\Core\Session\DatabaseSessionHandler;
use ConciliaRH\Core\Session\Session;
use ConciliaRH\Core\View;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Auth\PasswordHasher;
use ConciliaRH\Domain\Auth\PasswordPolicy;
use ConciliaRH\Domain\Jobs\JobTypes;
use ConciliaRH\Domain\Settings\SettingsService;
use ConciliaRH\Domain\ValidationException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DomainUnitTest extends TestCase
{
    public function testLoggerRedactsAndMasksSensitiveData(): void
    {
        $out = Logger::redact([
            'password' => 'secreta', 'clabe' => '012345678901234567', 'nested' => ['token' => 'x'],
            'mensaje' => 'Cuenta 012345678901234567 y CURP FICT850101HDFXXX09 RFC FICT850101AB1',
            'sha256' => str_repeat('a', 64),
        ]);
        self::assertSame('[REDACTADO]', $out['password']);
        self::assertSame('[REDACTADO]', $out['clabe']);
        self::assertSame('[REDACTADO]', $out['nested']['token']);
        self::assertStringContainsString('**************4567', $out['mensaje']);
        self::assertStringNotContainsString('012345678901234567', $out['mensaje']);
        self::assertStringContainsString('[CURP]', $out['mensaje']);
        $h = 'd076a3496a2f64121a' . '123456' . '4969ff74f2b7fc797c078384052d0c92cbf52e53';
        self::assertSame(['huella' => $h, 'sha256' => $h], Logger::redact(['huella' => $h, 'sha256' => $h]), 'Huellas bajo su clave: intactas');
        self::assertStringContainsString('****', Logger::redact(['motivo' => $h])['motivo'], 'El mismo texto en un campo libre sí se enmascara');
        self::assertStringContainsString('****', Logger::redact(['huella' => 'ab012345678901234567'])['huella'], 'Longitud no estándar: se enmascara');
        self::assertSame('**************4567', Logger::maskText('012345678901234567'));
        self::assertStringContainsString('[RFC]', $out['mensaje']);
        self::assertSame(str_repeat('a', 64), $out['sha256']);
        self::assertSame('cta**************4567', Logger::maskText('cta012345678901234567'));
        self::assertSame('**************7891', Logger::maskText('012-180-001234567891'));
        self::assertSame('2026-09-29 20:15:01', Logger::maskText('2026-09-29 20:15:01'), 'Las fechas no se enmascaran');
        self::assertSame('[CURP]', Logger::maskText('fict850101hdfxxx09'));
    }

    public function testLoggerWritesJsonLines(): void
    {
        $dir = sys_get_temp_dir() . '/crh-log-' . bin2hex(random_bytes(4));
        mkdir($dir);
        (new Logger($dir))->security('Intento', ['ip' => '192.0.2.1', 'password' => 'x']);
        $files = glob($dir . '/security-cli*.log') ?: [];
        self::assertCount(1, $files);
        $file = $files[0];
        $line = json_decode(trim((string) file_get_contents($file)), true);
        self::assertSame('security', $line['channel']);
        self::assertSame('0640', substr(sprintf('%o', fileperms($file)), -4), 'Archivo nuevo 0640: ningún otro servicio lo altera');
        self::assertSame('[REDACTADO]', $line['context']['password']);
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }

    public function testAuditRedactionNeverStoresSecrets(): void
    {
        $r = AuditLogger::redact(['password_hash' => 'x', 'nombre' => 'Ana', 'deep' => ['token_csrf' => 'y']]);
        self::assertSame(['password_hash' => '[REDACTADO]', 'nombre' => 'Ana', 'deep' => ['token_csrf' => '[REDACTADO]']], $r);
    }

    public function testPasswordPolicy(): void
    {
        $p = new PasswordPolicy(12);
        self::assertNotNull($p->validate('Corta1!'));
        self::assertNotNull($p->validate('aaaaaaaaaaaaaaaa'));
        self::assertNotNull($p->validate('juan.perez-2026!', 'juan.perez'));
        self::assertNotNull($p->validate('todasminusculaslargas'));
        self::assertNull($p->validate('Nomina-Segura-2026', 'ana'));
        self::assertNotNull($p->validate(str_repeat('Ab1', 50)));
    }

    public function testPasswordHasherUsesArgon2idAndVerifies(): void
    {
        $h = new PasswordHasher();
        $hash = $h->hash('Nomina-Segura-2026');
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue($h->verify('Nomina-Segura-2026', $hash));
        self::assertFalse($h->verify('otra', $hash));
        self::assertFalse($h->needsRehash($hash));
        $h->dummyVerify('x');
        $this->addToAssertionCount(1);
    }

    public function testAuditFailureActionsAreExactAndExist(): void
    {
        $root = dirname(__DIR__, 2);
        $src = '';
        foreach (['app', 'bin', 'database/migrations'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if (preg_match('/\.(php|sql)$/', $f->getFilename()) && !str_ends_with($f->getPathname(), 'AuditRepository.php')) {
                    $src .= file_get_contents($f->getPathname());
                }
            }
        }
        foreach (\ConciliaRH\Domain\Audit\AuditRepository::FALLOS as $a) {
            self::assertStringContainsString("'" . $a . "'", $src, "$a no se registra en ningún lugar");
            self::assertTrue(\ConciliaRH\Domain\Audit\AuditRepository::failed($a));
        }
        foreach (['asociaciones.rechazar_sugerencia', 'empleados.fusion_rechazar', 'reportes.errores_para_pausar', 'auth.login'] as $ok) {
            self::assertFalse(\ConciliaRH\Domain\Audit\AuditRepository::failed($ok), "$ok no es una falla");
        }
    }

    public function testCurrentUserPermissions(): void
    {
        $god = new CurrentUser(1, 'god', 'Dios Todo', false, ['GOD'], []);
        $cons = new CurrentUser(2, 'ana', 'Ana López', false, ['CONSULTA'], ['dashboard.ver']);
        self::assertTrue($god->can('cualquier.permiso'));
        self::assertTrue($cons->can('dashboard.ver'));
        self::assertFalse($cons->can('usuarios.ver'));
        self::assertSame('AL', $cons->initials());
        self::assertSame('DG', (new CurrentUser(3, 'dev.god', '[DEV] God', false, ['GOD'], []))->initials(), 'Sin signos como iniciales');
        self::assertSame('ÁÑ', (new CurrentUser(4, 'x', 'ána ñúñez', false, [], []))->initials());
        self::assertSame('ÁL', (new CurrentUser(6, 'x', "A\u{0301}ngel Lo\u{0301}pez", false, [], []))->initials(), 'Nombre en NFD');
        self::assertSame('US', (new CurrentUser(5, 'usuario', '— · —', false, [], []))->initials(), 'Sin letras: del usuario');
    }

    public function testSessionLoginRegeneratesAndRotatesCsrf(): void
    {
        $store = new ArraySessionStore();
        $s = new Session($store);
        $before = $s->csrfToken();
        $idBefore = $store->id();
        $s->login(7);
        self::assertSame(7, $s->userId());
        self::assertNotSame($idBefore, $store->id());
        self::assertSame(1, $store->regenerations);
        self::assertNotSame($before, $s->csrfToken());
        self::assertTrue($s->isValidCsrfToken($s->csrfToken()));
        self::assertFalse($s->isValidCsrfToken($before));
        self::assertFalse($s->isValidCsrfToken(''));
        self::assertFalse($s->isValidCsrfToken(null));
        $s->logout();
        self::assertNull($s->userId());
    }

    public function testFlashAndOldInputNeverKeepPasswords(): void
    {
        $s = new Session(new ArraySessionStore());
        $s->flash('success', 'ok');
        self::assertSame([['type' => 'success', 'message' => 'ok']], $s->consumeFlashes());
        self::assertSame([], $s->consumeFlashes());
        $s->flashInput(['username' => 'ana', 'password' => 'x', '_token' => 't', 'password_nueva' => 'y']);
        self::assertSame(['username' => 'ana'], $s->consumeOldInput());
    }

    public function testSessionUserIdExtraction(): void
    {
        self::assertSame(5, DatabaseSessionHandler::userIdFrom(serialize(['_auth' => ['user_id' => 5]])));
        self::assertNull(DatabaseSessionHandler::userIdFrom(''));
        self::assertNull(DatabaseSessionHandler::userIdFrom('basura'));
        self::assertNull(DatabaseSessionHandler::userIdFrom('O:8:"stdClass":0:{}'));
        self::assertSame(64, strlen(DatabaseSessionHandler::hashId('abc')));
    }

    public function testJobContractValidation(): void
    {
        self::assertSame(['eco' => 'hola', 'duracion_segundos' => 3], JobTypes::validate('DIAGNOSTICO', ['eco' => 'hola', 'duracion_segundos' => 3]));
        $bad = [
            ['NO_EXISTE', []],
            ['DIAGNOSTICO', ['ruta' => '/etc/passwd']],
            ['DIAGNOSTICO', ['duracion_segundos' => 999]],
            ['DIAGNOSTICO', ['duracion_segundos' => '3']],
            ['DIAGNOSTICO', ['eco' => str_repeat('x', 101)]],
            ['DIAGNOSTICO', [0 => 'x']],
        ];
        foreach ($bad as [$type, $params]) {
            try {
                JobTypes::validate($type, $params);
                self::fail('Debió rechazar ' . json_encode([$type, $params]));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSettingValidation(): void
    {
        $dec = ['tipo' => 'decimal', 'minimo' => '0', 'maximo' => '100'];
        self::assertNull(SettingsService::validate($dec, '0.01'));
        self::assertNotNull(SettingsService::validate($dec, '-1'));
        self::assertNotNull(SettingsService::validate($dec, '101'));
        self::assertNotNull(SettingsService::validate($dec, '1e2'));
        $int = ['tipo' => 'entero', 'minimo' => '3', 'maximo' => '20'];
        self::assertNull(SettingsService::validate($int, '5'));
        self::assertNotNull(SettingsService::validate($int, '2'));
        self::assertNotNull(SettingsService::validate($int, '5.5'));
        self::assertNotNull(SettingsService::validate(['tipo' => 'booleano', 'minimo' => null, 'maximo' => null], 'si'));
    }

    public function testViewRejectsTraversalAndRendersLayoutWithEscaping(): void
    {
        $dir = sys_get_temp_dir() . '/crh-view-' . bin2hex(random_bytes(4));
        mkdir($dir . '/layouts', 0777, true);
        file_put_contents($dir . '/layouts/base.php', '<main><?= $this->section("extra") ?>|<?= $content ?></main>');
        file_put_contents($dir . '/page.php', '<?php $this->layout("layouts/base"); $this->start("extra"); ?>X<?php $this->stop(); ?><p><?= e($nombre) ?></p>');
        $view = new View($dir, '1.0');
        self::assertSame('<main>X|<p>&lt;b&gt;Ana&lt;/b&gt;</p></main>', $view->render('page', ['nombre' => '<b>Ana</b>']));
        self::assertSame('/assets/css/app.css?v=1.0', $view->asset('css/app.css'));
        foreach (['../etc/passwd', 'layouts/../../x', 'Page'] as $bad) {
            try {
                $view->render($bad);
                self::fail('Debió rechazar ' . $bad);
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        array_map('unlink', [$dir . '/layouts/base.php', $dir . '/page.php']);
        rmdir($dir . '/layouts');
        rmdir($dir);
    }

    public function testMoneyHelpersShowSignIconAndTextNotOnlyColor(): void
    {
        self::assertSame('<span class="money">$1,234.50</span>', money('1234.5'));
        self::assertStringContainsString('−$0.01', money('-0.005'));
        self::assertStringContainsString('diff--none', money_diff(null));
        $under = money_diff('-3500.00', '0.01');
        self::assertStringContainsString('diff--under', $under);
        self::assertStringContainsString('bi-caret-down-fill', $under);
        self::assertStringContainsString('−$3,500.00', $under);
        self::assertStringContainsString('(pagado de menos)', $under);
        $over = money_diff('12.30');
        self::assertStringContainsString('+$12.30', $over);
        self::assertStringContainsString('(pagado de más)', $over);
        self::assertStringContainsString('diff--even', money_diff('-0.01', '0.01'), 'Dentro de la tolerancia no es diferencia');
        self::assertStringContainsString('±$0.01', money_diff('-0.01', '0.01'));
        self::assertStringContainsString('$0.00', money_diff('0.00'));
        self::assertStringContainsString('&lt;b&gt;', money_diff('<b>'), 'Valor no numérico se escapa');
        self::assertSame(['unofficial', 'bi-patch-question', 'No oficial'], status_meta('NO_OFICIAL'));
        self::assertSame('review', status_meta('REVISION_MANUAL')[0]);
        self::assertSame('wine', status_meta('CERRADO')[0]);
    }
}
