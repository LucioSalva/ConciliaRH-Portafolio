<?php

declare(strict_types=1);

namespace ConciliaRH\Core;

use ConciliaRH\Core\Session\DatabaseSessionHandler;
use ConciliaRH\Core\Session\NativeSessionStore;
use ConciliaRH\Core\Session\Session;
use ConciliaRH\Domain\Audit\AuditContext;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\AuthService;
use ConciliaRH\Domain\Auth\LoginThrottle;
use ConciliaRH\Domain\Auth\PasswordHasher;
use ConciliaRH\Domain\Imports\ImportStorage;
use ConciliaRH\Domain\Imports\NativeUploadMover;
use ConciliaRH\Domain\Imports\UploadMover;
use ConciliaRH\Domain\Settings\SettingsService;

/** Composición de dependencias (composition root). */
final class App
{
    public static function container(string $basePath, ?Env $env = null, ?Request $request = null): Container
    {
        $env ??= Env::load($basePath . '/.env');
        /** @var callable(Env,string):array<string,mixed> $configFactory */
        $configFactory = require $basePath . '/config/app.php';
        $config = new Config($configFactory($env, $basePath));

        date_default_timezone_set((string) $config->get('app.timezone'));
        mb_internal_encoding('UTF-8');

        $c = new Container();
        $c->instance(Env::class, $env);
        $c->instance(Config::class, $config);
        $c->set(Database::class, static fn () => new Database($config->get('db'), (string) $config->get('app.timezone')));
        $c->set(Logger::class, static fn () => new Logger((string) $config->get('storage.logs')));
        $c->set(View::class, static function () use ($basePath, $config): View {
            $view = new View($basePath . '/app/Views', (string) $config->get('app.version'));
            $view->share('appName', $config->get('app.name'));
            $view->share('appFullName', $config->get('app.full_name'));
            $view->share('institution', $config->get('app.institution'));
            $view->share('appVersion', $config->get('app.version'));
            $view->share('appEnv', $config->get('app.env'));
            return $view;
        });
        $c->set(Router::class, static function () use ($basePath): Router {
            $router = new Router();
            (require $basePath . '/config/routes.php')($router);
            return $router;
        });
        $c->set(Session::class, static function (Container $c) use ($config, $request): Session {
            $handler = new DatabaseSessionHandler(
                $c->get(Database::class),
                (int) $config->get('session.idle_minutes', 30),
                $request?->ip(),
                $request?->userAgent() ?? '',
            );
            return new Session(new NativeSessionStore(
                $handler,
                (string) $config->get('session.name'),
                (bool) $config->get('session.secure'),
                (int) $config->get('session.idle_minutes', 30),
            ));
        });
        $c->set(LoginThrottle::class, static fn (Container $c) => new LoginThrottle(
            $c->get(Database::class),
            $c->get(SettingsService::class),
            // Llave propia del bloqueo de login, derivada de APP_KEY: independiente de la llave HMAC de cuentas (que rota)
            hash_hmac('sha256', 'conciliarh:login-throttle:v1', (string) $config->get('app.key')),
        ));
        $c->set(ImportStorage::class, static fn () => new ImportStorage((string) $config->get('storage.path')));
        // Reportes: archivos temporales fuera de public/ (storage/trabajo/reportes), borrados al transmitirse
        $c->set(\ConciliaRH\Domain\Reports\ReportRenderer::class, static fn (Container $c) => new \ConciliaRH\Domain\Reports\ReportRenderer(
            $c->get(View::class), rtrim((string) $config->get('storage.path'), '/') . '/trabajo/reportes', $basePath));
        $c->set(\ConciliaRH\Domain\Reports\ScheduledReportService::class, static fn (Container $c) => new \ConciliaRH\Domain\Reports\ScheduledReportService(
            $c->get(Database::class), $c->get(AuditLogger::class), $c->get(SettingsService::class), (string) $config->get('storage.path'),
            (string) $config->get('app.timezone'), $c->get(\ConciliaRH\Domain\Reports\ReportCipher::class)));
        $c->set(\ConciliaRH\Domain\Reports\ReportCipher::class, static fn () => \ConciliaRH\Domain\Reports\ReportCipher::fromConfig(
            (string) $config->get('app.report_enc_keys'), (string) $config->get('app.report_enc_key_id'))
            ?? throw new \RuntimeException('REPORT_ENC_KEYS inválido.'));
        $c->set(\ConciliaRH\Domain\System\HealthService::class, static fn (Container $c) => new \ConciliaRH\Domain\System\HealthService(
            $c->get(Database::class), $c->get(AuditLogger::class), $c->get(\ConciliaRH\Domain\Imports\StorageAuditor::class),
            (string) $config->get('storage.path'), $basePath, (string) $config->get('app.version'), (int) (getenv('WORKER_HEARTBEAT_TIMEOUT') ?: 120)));
        $c->set(UploadMover::class, static fn () => new NativeUploadMover());
        $c->set(Maintenance::class, static fn () => new Maintenance((string) $config->get('storage.path')));
        \ConciliaRH\Domain\Conciliation\Exporter::useWorkDir(rtrim((string) $config->get('storage.path'), '/') . '/trabajo/reportes');
        $c->set(AuthService::class, static fn (Container $c) => new AuthService(
            $c->get(Database::class), $c->get(PasswordHasher::class), $c->get(LoginThrottle::class), $c->get(Session::class),
            $c->get(AuditLogger::class), $c->get(AuditContext::class), $c->get(Logger::class), $config->get('app.env') !== 'development',
        ));
        return $c;
    }
}
