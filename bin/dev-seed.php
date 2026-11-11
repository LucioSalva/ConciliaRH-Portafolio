<?php

declare(strict_types=1);

/**
 * Siembra de DATOS DE DESARROLLO (nunca en producción).
 *
 * Crea o reinicia las cuentas dev.god, dev.admin y dev.consulta:
 *  - marcadas con usuarios.es_desarrollo = true (la aplicación les NIEGA el acceso en cualquier APP_ENV distinto de development);
 *  - nombre con prefijo [DEV];
 *  - contraseñas aleatorias nuevas en cada ejecución, escritas SÓLO en storage/dev-credenciales.txt (0600,
 *    ignorado por Git) para que `make smoke` pueda usarlas.
 *
 * Protecciones: exige APP_ENV=development y credenciales del propietario, y se niega a crear dev.god si
 * existe un GOD activo que no sea de desarrollo (en ese caso `make smoke` no tendrá contraseña y fallará).
 * Se conecta con el rol propietario (asignar el rol GOD sólo es posible con él).
 */

use ConciliaRH\Core\Config;
use ConciliaRH\Core\Database;
use ConciliaRH\Domain\Auth\PasswordHasher;

$container = (require __DIR__ . '/bootstrap.php')();
$config = $container->get(Config::class);

if ($config->get('app.env') !== 'development') {
    fwrite(STDERR, "RECHAZADO: dev-seed sólo se ejecuta con APP_ENV=development.\n");
    exit(1);
}
if ((string) $config->get('db_migrate.password') === '') {
    fwrite(STDERR, "Requiere DB_MIGRATE_USERNAME/DB_MIGRATE_PASSWORD (use make seed-dev).\n");
    exit(1);
}
$db = new Database([
    ...$config->get('db'),
    'username' => $config->get('db_migrate.username'),
    'password' => $config->get('db_migrate.password'),
    'application_name' => 'conciliarh_dev_seed',
], (string) $config->get('app.timezone'));

$realGod = (int) $db->fetchValue(
    "SELECT count(*) FROM usuarios u JOIN usuario_rol ur ON ur.usuario_id = u.id JOIN roles r ON r.id = ur.rol_id
      WHERE r.codigo = 'GOD' AND u.activo AND u.deleted_at IS NULL AND NOT u.es_desarrollo"
);
$accounts = ['dev.god' => 'GOD', 'dev.admin' => 'ADMIN', 'dev.consulta' => 'CONSULTA'];
$hasher = new PasswordHasher();
$lines = ["# ConciliaRH · credenciales de DESARROLLO (generadas " . date('c') . "). No usar en producción."];

$db->transaction(function () use ($db, $accounts, $hasher, $realGod, &$lines): void {
    foreach ($accounts as $username => $role) {
        if ($role === 'GOD' && $realGod > 0) {
            fwrite(STDOUT, "  · dev.god omitido: existe un GOD real activo en esta base.\n");
            continue;
        }
        $password = 'Dev-' . bin2hex(random_bytes(9));
        $id = $db->fetchValue('SELECT id FROM usuarios WHERE username = :u', ['u' => $username]);
        if ($id === null) {
            $id = $db->fetchValue(
                'INSERT INTO usuarios (username, nombre, password_hash, es_desarrollo, password_cambiado_at)
                 VALUES (:u, :n, :h, true, now()) RETURNING id',
                ['u' => $username, 'n' => '[DEV] ' . ucfirst(strtolower($role)), 'h' => $hasher->hash($password)]
            );
        } else {
            $isDev = (bool) $db->fetchValue('SELECT es_desarrollo FROM usuarios WHERE id = :id', ['id' => $id]);
            if (!$isDev) {
                throw new RuntimeException("La cuenta $username existe y NO es de desarrollo; no se modifica.");
            }
            $db->execute(
                'UPDATE usuarios SET password_hash = :h, activo = true, deleted_at = NULL, deleted_by = NULL,
                        debe_cambiar_password = false WHERE id = :id',
                ['h' => $hasher->hash($password), 'id' => $id]
            );
        }
        $db->execute(
            'INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = :r ON CONFLICT DO NOTHING',
            ['u' => $id, 'r' => $role]
        );
        $lines[] = "$username=$password";
        fwrite(STDOUT, "  ✔ $username ($role)\n");
    }
    $db->execute(
        "INSERT INTO auditoria (origen, accion, entidad, valor_nuevo, contexto, hash)
         VALUES ('CLI', 'desarrollo.sembrar', 'usuarios', CAST(:v AS jsonb), CAST(:c AS jsonb), repeat('0', 64))",
        ['v' => json_encode(['cuentas' => array_keys($accounts)]), 'c' => json_encode(['entorno' => 'development'])]
    );
});

$file = dirname(__DIR__) . '/storage/dev-credenciales.txt';
$old = umask(077);
file_put_contents($file, implode(PHP_EOL, $lines) . PHP_EOL);
umask($old);
chmod($file, 0600);
fwrite(STDOUT, "Credenciales de desarrollo en storage/dev-credenciales.txt (0600, ignorado por Git).\n");
