<?php

declare(strict_types=1);

use ConciliaRH\Core\Config;
use ConciliaRH\Core\Logger;
use ConciliaRH\Engine\Jobs\JobRegistry;
use ConciliaRH\Engine\Worker\Worker;
use ConciliaRH\Engine\Worker\WorkerSettings;

$container = (require __DIR__ . '/bootstrap.php')();
$config = $container->get(Config::class);
$argv = $_SERVER['argv'] ?? [];
$cmd = $argv[1] ?? '';
$usage = "Uso: php bin/worker.php worker [--once] | run-job ID | reap | version\n";
if (!in_array($cmd, ['worker', 'run-job', 'reap', 'version'], true)) {
    fwrite(STDERR, $usage);
    exit(2);
}
$settings = WorkerSettings::fromConfig($config);
if ($cmd === 'version') {
    fwrite(STDOUT, 'conciliarh ' . $settings->version . " (contrato v1)\n");
    exit(0);
}
if ($cmd === 'worker' || $cmd === 'run-job') {
    $problems = $settings->problems();
    if ($problems !== []) {
        fwrite(STDERR, 'Configuración incompleta o inválida; el procesador no arranca: ' . implode('; ', $problems) . "\n");
        exit(2);
    }
}
ini_set('memory_limit', (string) (getenv('WORKER_MEMORY_LIMIT') ?: '1536M'));
$worker = new Worker($settings, JobRegistry::handlers(), $container->get(Logger::class));
if ($cmd === 'reap') {
    fwrite(STDOUT, 'Trabajos recuperados: ' . $worker->reapOnce() . "\n");
    exit(0);
}
if ($cmd === 'run-job') {
    $id = $argv[2] ?? '';
    if (preg_match('/^[1-9]\d{0,17}$/', $id) !== 1) {
        fwrite(STDERR, "run-job requiere un ID entero positivo.\n");
        exit(2);
    }
    if ($worker->run(true, (int) $id) === 0) {
        fwrite(STDERR, "El trabajo no está disponible (no existe, no está PENDIENTE o no es de un tipo admitido).\n");
        exit(1);
    }
    exit(0);
}
$worker->installSignalHandlers();
$worker->run(in_array('--once', $argv, true));
exit(0);
