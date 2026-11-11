<?php

declare(strict_types=1);

/**
 * Uso:
 *   php bin/migrate.php up                 Aplica migraciones pendientes
 *   php bin/migrate.php status             Muestra estado
 *   php bin/migrate.php down [--steps=N]   Revierte (sólo desarrollo/pruebas)
 *   Opción --database=NOMBRE para operar sobre otra BD (p. ej. la de pruebas).
 * Conecta con el rol propietario (DB_MIGRATE_USERNAME / DB_MIGRATE_PASSWORD).
 */

use ConciliaRH\Core\Config;
use ConciliaRH\Core\Database;
use ConciliaRH\Core\Migrator;

$container = (require __DIR__ . '/bootstrap.php')();
$config = $container->get(Config::class);

// Análisis explícito: getopt() de PHP deja de leer opciones tras el primer argumento posicional.
$cmd = 'status';
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(steps|database)=(.*)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    } elseif (!str_starts_with($arg, '--')) {
        $cmd = $arg;
    } else {
        fwrite(STDERR, "Opción desconocida: $arg\n");
        exit(2);
    }
}
$database = isset($opts['database']) && is_string($opts['database']) ? $opts['database'] : (string) $config->get('db.database');
if (!preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $database)) {
    fwrite(STDERR, "Nombre de base de datos inválido.\n");
    exit(2);
}

$db = new Database([
    ...$config->get('db'),
    'database' => $database,
    'username' => $config->get('db_migrate.username'),
    'password' => $config->get('db_migrate.password'),
    'application_name' => 'conciliarh_migrate',
], (string) $config->get('app.timezone'));

$migrator = new Migrator($db, dirname(__DIR__) . '/database/migrations', $config->isProduction());
$print = static fn (string $l) => fwrite(STDOUT, $l . PHP_EOL);

try {
    switch ($cmd) {
        case 'up':
            $print("Aplicando migraciones en «{$database}»…");
            $done = $migrator->up($print);
            $print($done === [] ? '  Nada pendiente.' : sprintf('  %d migración(es) aplicada(s).', count($done)));
            break;
        case 'down':
            $steps = isset($opts['steps']) && ctype_digit((string) $opts['steps']) ? (int) $opts['steps'] : 1;
            $migrator->down($steps, $print);
            break;
        case 'status':
            $applied = $migrator->applied();
            foreach ($migrator->available() as $v => $m) {
                $print(sprintf('  [%s] %s_%s', isset($applied[$v]) ? 'x' : ' ', $v, $m['name']));
            }
            break;
        default:
            $print('Comando desconocido. Use: up | status | down');
            exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
