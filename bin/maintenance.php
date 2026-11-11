<?php

declare(strict_types=1);

/**
 * Modo mantenimiento (docs/OPERATIONS_RUNBOOK.md §6). SÓLO CLI.
 *
 *   php bin/maintenance.php status
 *   php bin/maintenance.php on --motivo="Actualización a 0.1.0-rc.1"
 *   php bin/maintenance.php off
 *
 * Con el modo activo la web responde 503 (salvo /api/health), el scheduler no ejecuta y el worker no toma trabajos nuevos.
 * Código de salida de «status»: 0 = operando, 3 = en mantenimiento (útil en scripts de despliegue).
 */

use ConciliaRH\Core\Config;
use ConciliaRH\Core\Maintenance;

$container = (require __DIR__ . '/bootstrap.php')();
$storage = (string) $container->get(Config::class)->get('storage.path');
// (getopt() de PHP deja de leer opciones en el primer argumento posicional: «on --motivo=…» se analiza a mano)
$cmd = 'status';
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--motivo=(.*)$/s', $arg, $m)) {
        $opts['motivo'] = $m[1];
    } elseif (!str_starts_with($arg, '--')) {
        $cmd = $arg;
    }
}
try {
    switch ($cmd) {
        case 'on':
            $motivo = trim((string) ($opts['motivo'] ?? ''));
            if (mb_strlen($motivo) < 5) {
                fwrite(STDERR, "Indique --motivo=\"…\" (mínimo 5 caracteres).\n");
                exit(1);
            }
            Maintenance::enable($storage, $motivo);
            fwrite(STDOUT, "Modo mantenimiento ACTIVO. La web responde 503; detenga el worker y el scheduler si va a migrar o restaurar.\n");
            exit(0);
        case 'off':
            Maintenance::disable($storage);
            fwrite(STDOUT, "Modo mantenimiento desactivado.\n");
            exit(0);
        case 'status':
            $s = Maintenance::status($storage);
            fwrite(STDOUT, $s === null ? "Operando (sin mantenimiento).\n" : "EN MANTENIMIENTO desde {$s['desde']}: {$s['motivo']}\n");
            exit($s === null ? 0 : 3);
        default:
            fwrite(STDERR, "Uso: php bin/maintenance.php status|on --motivo=\"…\"|off\n");
            exit(1);
    }
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
