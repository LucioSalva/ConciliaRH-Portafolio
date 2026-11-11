<?php

declare(strict_types=1);

/**
 * Rotación de la llave de cifrado de cuentas/CLABE (docs/KEY_ROTATION.md). SÓLO CLI; nunca desde la web.
 *
 *   php bin/rotate-account-key.php --status                       Filas cifradas por tabla y key_id; llaves retirables
 *   php bin/rotate-account-key.php --dry-run [--to=ID]            Simulación completa: descifra todo lo pendiente, sin escribir
 *   php bin/rotate-account-key.php --to=ID --confirm-backup [--batch=500]
 *                                                                 Rotación real, por lotes transaccionales y reanudable
 *
 * Requisitos: ACCOUNT_ENC_KEYS con la llave anterior Y la nueva; destino por omisión = ACCOUNT_ENC_KEY_ID; credenciales del
 * rol propietario (DB_MIGRATE_USERNAME/PASSWORD) sólo al ejecutar el comando. Nunca imprime llaves ni valores en claro.
 */

use ConciliaRH\Core\Config;
use ConciliaRH\Core\Database;
use ConciliaRH\Domain\Identity\KeyRotator;

$container = (require __DIR__ . '/bootstrap.php')();
$config = $container->get(Config::class);

$opts = getopt('', ['status', 'dry-run', 'to:', 'confirm-backup', 'batch:', 'max-batches:']);
$fail = static function (string $msg, int $code = 1): never {
    fwrite(STDERR, $msg . "\n");
    exit($code);
};
if ((string) $config->get('db_migrate.password') === '') {
    $fail('Defina DB_MIGRATE_USERNAME y DB_MIGRATE_PASSWORD (rol propietario) sólo para este comando.');
}
$ring = Config::parseKeyRing((string) $config->get('app.enc_keys'));
if ($ring === null) {
    $fail('ACCOUNT_ENC_KEYS inválido (formato "id:64hex[,id:64hex]").');
}
$to = (string) ($opts['to'] ?? $config->get('app.enc_key_id'));
$batch = max(1, min(5000, (int) ($opts['batch'] ?? 500)));
$db = new Database([...$config->get('db'), 'username' => $config->get('db_migrate.username'),
    'password' => $config->get('db_migrate.password'), 'application_name' => 'conciliarh_rotacion'], (string) $config->get('app.timezone'));
$rot = new KeyRotator($db, $ring);
$audit = static function (string $accion, array $contexto) use ($db): void {
    // Sólo conteos, tablas y key_id: jamás llaves ni valores
    $db->execute("INSERT INTO auditoria (origen, accion, entidad, entidad_id, contexto, hash)
                  VALUES ('CLI', :a, 'cuentas', 'rotacion', CAST(:c AS jsonb), repeat('0', 64))",
        ['a' => $accion, 'c' => json_encode($contexto, JSON_THROW_ON_ERROR)]);
};
$print = static function (array $status): void {
    foreach ($status as $t => $byKey) {
        $parts = [];
        foreach ($byKey as $k => $n) {
            $parts[] = "$k=$n";
        }
        fwrite(STDOUT, sprintf("  %-22s %s\n", $t, $parts === [] ? '(sin filas cifradas)' : implode('  ', $parts)));
    }
};

try {
    fwrite(STDOUT, "Llaves configuradas: " . implode(', ', array_keys($ring)) . " · destino: $to\n");
    if (isset($opts['status'])) {
        $print($rot->status());
        $r = $rot->retirables();
        fwrite(STDOUT, 'Llaves sin filas cifradas (retirables del anillo): ' . ($r === [] ? 'ninguna' : implode(', ', $r)) . "\n");
        exit(0);
    }
    if (isset($opts['dry-run'])) {
        $res = $rot->dryRun($to, max($batch, 500));
        $print($res['por_key']);
        foreach ($res['pendientes'] as $t => $n) {
            fwrite(STDOUT, sprintf("  se actualizarían en %-22s %d\n", $t, $n));
        }
        fwrite(STDOUT, 'Errores: ' . count($res['errores']) . "\n");
        foreach (array_slice($res['errores'], 0, 50) as $e) {
            fwrite(STDOUT, "  {$e['tabla']} #{$e['id']}: {$e['motivo']}\n");
        }
        $audit('cuentas.rotacion_simulada', ['destino' => $to, 'pendientes' => $res['pendientes'], 'errores' => count($res['errores'])]);
        exit($res['errores'] === [] ? 0 : 2);
    }
    if (!isset($opts['confirm-backup'])) {
        $fail("ADVERTENCIA: la rotación reescribe el cifrado de todas las cuentas.\n"
            . "Antes: (1) respalde la base de datos y verifique que el respaldo se restaura; (2) ejecute --dry-run sin errores;\n"
            . "(3) conserve la llave anterior en ACCOUNT_ENC_KEYS hasta que --status la marque como retirable.\n"
            . 'Repita con --confirm-backup para ejecutar.');
    }
    $pre = $rot->dryRun($to, max($batch, 500));
    if ($pre['errores'] !== []) {
        $audit('cuentas.rotacion_rechazada', ['destino' => $to, 'errores' => count($pre['errores'])]);
        $fail('Hay ' . count($pre['errores']) . ' filas no descifrables con las llaves configuradas (ver --dry-run). No se modificó nada.', 2);
    }
    $audit('cuentas.rotacion_iniciada', ['destino' => $to, 'pendientes' => $pre['pendientes']]);
    $res = $rot->rotate($to, $batch, isset($opts['max-batches']) ? max(1, (int) $opts['max-batches']) : null,
        static function (string $t, int $n, int $total): void {
            fwrite(STDOUT, sprintf("  %-22s +%d (acumulado %d)\n", $t, $n, $total));
        });
    $audit($res['completa'] ? 'cuentas.rotacion_completada' : 'cuentas.rotacion_parcial',
        ['destino' => $to, 'rotadas' => $res['rotadas'], 'lotes' => $res['lotes']]);
    $print($rot->status());
    fwrite(STDOUT, ($res['completa'] ? 'Rotación completa.' : 'Rotación parcial: vuelva a ejecutar para continuar.')
        . ' Llaves retirables: ' . (($r = $rot->retirables()) === [] ? 'ninguna' : implode(', ', $r)) . "\n");
    exit(0);
} catch (Throwable $e) {
    // Mensajes propios sin datos sensibles; cualquier otro error se reporta genérico (sin volcar parámetros)
    // Un error de PostgreSQL puede traer la fila en DETAIL: sólo se informa el SQLSTATE
    $msg = $e instanceof PDOException ? 'Error de base de datos (SQLSTATE ' . ($e->errorInfo[0] ?? '?') . ').'
        : ($e instanceof RuntimeException ? $e->getMessage() : 'Error inesperado (' . $e::class . ').');
    try {
        $audit('cuentas.rotacion_error', ['destino' => $to, 'error' => mb_substr($msg, 0, 200)]);
    } catch (Throwable) {
    }
    $fail('Rotación detenida: ' . $msg . ' Lo ya confirmado quedó consistente; puede reanudar.', 3);
}
