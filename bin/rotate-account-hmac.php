<?php

declare(strict_types=1);

/**
 * Rotación de la llave HMAC de cuentas/CLABE (docs/HMAC_ROTATION.md). SÓLO CLI; nunca desde la web.
 *
 *   php bin/rotate-account-hmac.php --status
 *   php bin/rotate-account-hmac.php --dry-run --to=ID [--batch-size=1000]
 *   php bin/rotate-account-hmac.php --to=ID --confirm-backup [--batch-size=500] [--max-batches=N] [--no-cutover]
 *   php bin/rotate-account-hmac.php --resume [--batch-size=500] [--max-batches=N] [--no-cutover] [--descartar-sin-cifrado]
 *   php bin/rotate-account-hmac.php --cancel
 *
 * Requisitos: ACCOUNT_HMAC_KEYS con la llave ACTIVA y la DESTINO (la activa la decide la BD); ACCOUNT_ENC_KEYS para descifrar;
 * credenciales del rol propietario (DB_MIGRATE_USERNAME/PASSWORD) sólo al ejecutar el comando. Todos los workers vivos deben
 * declarar la llave destino antes del corte. Nunca imprime llaves, huellas ni valores en claro. No ejecuta comandos de shell.
 */

use ConciliaRH\Core\Config;
use ConciliaRH\Core\Database;
use ConciliaRH\Domain\Identity\HmacRotator;

$container = (require __DIR__ . '/bootstrap.php')();
$config = $container->get(Config::class);

$opts = getopt('', ['status', 'dry-run', 'to:', 'confirm-backup', 'resume', 'cancel', 'batch-size:', 'max-batches:', 'no-cutover',
    'descartar-sin-cifrado']);
$fail = static function (string $msg, int $code = 1): never {
    fwrite(STDERR, $msg . "\n");
    exit($code);
};
if ((string) $config->get('db_migrate.password') === '') {
    $fail('Defina DB_MIGRATE_USERNAME y DB_MIGRATE_PASSWORD (rol propietario) sólo para este comando.');
}
$hmacRing = Config::parseHmacRing((string) $config->get('app.hmac_keys'), (string) $config->get('app.hmac_key'));
$encRing = Config::parseKeyRing((string) $config->get('app.enc_keys'));
if ($hmacRing === null || $encRing === null) {
    $fail('ACCOUNT_HMAC_KEYS ("id:secreto[,id:secreto]") y ACCOUNT_ENC_KEYS son obligatorios para rotar.');
}
$to = isset($opts['to']) ? (string) $opts['to'] : '';
if ($to !== '' && !preg_match('/^[a-z0-9_-]{1,16}$/', $to)) {
    $fail('--to debe ser un key_id válido.');
}
$batch = max(1, min(5000, (int) ($opts['batch-size'] ?? 500)));
$maxBatches = isset($opts['max-batches']) ? max(1, (int) $opts['max-batches']) : null;
$hb = max(30, (int) (getenv('WORKER_HEARTBEAT_TIMEOUT') ?: 120));
$db = new Database([...$config->get('db'), 'username' => $config->get('db_migrate.username'),
    'password' => $config->get('db_migrate.password'), 'application_name' => 'conciliarh_rotacion_hmac'], (string) $config->get('app.timezone'));
$rot = new HmacRotator($db, $hmacRing, $encRing);
$osUser = (string) (getenv('USER') ?: getenv('LOGNAME') ?: 'cli');
$audit = static function (string $accion, array $contexto) use ($db): void {
    // Sólo conteos, tablas y key_id: jamás llaves, huellas ni valores
    $db->execute("INSERT INTO auditoria (origen, accion, entidad, entidad_id, contexto, hash)
                  VALUES ('CLI', :a, 'cuentas', 'rotacion_hmac', CAST(:c AS jsonb), repeat('0', 64))",
        ['a' => $accion, 'c' => json_encode($contexto, JSON_THROW_ON_ERROR)]);
};
$printStatus = static function (array $st): void {
    fwrite(STDOUT, "Llave HMAC activa (BD): {$st['activa']}\n");
    fwrite(STDOUT, 'Rotación en curso: ' . ($st['en_curso'] === null ? 'ninguna' : "#{$st['en_curso']['id']} {$st['en_curso']['key_origen']} → {$st['en_curso']['key_destino']}") . "\n");
    foreach ($st['por_tabla'] as $t => $byKey) {
        $parts = [];
        foreach ($byKey as $k => $n) {
            $parts[] = "$k=$n";
        }
        fwrite(STDOUT, sprintf("  %-20s %s · sin sombra: %d\n", $t, $parts === [] ? '(sin huellas)' : implode('  ', $parts), $st['pendientes_sombra'][$t]));
    }
    foreach ($st['workers'] as $w) {
        fwrite(STDOUT, "  worker {$w['id']}: llaves " . ($w['llaves'] === [] ? '(no declaradas)' : implode(', ', $w['llaves'])) . "\n");
    }
    fwrite(STDOUT, 'Llaves retirables del anillo: ' . ($st['retirables'] === [] ? 'ninguna' : implode(', ', $st['retirables'])) . "\n");
};

// Una sola CLI de rotación a la vez (candado de sesión; se libera al terminar el proceso)
if (!(bool) $db->fetchValue('SELECT pg_try_advisory_lock(:k)', ['k' => HmacRotator::CLI_LOCK])) {
    $fail('Otra rotación HMAC se está ejecutando en este momento.', 4);
}

try {
    fwrite(STDOUT, 'Llaves HMAC configuradas: ' . implode(', ', array_keys($hmacRing)) . "\n");
    if (isset($opts['status'])) {
        $printStatus($rot->status($hb));
        exit(0);
    }
    if (isset($opts['cancel'])) {
        $id = $rot->cancel();
        $audit('cuentas.rotacion_hmac_cancelada', ['rotacion' => $id]);
        fwrite(STDOUT, "Rotación #$id cancelada; la llave activa no cambió.\n");
        exit(0);
    }
    if (isset($opts['dry-run'])) {
        $dest = $to !== '' ? $to : ($rot->inProgress()['key_destino'] ?? '');
        if ($dest === '') {
            $fail('Indique --to=ID.');
        }
        $res = $rot->dryRun($dest, max($batch, 500), $hb);
        fwrite(STDOUT, "Huellas totales: {$res['total']} · activa: {$rot->active()} · destino: $dest\n");
        foreach ($res['necesitan'] as $t => $n) {
            fwrite(STDOUT, sprintf("  requieren migración en %-20s %d\n", $t, $n));
        }
        fwrite(STDOUT, "Sin valor cifrado: {$res['sin_cifrado']} filas ({$res['sin_cifrado_huellas']} huellas distintas, {$res['sin_cifrado_mapeables']} con correspondencia)\n");
        fwrite(STDOUT, "Colisiones o inconsistencias: {$res['colisiones']}\n");
        fwrite(STDOUT, 'Workers vivos sin la llave destino: ' . ($res['workers_sin_destino'] === [] ? 'ninguno' : implode(', ', $res['workers_sin_destino'])) . "\n");
        fwrite(STDOUT, 'Errores: ' . count($res['errores']) . "\n");
        foreach (array_slice($res['errores'], 0, 50) as $e) {
            fwrite(STDOUT, "  {$e['tabla']} #{$e['id']}: {$e['motivo']}\n");
        }
        $audit('cuentas.rotacion_hmac_simulada', ['destino' => $dest, 'total' => $res['total'], 'necesitan' => $res['necesitan'],
            'sin_cifrado' => $res['sin_cifrado'], 'colisiones' => $res['colisiones'], 'errores' => count($res['errores'])]);
        exit($res['errores'] === [] ? 0 : 2);
    }
    if (!isset($opts['resume'])) {
        if ($to === '') {
            $fail('Indique --to=ID (o --resume para continuar la rotación en curso).');
        }
        if (!isset($opts['confirm-backup'])) {
            $fail("ADVERTENCIA: la rotación recalcula la huella HMAC de todas las cuentas.\n"
                . "Antes: (1) respalde la base de datos y verifique que se restaura; (2) agregue la llave destino a ACCOUNT_HMAC_KEYS\n"
                . "del worker y de esta CLI SIN quitar la activa y reinicie el worker (la web no la usa); (3) ejecute --dry-run sin errores.\n"
                . 'Repita con --confirm-backup para ejecutar.');
        }
        $pre = $rot->dryRun($to, max($batch, 500), $hb);
        if ($pre['errores'] !== []) {
            $audit('cuentas.rotacion_hmac_rechazada', ['destino' => $to, 'errores' => count($pre['errores']), 'colisiones' => $pre['colisiones']]);
            $fail('Hay ' . count($pre['errores']) . ' errores (ver --dry-run). No se modificó nada.', 2);
        }
        $id = $rot->start($to, $osUser);
        $audit('cuentas.rotacion_hmac_iniciada', ['rotacion' => $id, 'destino' => $to, 'necesitan' => $pre['necesitan']]);
    } elseif ($rot->inProgress() === null) {
        $fail('No hay una rotación en curso para reanudar.');
    }
    $cur = $rot->inProgress();
    $res = $rot->prepare($batch, $maxBatches, static function (string $t, int $n, int $total): void {
        fwrite(STDOUT, sprintf("  %-20s +%d (acumulado %d)\n", $t, $n, $total));
    });
    // Sólo quedan huellas irrecuperables (sin valor cifrado ni correspondencia): el corte decide (se niega sin --descartar-sin-cifrado)
    $soloSinMapa = !$res['completa'] && $res['restantes'] > 0 && $res['restantes'] === $res['sin_mapa'] && $maxBatches === null;
    if ((!$res['completa'] && !$soloSinMapa) || isset($opts['no-cutover'])) {
        $audit('cuentas.rotacion_hmac_parcial', ['rotacion' => $cur['id'], 'destino' => $cur['key_destino'], 'lotes' => $res['lotes'],
            'restantes' => $res['restantes']]);
        fwrite(STDOUT, "Preparación " . ($res['completa'] ? 'completa' : 'parcial') . ": quedan {$res['restantes']} huellas sin sombra. "
            . "La llave activa NO cambió. Continúe con --resume.\n");
        exit(0);
    }
    $cut = $rot->cutover(isset($opts['descartar-sin-cifrado']), $hb);
    $audit('cuentas.rotacion_hmac_completada', ['rotacion' => $cur['id'], 'destino' => $cut['destino'], 'cambiadas' => $cut['cambiadas'],
        'descartadas' => $cut['descartadas']]);
    $printStatus($rot->status($hb));
    fwrite(STDOUT, "Corte completado: la llave activa es {$cut['destino']}. Retire la anterior del anillo sólo cuando aparezca como retirable.\n");
    exit(0);
} catch (Throwable $e) {
    // Un error de PostgreSQL puede traer la fila en DETAIL: sólo se informa el SQLSTATE
    $msg = $e instanceof PDOException ? 'Error de base de datos (SQLSTATE ' . ($e->errorInfo[0] ?? '?') . ').'
        : ($e instanceof RuntimeException ? $e->getMessage() : 'Error inesperado (' . $e::class . ').');
    try {
        $audit('cuentas.rotacion_hmac_error', ['error' => mb_substr($msg, 0, 300)]);
    } catch (Throwable) {
    }
    $fail('Rotación HMAC detenida: ' . $msg . ' La llave activa sólo cambia en un corte completo; puede reanudar.', 3);
}
