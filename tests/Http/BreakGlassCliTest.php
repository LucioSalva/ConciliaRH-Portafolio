<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Http;

use ConciliaRH\Tests\Support\DatabaseTestCase;

/**
 * CLI de recuperación de emergencia y ancla de auditoría (FASE 10): argumentos, credenciales, producción, falla de BD.
 * El servicio se prueba en tests/Integration/GodRecoveryTest; el recorrido real (sudo, terminal, concurrencia, listado de
 * procesos) en tests/e2e/break_glass_rc.py sobre el servidor de prueba.
 */
final class BreakGlassCliTest extends DatabaseTestCase
{
    /** @param list<string> $args @param array<string,string> $env @return array{0:int,1:string} */
    private function cli(string $script, array $args, array $env = [], string $stdin = ''): array
    {
        $base = ['DB_DATABASE' => CONCILIARH_TEST_DB, 'APP_ENV' => 'testing', 'PATH' => '/usr/bin:/bin'];
        $p = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/' . $script, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + $base + getenv());
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return [proc_close($p), $out];
    }

    public function testPasswordIsNeverAcceptedAsArgumentAndUnknownArgumentsAreRejected(): void
    {
        foreach ([['reset-password', '--password=Secreta-En-Argumento-1'], ['reset-password', '--motivo=prueba de argumentos', 'Secreta-Posicional-1'],
                  ['status; id'], ['status', '--usuario=../../etc/passwd'], ['status', "--usuario=x' OR '1'='1"], ['status', '--usuario=$(id)'],
                  ['crear'], ['status', 'unlock'], ['--help']] as $args) {
            [$code, $out] = $this->cli('god-recovery.php', $args);
            self::assertSame(1, $code, implode(' ', $args));
            self::assertStringNotContainsString('Secreta', $out, 'no repite lo recibido');
            self::assertStringNotContainsString('uid=', $out);
        }
    }

    public function testRequiresOwnerCredentials(): void
    {
        // Como lo vería el usuario web o cualquiera sin /etc/conciliarh/cli.env
        [$code, $out] = $this->cli('god-recovery.php', ['reset-password', '--motivo=intento sin credenciales'], ['DB_MIGRATE_PASSWORD' => '']);
        self::assertSame(2, $code);
        self::assertStringContainsString('rol propietario', $out);
    }

    public function testProductionIsInteractiveOnly(): void
    {
        foreach (['production', 'staging', 'PRODUCTION', ''] as $env) {        // todo lo que no sea development/testing es producción
            [$code, $out] = $this->cli('god-recovery.php', ['reset-password', '--motivo=intento automatizado', '--si-confirmo', '--password-stdin'],
                ['APP_ENV' => $env, 'APP_DEBUG' => 'false'], "Clave-Por-Tuberia-2027\n");
            self::assertSame(1, $code, "APP_ENV=$env: $out");
            self::assertStringContainsString('En producción la recuperación es interactiva', $out, "APP_ENV=$env");
            self::assertStringNotContainsString('Clave-Por-Tuberia', $out);
        }
        // En producción y sin terminal, tampoco hay forma de confirmar por la entrada estándar
        [$code, $out] = $this->cli('god-recovery.php', ['status'], ['APP_ENV' => 'production', 'APP_DEBUG' => 'false']);
        if (preg_match('/^\s+usuario\s+(\S+)/m', $out, $m) === 1) {       // sólo si la BD de pruebas tiene UNA cuenta GOD utilizable
            [$code, $out] = $this->cli('god-recovery.php', ['unlock', '--motivo=sin terminal no se confirma'], ['APP_ENV' => 'production', 'APP_DEBUG' => 'false'],
                "RECUPERAR GOD {$m[1]}\n");
            self::assertSame(1, $code, $out);
            self::assertStringContainsString('Se requiere una terminal interactiva', $out);
        }
    }

    public function testDatabaseFailureExitsWithoutLeakingConnectionDetails(): void
    {
        $secret = (string) (getenv('DB_MIGRATE_PASSWORD') ?: self::env()->string('DB_MIGRATE_PASSWORD'));
        [$code, $out] = $this->cli('god-recovery.php', ['status'], ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '1']);
        self::assertSame(2, $code);
        self::assertStringContainsString('No se pudo completar', $out);
        foreach (['pgsql:', 'host=', '127.0.0.1', 'conciliarh_owner'] as $leak) {
            self::assertStringNotContainsString($leak, $out);
        }
        if ($secret !== '') {
            self::assertStringNotContainsString($secret, $out);
        }
    }

    public function testSttyIsResolvedByAbsolutePathNeverByPath(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/_cli.php');
        self::assertStringContainsString("'/usr/bin/stty', '/bin/stty'", $src);
        self::assertSame(0, preg_match('/proc_open\(\[\s*\'stty\'/', $src . file_get_contents(dirname(__DIR__, 2) . '/bin/create-god.php')
            . file_get_contents(dirname(__DIR__, 2) . '/bin/god-recovery.php')), 'ningún comando invoca stty por PATH');
        // (El recorrido con una terminal real y un PATH envenenado está en tests/e2e/break_glass_rc.py §5.)
        require_once dirname(__DIR__, 2) . '/bin/_cli.php';
        self::assertContains(cli_stty(), ['/usr/bin/stty', '/bin/stty', null]);
    }

    public function testHiddenPromptRefusesToAskWhenEchoCannotBeTurnedOff(): void
    {
        require_once dirname(__DIR__, 2) . '/bin/_cli.php';
        // PHPUnit no corre en una terminal: no se puede apagar el eco, así que NO se pide el secreto (no se lee la entrada)
        try {
            cli_prompt_hidden('Contraseña: ');
            self::fail('Debió negarse a pedir la contraseña con eco');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('No se pudo ocultar', $e->getMessage());
        }
    }

    public function testAuditAnchorIsReadOnlyMinimalAndVerifiable(): void
    {
        $o = $this->owner();
        $antes = (int) $o->fetchValue('SELECT count(*) FROM auditoria');
        [$code, $out] = $this->cli('audit-anchor.php', []);
        self::assertSame(0, $code, $out);
        $a = json_decode($out, true);
        self::assertSame(['formato', 'producto', 'version', 'commit', 'entorno', 'generado_at', 'auditoria'], array_keys($a));
        self::assertSame(['registros', 'ultima_secuencia', 'ultimo_hash', 'hash_version'], array_keys($a['auditoria']));
        self::assertSame('conciliarh-ancla-auditoria/1', $a['formato']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a['auditoria']['ultimo_hash']);
        self::assertSame($antes, (int) $o->fetchValue('SELECT count(*) FROM auditoria'), 'el ancla no escribe en la auditoría');
        // Sin datos personales: ningún nombre de usuario de la BD aparece en el ancla
        foreach ($o->fetchAll('SELECT username FROM usuarios') as $u) {
            self::assertStringNotContainsString((string) $u['username'], $out);
        }
        [$code, $msg] = $this->cli('audit-anchor.php', ['--verificar'], [], $out);
        self::assertSame(0, $code, $msg);
        self::assertStringContainsString('COINCIDE', $msg);
        self::assertStringContainsString('la cadena es íntegra', $msg, 'el ancla sólo vale junto con la verificación de la cadena');
        $a['auditoria']['ultimo_hash'] = str_repeat('0', 64);
        [$code, $msg] = $this->cli('audit-anchor.php', ['--verificar'], [], (string) json_encode($a));
        self::assertSame(3, $code);
        self::assertStringContainsString('NO COINCIDE', $msg);
        foreach (['{"x":1}', 'no es json', str_repeat('a', 5000)] as $malo) {
            self::assertSame(1, $this->cli('audit-anchor.php', ['--verificar'], [], $malo)[0]);
        }
        self::assertSame(1, $this->cli('audit-anchor.php', ['--enviar=https://ejemplo.invalid'])[0], 'no hay opción de envío');
    }

    public function testAnchorAndRecoveryMakeNoNetworkCalls(): void
    {
        foreach (['bin/audit-anchor.php', 'bin/god-recovery.php', 'bin/_cli.php', 'app/Domain/Users/GodRecovery.php'] as $f) {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $f);
            foreach (['curl_', 'fsockopen', 'stream_socket_client', 'http://', 'https://', 'file_get_contents(\'http', 'mail('] as $red) {
                self::assertStringNotContainsString($red, $src, "$f: $red");
            }
        }
    }

    /** El inventario del servidor (FASE 10A) revisa su propio texto y no corre si contiene una orden que modifique el servidor. */
    public function testServerInventoryScriptRefusesToRunIfItContainsModifyingCommands(): void
    {
        $script = dirname(__DIR__, 2) . '/deploy/setup/inventario-servidor.sh';
        $run = static function (string $file): array {
            $p = proc_open(['bash', $file, '--verificar'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            return [proc_close($p), $out];
        };
        [$code, $out] = $run($script);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('sólo contiene órdenes de consulta', $out);
        $tmp = sys_get_temp_dir() . '/crh-inv-' . bin2hex(random_bytes(4)) . '.sh';
        try {
            foreach (['apt-get install -y x', 'dnf install x', 'yum update', 'pacman -S x', 'systemctl restart apache2', 'systemctl stop postgresql',
                      'ufw disable', 'ufw enable', 'firewall-cmd --add-port=1/tcp', 'iptables -A INPUT -j DROP', 'iptables -D INPUT 1',
                      'psql -c "DROP TABLE x"', 'psql -d x -f y.sql', 'chmod 777 /etc', 'chown root /x', 'rm -rf /tmp/x', 'mv /a /b', 'cp x /etc/y',
                      'sed -i s/a/b/ /etc/hosts', 'echo x > /etc/passwd', 'curl https://ejemplo.invalid', 'tee /etc/x', 'kill 1', 'mkdir /opt/x'] as $orden) {
                file_put_contents($tmp, file_get_contents($script) . "\n" . $orden . "\n");
                [$code, $out] = $run($tmp);
                self::assertSame(3, $code, "No detectó: $orden");
                self::assertStringContainsString('ABORTADO', $out);
            }
        } finally {
            @unlink($tmp);
        }
        // Y en su texto no hay ninguna de esas órdenes ni lecturas de configuración de aplicaciones
        $src = (string) file_get_contents($script);
        foreach (['/etc/conciliarh/', '.env"', 'pg_hba', 'shadow', 'ssl/private', 'id_rsa', 'id_ed25519'] as $ruta) {
            self::assertStringNotContainsString($ruta, preg_replace('/^\s*#.*$/m', '', $src), "lee $ruta");
        }
    }
}
