<?php

declare(strict_types=1);

use ConciliaRH\Core\Config;
use ConciliaRH\Core\Database;
use ConciliaRH\Core\View;
use ConciliaRH\Domain\Audit\AuditContext;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Conciliation\ConciliationComparison;
use ConciliaRH\Domain\Conciliation\ConciliationService;
use ConciliaRH\Domain\Reports\ReportCipher;
use ConciliaRH\Domain\Reports\ReportJobRunner;
use ConciliaRH\Domain\Reports\ReportRenderer;
use ConciliaRH\Domain\Reports\ReportService;
use ConciliaRH\Domain\Settings\SettingsService;

$container = (require __DIR__ . '/bootstrap.php')();
$config = $container->get(Config::class);
$opts = getopt('', ['run', 'solo-ejecutar', 'max-jobs:']);
try {
    $config->assertScheduler();   // FASE 9: sin anillo de reportes, rol o storage válidos no arranca
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
$storage = (string) $config->get('storage.path');
if (($m = \ConciliaRH\Core\Maintenance::status($storage)) !== null) {
    fwrite(STDOUT, "En mantenimiento desde {$m['desde']}: no se ejecuta.\n");
    exit(0);
}
$tz = (string) $config->get('app.timezone');
$db = new Database([...$config->get('db'), 'username' => $config->get('scheduler.username'), 'password' => $config->get('scheduler.password'),
    'application_name' => 'conciliarh_scheduler'], $tz);
$ctx = new AuditContext();
$ctx->origin = 'WORKER';
$ctx->setActor(null, 'scheduler', null);
$audit = new AuditLogger($db, $ctx);
$settings = new SettingsService($db, $audit);
$reports = new ReportService($db, new ConciliationService($db, $audit), new ConciliationComparison($db), $settings);
$runner = new ReportJobRunner($db, $reports, new ReportRenderer($container->get(View::class), rtrim($storage, '/') . '/trabajo/reportes', dirname(__DIR__)),
    $audit, $settings, $storage, 'php-reportes-' . preg_replace('/[^A-Za-z0-9._-]/', '_', gethostname() ?: 'host') . '-' . getmypid(), $tz,
    $config->get('app.env') === 'development',
    ReportCipher::fromConfig((string) $config->get('app.report_enc_keys'), (string) $config->get('app.report_enc_key_id')));

$inicio = date(DATE_ATOM);
try {
    $out = [];
    $out['mantenimiento'] = ['solicitudes_expiradas' => (int) $db->fetchValue('SELECT fn_solicitudes_fusion_expirar()'),
                             'notificaciones_purgadas' => (int) $db->fetchValue('SELECT fn_mantenimiento_notificaciones()')];
    if (!isset($opts['solo-ejecutar'])) {
        $out['scheduler'] = $runner->schedule();
    }
    if (isset($opts['run']) || isset($opts['solo-ejecutar'])) {
        $out['ejecucion'] = $runner->runPending(max(1, min(1000, (int) ($opts['max-jobs'] ?? 50))));
    }
    $db->fetchValue("SELECT fn_sistema_ejecucion('SCHEDULER', 'CLI', CAST(:i AS timestamptz), 'OK', CAST(:d AS jsonb))",
        ['i' => $inicio, 'd' => json_encode($out)]);
    fwrite(STDOUT, json_encode($out, JSON_UNESCAPED_UNICODE) . "\n");
    exit(0);
} catch (Throwable $e) {
    $msg = $e instanceof PDOException ? 'SQLSTATE ' . ($e->errorInfo[0] ?? '?') : $e::class;
    try {
        $db->fetchValue("SELECT fn_sistema_ejecucion('SCHEDULER', 'CLI', CAST(:i AS timestamptz), 'ERROR', CAST(:d AS jsonb))",
            ['i' => $inicio, 'd' => json_encode(['error' => $msg])]);
    } catch (Throwable) {
    }
    fwrite(STDERR, "Scheduler detenido: $msg\n");
    exit(3);
}
