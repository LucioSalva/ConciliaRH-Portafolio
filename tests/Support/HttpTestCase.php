<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Support;

use ConciliaRH\Core\App;
use ConciliaRH\Core\Container;
use ConciliaRH\Core\Database;
use ConciliaRH\Core\Kernel;
use ConciliaRH\Core\Logger;
use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Core\Session\ArraySessionStore;
use ConciliaRH\Core\Session\Session;
use ConciliaRH\Domain\Imports\ImportStorage;
use ConciliaRH\Domain\Imports\UploadMover;

/**
 * Pruebas HTTP en proceso: pila completa (router, CSRF, middleware, controladores, vistas)
 * con la BD de pruebas y una sesión en memoria que persiste entre peticiones de la misma prueba.
 */
abstract class HttpTestCase extends DatabaseTestCase
{
    protected ArraySessionStore $store;
    private ?Session $session = null;
    protected string $tmpDir;
    protected string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new ArraySessionStore();
        $this->session = new Session($this->store);
        $this->tmpDir = ImportFixtures::tmpDir();
        $this->storageRoot = ImportFixtures::tmpDir();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        ImportFixtures::rmTree($this->tmpDir);
        ImportFixtures::rmTree($this->storageRoot);
    }

    private function container(): Container
    {
        $c = App::container(dirname(__DIR__, 2));
        $c->instance(Database::class, self::web());
        $c->instance(Session::class, $this->session);
        $c->instance(Logger::class, new Logger(sys_get_temp_dir()));
        $c->instance(ImportStorage::class, new ImportStorage($this->storageRoot));
        $c->instance(\ConciliaRH\Core\Maintenance::class, new \ConciliaRH\Core\Maintenance($this->storageRoot));
        $c->instance(UploadMover::class, new TestUploadMover($this->tmpDir));
        // Reportes generados (FASE 7) en el almacenamiento temporal de la prueba
        $c->set(\ConciliaRH\Domain\Reports\ScheduledReportService::class, fn (Container $c) => new \ConciliaRH\Domain\Reports\ScheduledReportService(
            self::web(), $c->get(\ConciliaRH\Domain\Audit\AuditLogger::class), $c->get(\ConciliaRH\Domain\Settings\SettingsService::class),
            $this->storageRoot, 'America/Mexico_City', self::reportCipher()));
        $c->instance(\ConciliaRH\Domain\Reports\ReportCipher::class, self::reportCipher());
        $c->set(\ConciliaRH\Domain\System\HealthService::class, fn (Container $c) => new \ConciliaRH\Domain\System\HealthService(
            self::web(), $c->get(\ConciliaRH\Domain\Audit\AuditLogger::class), $c->get(\ConciliaRH\Domain\Imports\StorageAuditor::class),
            $this->storageRoot, dirname(__DIR__, 2), '0.0.0-pruebas', 120));
        return $c;
    }

    /** Llave de reportes de las pruebas (ficticia, distinta de las de cuentas) o la del entorno si existe. */
    public static function reportCipher(): \ConciliaRH\Domain\Reports\ReportCipher
    {
        $ring = getenv('REPORT_ENC_KEYS') ?: 'r1:' . hash('sha256', 'conciliarh-pruebas-reportes');
        return \ConciliaRH\Domain\Reports\ReportCipher::fromConfig($ring, getenv('REPORT_ENC_KEY_ID') ?: 'r1')
            ?? throw new \RuntimeException('REPORT_ENC_KEYS de pruebas inválido');
    }

    /**
     * @param array<string,mixed> $body @param array<string,string> $headers
     * @param array<string,mixed> $files entradas con la forma de $_FILES
     * @param array<string,mixed> $server
     */
    protected function request(string $method, string $path, array $body = [], array $headers = [], bool $withToken = true,
                               array $files = [], array $server = []): Response
    {
        if ($withToken && $method !== 'GET' && !array_key_exists('_token', $body)) {
            $body['_token'] = $this->session->csrfToken();
        }
        $parts = parse_url($path);
        parse_str($parts['query'] ?? '', $query);
        $request = new Request($method, Request::normalizePath($parts['path'] ?? '/'), $query, $body,
            ['REMOTE_ADDR' => '192.0.2.99', 'HTTP_USER_AGENT' => 'phpunit', ...$server], array_change_key_case($headers), $files);
        return (new Kernel($this->container()))->handle($request);
    }

    protected function get(string $path, array $headers = []): Response
    {
        return $this->request('GET', $path, [], $headers);
    }

    protected function post(string $path, array $body = [], array $headers = []): Response
    {
        return $this->request('POST', $path, $body, $headers);
    }

    /** Entrada $_FILES para un fixture copiado al temporal de la prueba. @return array<string,mixed> */
    protected function fileEntry(string $fixture, ?string $clientName = null): array
    {
        $f = ImportFixtures::upload($fixture, $this->tmpDir, $clientName);
        return ['name' => $f->clientName, 'type' => 'application/octet-stream', 'tmp_name' => $f->tmpPath, 'error' => UPLOAD_ERR_OK, 'size' => filesize($f->tmpPath)];
    }

    protected function loginAs(string $username): void
    {
        $r = $this->post('/login', ['username' => $username, 'password' => self::PASSWORD]);
        self::assertSame('/', $r->header('Location'), 'El login de prueba falló para ' . $username);
    }

    protected static function assertRedirect(Response $r, string $to): void
    {
        self::assertSame(303, $r->status());
        self::assertSame($to, $r->header('Location'));
    }
}
