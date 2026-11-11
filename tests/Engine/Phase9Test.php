<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Engine;

use ConciliaRH\Core\Config;
use ConciliaRH\Core\Env;
use ConciliaRH\Engine\Worker\WorkerSettings;
use ConciliaRH\Tests\Support\EngineTestCase;

final class Phase9Test extends EngineTestCase
{
    private const K = 'abababababababababababababababababababababababababababababababab';
    private const SETTINGS_KEYS = ['APP_ENV', 'WORKER_DB_PASSWORD', 'ACCOUNT_ENC_KEYS', 'ACCOUNT_ENC_KEY_ID', 'ACCOUNT_HMAC_KEYS', 'ACCOUNT_HMAC_KEY',
                                   'STORAGE_PATH', 'DB_HOST'];

    /** @var array<string,string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::SETTINGS_KEYS as $k) {
            $this->savedEnv[$k] = getenv($k);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $k => $v) {
            putenv($v === false ? $k : "$k=$v");
        }
        parent::tearDown();
    }

    /** @param array<string,string> $kw @return array<string,string> */
    private static function env(array $kw = []): array
    {
        $base = ['APP_ENV' => 'production', 'WORKER_DB_PASSWORD' => 'x', 'ACCOUNT_ENC_KEYS' => 'k1:' . self::K, 'ACCOUNT_ENC_KEY_ID' => 'k1',
                 'ACCOUNT_HMAC_KEYS' => 'h1:' . str_repeat('cd', 32), 'STORAGE_PATH' => '/tmp'];
        return [...$base, ...$kw];
    }

    private static function basePath(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @param array<string,string> $vars */
    private static function loadSettings(array $vars): WorkerSettings
    {
        foreach (self::SETTINGS_KEYS as $k) {
            putenv(array_key_exists($k, $vars) ? "$k={$vars[$k]}" : $k);
        }
        return self::settingsFrom(Env::fromArray($vars), self::basePath());
    }

    private static function settingsFrom(Env $env, string $basePath): WorkerSettings
    {
        $factory = require self::basePath() . '/config/app.php';
        return WorkerSettings::fromConfig(new Config($factory($env, $basePath)));
    }

    public function testCompleteConfigurationHasNoProblems(): void
    {
        self::assertSame([], self::loadSettings(self::env())->problems());
    }

    public function testMissingCriticalConfigurationIsReportedWithoutValues(): void
    {
        $casos = [
            'WORKER_DB_PASSWORD' => '',
            'ACCOUNT_ENC_KEYS' => '',
            'ACCOUNT_ENC_KEY_ID' => 'k9',
            'ACCOUNT_HMAC_KEYS' => 'h1:corta',
            'APP_ENV' => 'produccion',
            'STORAGE_PATH' => '/no/existe/conciliarh',
        ];
        foreach ($casos as $var => $valor) {
            $env = self::env([$var => $valor]);
            if ($var === 'ACCOUNT_HMAC_KEYS') {
                $env['ACCOUNT_HMAC_KEY'] = '';
            }
            $problemas = self::loadSettings($env)->problems();
            self::assertNotEmpty($problemas, $var);
            foreach ($problemas as $p) {
                self::assertStringNotContainsString(self::K, $p, 'nunca muestra la llave');
            }
        }
    }

    public function testWorkerCliRefusesToStartWithIncompleteConfiguration(): void
    {
        $vars = [...getenv(), ...self::env(['WORKER_DB_PASSWORD' => '']), 'ACCOUNT_HMAC_KEY' => ''];
        $proc = proc_open([PHP_BINARY, self::basePath() . '/bin/worker.php', 'worker', '--once'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            self::basePath(), $vars);
        self::assertIsResource($proc);
        stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(2, proc_close($proc));
        self::assertStringContainsString('no arranca', $err);
    }

    public function testWorkerDoesNotClaimJobsInMaintenance(): void
    {
        $settings = self::settings();
        $flag = $settings->storagePath . '/mantenimiento.json';
        $jid = self::enqueue('DIAGNOSTICO', ['eco' => 'mantenimiento']);
        file_put_contents($flag, '{"desde": "x", "motivo": "prueba"}');
        try {
            self::assertSame(0, self::worker($settings, 'phpunit-mant')->run(true));
            self::assertSame('PENDIENTE', self::jobRow($jid)['estado']);
        } finally {
            unlink($flag);
        }
        self::assertSame(1, self::worker($settings, 'phpunit-mant2')->run(true));
        self::assertSame('COMPLETADO', self::jobRow($jid)['estado']);
    }

    public function testUnreadableWebEnvLinkIsIgnored(): void
    {
        $tmp = sys_get_temp_dir() . '/crh-fase9-' . bin2hex(random_bytes(6));
        $secreto = $tmp . '/etc';
        $base = $tmp . '/app';
        mkdir($secreto, 0755, true);
        mkdir($base, 0755, true);
        file_put_contents($secreto . '/web.env', "STORAGE_PATH=/desde/el/env/de/la/web\nDB_HOST=host-de-la-web\n");
        symlink($secreto . '/web.env', $base . '/.env');
        chmod($secreto, 0000);
        try {
            putenv('WORKER_DB_PASSWORD=del-worker');
            putenv('STORAGE_PATH');
            putenv('DB_HOST');
            $s = self::settingsFrom(Env::load($base . '/.env'), $base);
            self::assertSame('del-worker', $s->db['password']);
            self::assertNotSame('/desde/el/env/de/la/web', $s->storagePath, 'el .env ajeno no se lee');
            self::assertNotSame('host-de-la-web', $s->db['host'], 'el .env ajeno no se lee');
            self::assertTrue(is_link($base . '/.env'));
        } finally {
            chmod($secreto, 0755);
            unlink($base . '/.env');
            unlink($secreto . '/web.env');
            rmdir($secreto);
            rmdir($base);
            rmdir($tmp);
        }
    }
}
