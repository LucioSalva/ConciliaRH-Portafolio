<?php

declare(strict_types=1);

use ConciliaRH\Core\BuildInfo;
use ConciliaRH\Core\Config;
use ConciliaRH\Core\Database;
use ConciliaRH\Core\Maintenance;
use ConciliaRH\Domain\System\HealthService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$opts = getopt('', ['post-deploy', 'db-owner', 'json']);
$base = dirname(__DIR__);
$res = [];
$add = static function (string $grupo, string $nombre, string $estado, string $detalle = '') use (&$res): void {
    $res[] = ['grupo' => $grupo, 'chequeo' => $nombre, 'estado' => $estado, 'detalle' => $detalle];
};

$add('php', 'Versión ≥ 8.3', version_compare(PHP_VERSION, '8.3.0', '>=') ? 'OK' : 'ERROR', PHP_VERSION);
if (!is_file($base . '/vendor/autoload.php')) {
    $add('php', 'Dependencias (vendor/)', 'ERROR', 'Falta vendor/autoload.php: el paquete está incompleto.');
    goto salida;
}
require $base . '/vendor/autoload.php';
foreach (HealthService::EXTENSIONES as $ext) {
    $add('php', "Extensión $ext", extension_loaded($ext) ? 'OK' : 'ERROR');
}

try {
    $container = (static fn () => \ConciliaRH\Core\App::container($base))();
    $config = $container->get(Config::class);
} catch (Throwable $e) {
    $add('config', 'Carga de configuración', 'ERROR', $e::class);
    goto salida;
}
$prod = $config->isProduction();
$add('config', 'APP_ENV', in_array($config->get('app.env'), ['production', 'testing', 'development'], true) ? 'OK' : 'ERROR', (string) $config->get('app.env'));
if (!$prod) {
    $add('config', 'Entorno de producción', 'ADVERTENCIA', 'APP_ENV no es production (correcto sólo fuera de producción).');
}
try {
    $config->assertSecure();
    $add('config', 'Configuración de la web (llaves, separación, APP_DEBUG, sesión)', 'OK');
} catch (RuntimeException $e) {
    $add('config', 'Configuración de la web', 'ERROR', $e->getMessage());   // el mensaje nunca contiene valores
}
if ($prod && !str_starts_with((string) $config->get('app.url'), 'https://')) {
    $add('config', 'APP_URL con https://', 'ERROR', 'En producción la aplicación se sirve sólo por HTTPS.');
}
if ((string) $config->get('scheduler.password') !== '') {
    try {
        $config->assertScheduler();
        $add('config', 'Configuración del scheduler', 'OK');
    } catch (RuntimeException $e) {
        $add('config', 'Configuración del scheduler', 'ERROR', $e->getMessage());
    }
}
if ((string) $config->get('db_migrate.password') !== '' && !isset($opts['db-owner'])) {
    $add('config', 'DB_MIGRATE_* en el entorno', $prod ? 'ADVERTENCIA' : 'OK',
        'Las credenciales del propietario sólo deben estar en cli.env, no en el entorno permanente de la web.');
}
$envFile = $base . '/.env';
if (is_link($envFile) || file_exists($envFile)) {
    $add('config', 'Archivo .env de la web legible por ' . (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : 'este usuario'),
        @is_readable($envFile) ? 'OK' : 'ERROR',
        @is_readable($envFile) ? '' : 'PHP-FPM no podrá leerlo: revise el modo de /etc/conciliarh (0711) y de web.env (0640 root:conciliarh-web).');
}
$b = BuildInfo::read($base, (string) $config->get('app.version'));
$add('config', 'Versión y build', $b['origen'] === 'paquete' ? 'OK' : ($prod ? 'ADVERTENCIA' : 'OK'),
    $b['version'] . ($b['commit'] ? ' · ' . substr($b['commit'], 0, 12) . ' · ' . $b['built_at'] : ' · sin BUILD.json (desarrollo)'));

$bytes = static function (string $v): int {
    $n = (int) $v;
    return match (strtolower(substr(trim($v), -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
};
$max = (int) $config->get('upload.max_size', 26214400);
$umax = $bytes((string) ini_get('upload_max_filesize'));
$pmax = $bytes((string) ini_get('post_max_size'));
$add('php.ini', 'upload_max_filesize > MAX_UPLOAD_SIZE y post_max_size > upload_max_filesize', $umax > $max && $pmax > $umax ? 'OK' : 'ADVERTENCIA',
    sprintf('MAX_UPLOAD_SIZE %d · upload_max_filesize %s · post_max_size %s (verifique también el php.ini de PHP-FPM)', $max, ini_get('upload_max_filesize'), ini_get('post_max_size')));
if ($prod) {
    $add('php.ini', 'display_errors desactivado', in_array(strtolower((string) ini_get('display_errors')), ['', '0', 'off', 'stderr'], true) ? 'OK' : 'ADVERTENCIA');
}

$st = rtrim((string) $config->get('storage.path'), '/');
$add('storage', 'STORAGE_PATH existe', is_dir($st) ? 'OK' : 'ERROR', $st);
if (is_dir($st)) {
    if ($prod && str_starts_with(realpath($st) ?: $st, realpath($base . '/public') ?: "\0")) {
        $add('storage', 'Fuera del web root', 'ERROR', 'STORAGE_PATH no puede estar dentro de public/.');
    }
    $perm = static fn (string $p): int => (int) (@fileperms($p) & 0777);
    foreach (['imports' => true, 'imports/.staging' => true, 'trabajo' => true, 'trabajo/reportes' => true, 'logs' => true, 'reportes/generados' => false] as $dir => $escribe) {
        $p = $st . '/' . $dir;
        if (!is_dir($p)) {
            $add('storage', "$dir/", 'ERROR', 'No existe: ejecute deploy/setup/preparar-sistema.sh.');
            continue;
        }
        $ok = is_readable($p) && (!$escribe || is_writable($p));
        $otros = $perm($p) & 0007;
        $add('storage', "$dir/" . ($escribe ? ' (lectura y escritura)' : ' (lectura)'),
            !$ok ? (PHP_SAPI === 'cli' && !$prod ? 'ADVERTENCIA' : 'ERROR') : ($otros !== 0 && $dir !== 'trabajo' && $dir !== 'trabajo/reportes' ? 'ADVERTENCIA' : 'OK'),
            sprintf('modo %04o%s', $perm($p), $otros !== 0 ? ' · accesible para «otros»' : ''));
    }
    $mal = 0;
    $n = 0;
    if (is_dir($st . '/imports')) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($st . '/imports', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && str_contains($f->getPathname(), '/original.')) {
                $n++;
                $mal += ($perm($f->getPathname()) & 0222) !== 0 ? 1 : 0;
                if ($n >= 200) {
                    break;
                }
            }
        }
    }
    $add('storage', 'Originales de sólo lectura', $mal === 0 ? 'OK' : 'ERROR', "$n revisado(s), $mal con permiso de escritura");
    $free = @disk_free_space($st);
    $tot = @disk_total_space($st);
    if ($free !== false && $tot) {
        $pct = $free / $tot * 100;
        $add('storage', 'Espacio libre', $pct < 10 ? 'ERROR' : ($pct < 15 ? 'ADVERTENCIA' : 'OK'), sprintf('%.1f %% libre', $pct));
    }
    $m = Maintenance::status($st);
    $add('storage', 'Modo mantenimiento', $m === null ? 'OK' : (isset($opts['post-deploy']) ? 'ERROR' : 'ADVERTENCIA'),
        $m === null ? 'Desactivado' : 'ACTIVO desde ' . $m['desde'] . ' (php bin/maintenance.php off al terminar)');
}

$ro = static function (string $user, string $pass, string $app) use ($config): Database {
    $db = new Database([...$config->get('db'), 'username' => $user, 'password' => $pass, 'application_name' => $app], (string) $config->get('app.timezone'));
    $db->execute('SET default_transaction_read_only = on');   // garantía: nada de lo que siga puede escribir
    return $db;
};
$web = null;
try {
    $web = $ro((string) $config->get('db.username'), (string) $config->get('db.password'), 'conciliarh_preflight');
    $add('bd', 'Conexión con el rol web', 'OK', (string) $config->get('db.username'));
    $v = (int) $web->fetchValue("SELECT current_setting('server_version_num')");
    $add('bd', 'PostgreSQL ≥ 14', $v >= 140000 ? 'OK' : 'ERROR', (string) $web->fetchValue("SELECT current_setting('server_version')"));
    $r = $web->fetchOne('SELECT rolsuper, rolcreaterole, rolcreatedb, rolbypassrls FROM pg_roles WHERE rolname = current_user');
    $add('bd', 'Rol web sin privilegios de administración', ($r && !$r['rolsuper'] && !$r['rolcreaterole'] && !$r['rolcreatedb'] && !$r['rolbypassrls']) ? 'OK' : 'ERROR');
    $add('bd', 'Zona horaria de la sesión', in_array((string) $web->fetchValue('SHOW timezone'), ['America/Mexico_City', 'UTC'], true) ? 'OK' : 'ADVERTENCIA',
        (string) $web->fetchValue('SHOW timezone'));
    $files = array_map(static fn ($f) => substr(basename($f), 0, 4), glob($base . '/database/migrations/*.up.sql') ?: []);
    try {
        $aplicadas = array_column($web->fetchAll('SELECT version FROM schema_migraciones'), 'version');
        $pend = array_values(array_diff($files, $aplicadas));
        $extra = array_values(array_diff($aplicadas, $files));
        $add('bd', 'Migraciones', $extra !== [] ? 'ERROR' : ($pend === [] ? 'OK' : (isset($opts['post-deploy']) ? 'ERROR' : 'ADVERTENCIA')),
            $extra !== [] ? 'La BD tiene migraciones que este paquete no conoce (' . implode(',', $extra) . '): ¿paquete anterior?'
                : ($pend === [] ? count($aplicadas) . ' aplicadas, 0 pendientes' : count($pend) . ' pendiente(s): ' . implode(', ', $pend) . ' → bin/migrate.php up'));
    } catch (Throwable) {
        $add('bd', 'Migraciones', isset($opts['post-deploy']) ? 'ERROR' : 'ADVERTENCIA', 'Esquema vacío: instalación nueva (bin/migrate.php up).');
    }
} catch (Throwable $e) {
    $add('bd', 'Conexión con el rol web', 'ERROR', $e instanceof PDOException ? 'SQLSTATE ' . ($e->errorInfo[0] ?? $e->getCode()) : $e::class);
}
if (isset($opts['db-owner']) && (string) $config->get('db_migrate.password') === '') {
    $add('bd', 'Rol propietario (migraciones)', 'ERROR',
        'Sin DB_MIGRATE_* en este entorno. En producción valídelo con «conciliarh-run migrate status» (usuario conciliarh-admin).');
} elseif (isset($opts['db-owner'])) {
    try {
        $own = $ro((string) $config->get('db_migrate.username'), (string) $config->get('db_migrate.password'), 'conciliarh_preflight_owner');
        $r = $own->fetchOne("SELECT r.rolsuper, n.nspowner = r.oid AS duenio FROM pg_roles r, pg_namespace n WHERE r.rolname = current_user AND n.nspname = 'public'");
        $add('bd', 'Rol propietario (migraciones)', $r && !$r['rolsuper'] && $r['duenio'] ? 'OK' : 'ERROR',
            $r && $r['rolsuper'] ? 'No use un superusuario: cree conciliarh_owner (deploy/postgres/01-roles-y-base.sql).' : ($r && !$r['duenio'] ? 'No es dueño del esquema public.' : ''));
    } catch (Throwable $e) {
        $add('bd', 'Rol propietario (migraciones)', 'ERROR', $e instanceof PDOException ? 'SQLSTATE ' . ($e->errorInfo[0] ?? $e->getCode()) : $e::class);
    }
}
foreach (['worker' => ['worker.username', 'worker.password'], 'scheduler' => ['scheduler.username', 'scheduler.password']] as $rol => [$u, $p]) {
    if ((string) $config->get($p) !== '') {
        try {
            $ro((string) $config->get($u), (string) $config->get($p), 'conciliarh_preflight_' . $rol);
            $add('bd', "Conexión con el rol $rol", 'OK');
        } catch (Throwable $e) {
            $add('bd', "Conexión con el rol $rol", 'ERROR', $e instanceof PDOException ? 'SQLSTATE ' . ($e->errorInfo[0] ?? $e->getCode()) : $e::class);
        }
    }
}

foreach (['ctype', 'filter', 'gd', 'libxml', 'simplexml', 'xml', 'xmlwriter', 'zlib'] as $ext) {
    $add('procesador', "Extensión $ext (lectura de libros de Excel)", extension_loaded($ext) ? 'OK' : 'ERROR');
}
$add('procesador', 'Lector de libros .xls (PhpSpreadsheet)', class_exists(\PhpOffice\PhpSpreadsheet\Reader\Xls::class) ? 'OK' : 'ERROR',
    class_exists(\PhpOffice\PhpSpreadsheet\Reader\Xls::class) ? '' : 'Ejecute composer install --no-dev.');
$add('procesador', 'Extensión pcntl (detención ordenada)', extension_loaded('pcntl') ? 'OK' : 'ADVERTENCIA',
    extension_loaded('pcntl') ? '' : 'Sin pcntl el procesador se detiene sin terminar el trabajo en curso; el trabajo se recupera solo al vencer su latido.');

if (isset($opts['post-deploy']) && $web !== null) {
    $h = new HealthService($web, $container->get(\ConciliaRH\Domain\Audit\AuditLogger::class),
        $container->get(\ConciliaRH\Domain\Imports\StorageAuditor::class), $st, $base, (string) $config->get('app.version'),
        (int) ($config->get('worker.heartbeat_timeout') ?? 120));
    foreach ($h->checks() as $k => $c) {
        $estado = $c['estado'] === 'SIN_DATOS' ? 'ADVERTENCIA' : $c['estado'];
        $add('salud', $k, $estado, (string) ($c['detalle'] ?? ''));
    }
}

salida:
$errores = count(array_filter($res, static fn ($r) => $r['estado'] === 'ERROR'));
$adv = count(array_filter($res, static fn ($r) => $r['estado'] === 'ADVERTENCIA'));
if (isset($opts['json'])) {
    fwrite(STDOUT, json_encode(['errores' => $errores, 'advertencias' => $adv, 'chequeos' => $res], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
} else {
    $marca = ['OK' => '✔', 'ADVERTENCIA' => '!', 'ERROR' => '✘'];
    $g = null;
    foreach ($res as $r) {
        if ($r['grupo'] !== $g) {
            $g = $r['grupo'];
            fwrite(STDOUT, "\n[$g]\n");
        }
        fwrite(STDOUT, sprintf("  %s %-62s %s\n", $marca[$r['estado']], $r['chequeo'], $r['detalle']));
    }
    fwrite(STDOUT, sprintf("\nResultado: %d error(es), %d advertencia(s). %s\n", $errores, $adv,
        $errores === 0 ? 'Listo para continuar.' : 'Corrija los errores antes de continuar.'));
}
exit($errores === 0 ? 0 : 1);
