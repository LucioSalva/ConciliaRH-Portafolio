<?php

declare(strict_types=1);

/**
 * Creación segura del usuario GOD (único usuario de máximo nivel).
 *
 * Interactivo:      php bin/create-god.php
 * No interactivo:   printf '%s\n' "$PASS" | php bin/create-god.php --username=jdoe --nombre="Juan Pérez" --password-stdin
 *
 * - Solicita USUARIO, NOMBRE y CONTRASEÑA (no pide correo).
 * - La contraseña nunca se acepta como argumento (quedaría en el historial y en `ps`).
 * - En modo interactivo el eco se oculta con `stty -echo`, invocado SIN shell y con ruta absoluta (bin/_cli.php).
 * - Se niega a crear un segundo GOD activo. La acción queda auditada con origen CLI.
 * - Se conecta con el rol PROPIETARIO del esquema (DB_MIGRATE_USERNAME/PASSWORD): la base de datos
 *   sólo permite asignar el rol GOD a ese rol (trigger usuario_rol_god_protegido), nunca al rol web.
 */

use ConciliaRH\Core\Config;
use ConciliaRH\Core\Database;
use ConciliaRH\Domain\Settings\SettingsService;
use ConciliaRH\Domain\Users\GodProvisioner;
use ConciliaRH\Domain\ValidationException;

$container = (require __DIR__ . '/bootstrap.php')();
require __DIR__ . '/_cli.php';
$config = $container->get(Config::class);
if ((string) $config->get('db_migrate.password') === '') {
    fwrite(STDERR, "Defina DB_MIGRATE_USERNAME y DB_MIGRATE_PASSWORD (rol propietario) sólo para este comando.\n");
    exit(1);
}
$container->instance(Database::class, new Database([
    ...$config->get('db'),
    'username' => $config->get('db_migrate.username'),
    'password' => $config->get('db_migrate.password'),
    'application_name' => 'conciliarh_setup',
], (string) $config->get('app.timezone')));

$opts = getopt('', ['username:', 'nombre:', 'password-stdin']);
$interactive = !isset($opts['password-stdin']);

fwrite(STDOUT, "== ConciliaRH · Creación del usuario GOD ==\n");

$username = is_string($opts['username'] ?? null) ? $opts['username'] : cli_prompt('Usuario: ');
$nombre = is_string($opts['nombre'] ?? null) ? $opts['nombre'] : cli_prompt('Nombre completo: ');

if ($interactive) {
    if (!stream_isatty(STDIN)) {
        fwrite(STDERR, "Sin terminal: use --password-stdin (la contraseña nunca se pasa como argumento).\n");
        exit(1);
    }
    try {
        $password = cli_prompt_hidden('Contraseña: ');
        $confirm = cli_prompt_hidden('Confirme la contraseña: ');
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    if (!hash_equals($password, $confirm)) {
        fwrite(STDERR, "Las contraseñas no coinciden.\n");
        exit(1);
    }
} else {
    $line = fgets(STDIN);
    $password = $line === false ? '' : rtrim($line, "\r\n");
}

$minLength = max(12, $container->get(SettingsService::class)->int('seguridad.password_longitud_minima', 12));
$osUser = cli_os_user();

try {
    $id = $container->get(GodProvisioner::class)->create($username, $nombre, $password, $minLength, $osUser);
} catch (ValidationException $e) {
    foreach ($e->errors() as $field => $msg) {
        fwrite(STDERR, "  - $field: $msg\n");
    }
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Usuario GOD creado (id $id). La acción quedó registrada en la auditoría.\n");
