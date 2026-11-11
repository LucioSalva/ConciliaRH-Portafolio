<?php

declare(strict_types=1);

/**
 * Arranque de PHPUnit.
 * - Nunca usa la base principal: exige DB_TEST_DATABASE distinta de DB_DATABASE.
 * - Aplica migraciones pendientes en la base de pruebas con el rol propietario.
 * - Las pruebas de integración se ejecutan como conciliarh_web (privilegios reales)
 *   dentro de una transacción que se revierte al final de cada prueba.
 */

use ConciliaRH\Core\Database;
use ConciliaRH\Core\Env;
use ConciliaRH\Core\Migrator;

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('America/Mexico_City');

$env = Env::load(dirname(__DIR__) . '/.env');
$testDb = $env->string('DB_TEST_DATABASE', 'conciliarh_test');
if (!str_ends_with($testDb, '_test') || $testDb === $env->string('DB_DATABASE', 'conciliarh')) {
    fwrite(STDERR, "Por seguridad DB_TEST_DATABASE debe terminar en _test y ser distinta de DB_DATABASE.\n");
    exit(1);
}

define('CONCILIARH_TEST_DB', $testDb);
define('CONCILIARH_DB_AVAILABLE', (static function () use ($env, $testDb): bool {
    try {
        $owner = new Database([
            'host' => $env->string('DB_HOST', '127.0.0.1'),
            'port' => $env->int('DB_PORT', 5432),
            'database' => $testDb,
            'username' => $env->string('DB_MIGRATE_USERNAME', 'conciliarh_owner'),
            'password' => $env->string('DB_MIGRATE_PASSWORD'),
            'application_name' => 'conciliarh_tests',
        ]);
        (new Migrator($owner, dirname(__DIR__) . '/database/migrations', false))->up();
        // Limpieza defensiva de usuarios GOD de pruebas que una ejecución interrumpida pudo dejar
        $owner->execute("DELETE FROM usuario_rol WHERE usuario_id IN (SELECT id FROM usuarios WHERE username LIKE 'god.%' AND NOT EXISTS (SELECT 1 FROM auditoria a WHERE a.usuario_id = usuarios.id))");
        $owner->execute("DELETE FROM usuarios WHERE username LIKE 'god.%' AND NOT EXISTS (SELECT 1 FROM auditoria a WHERE a.usuario_id = usuarios.id)");
        return true;
    } catch (Throwable $e) {
        fwrite(STDERR, '[tests] Base de pruebas no disponible: ' . $e->getMessage() . PHP_EOL);
        return false;
    }
})());
