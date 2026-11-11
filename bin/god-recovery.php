<?php

declare(strict_types=1);

/**
 * Recuperación de EMERGENCIA de la cuenta GOD (break-glass). docs/BREAK_GLASS_RECOVERY.md
 *
 *   sudo conciliarh-run god-recovery status
 *   sudo conciliarh-run god-recovery unlock          --motivo="…"
 *   sudo conciliarh-run god-recovery reactivate      --motivo="…"
 *   sudo conciliarh-run god-recovery revoke-sessions --motivo="…"
 *   sudo conciliarh-run god-recovery reset-password  --motivo="…"
 *   sudo conciliarh-run god-recovery repair          --motivo="…"
 *
 * - SÓLO CLI y sólo con las credenciales del rol PROPIETARIO de la BD (DB_MIGRATE_*), que no están al alcance de la web.
 * - Sólo opera sobre la cuenta que ya tiene el rol GOD. No crea usuarios, no asigna el rol a otra cuenta, no abre sesión.
 * - La contraseña se pide SIN eco y dos veces; nunca se acepta como argumento ni se registra.
 * - En producción exige una terminal y escribir la frase de confirmación completa.
 * - Toda operación que cambia algo queda en la auditoría como SYSTEM_BREAK_GLASS con el usuario del sistema y el motivo.
 * Códigos de salida: 0 hecho · 1 rechazado (uso, validación, confirmación) · 2 falla de BD o de configuración.
 */

use ConciliaRH\Core\Config;
use ConciliaRH\Core\Database;
use ConciliaRH\Domain\Settings\SettingsService;
use ConciliaRH\Domain\Users\GodRecovery;
use ConciliaRH\Domain\ValidationException;

$container = (require __DIR__ . '/bootstrap.php')();
require __DIR__ . '/_cli.php';

const USO = "Uso: god-recovery status|unlock|reactivate|revoke-sessions|reset-password|repair [--usuario=<god>] --motivo=\"…\"\n";

// Análisis ESTRICTO de argumentos: una operación y opciones conocidas; cualquier otra cosa se rechaza (nunca --password=…)
$op = null;
$opts = ['usuario' => null, 'motivo' => '', 'si' => false, 'password_stdin' => false];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--usuario=([a-z0-9._-]{3,50})$/', $arg, $m)) {
        $opts['usuario'] = $m[1];
    } elseif (preg_match('/^--motivo=(.{1,500})$/su', $arg, $m)) {
        $opts['motivo'] = $m[1];
    } elseif ($arg === '--si-confirmo') {
        $opts['si'] = true;
    } elseif ($arg === '--password-stdin') {
        $opts['password_stdin'] = true;
    } elseif ($op === null && in_array($arg, GodRecovery::OPERATIONS, true)) {
        $op = $arg;
    } else {
        fwrite(STDERR, "Argumento no admitido. La contraseña nunca se pasa como argumento.\n" . USO);
        exit(1);
    }
}
if ($op === null) {
    fwrite(STDERR, USO);
    exit(1);
}

$config = $container->get(Config::class);
$env = (string) $config->get('app.env');
$production = !in_array($env, ['development', 'testing'], true);       // todo lo que no sea explícitamente no-producción
if ((string) $config->get('db_migrate.password') === '') {
    fwrite(STDERR, "Este comando requiere las credenciales del rol propietario (DB_MIGRATE_*): ejecútelo con «sudo conciliarh-run god-recovery …».\n");
    exit(2);
}
if ($production && ($opts['si'] || $opts['password_stdin'])) {
    fwrite(STDERR, "En producción la recuperación es interactiva: no se admiten --si-confirmo ni --password-stdin.\n");
    exit(1);
}

try {
    $container->instance(Database::class, new Database([
        ...$config->get('db'),
        'username' => $config->get('db_migrate.username'),
        'password' => $config->get('db_migrate.password'),
        'application_name' => 'conciliarh_break_glass',
    ], (string) $config->get('app.timezone')));
    $recovery = $container->get(GodRecovery::class);
    $osUser = cli_os_user();

    $estado = $recovery->status($opts['usuario']);
    if ($op === 'status') {
        fwrite(STDOUT, "== ConciliaRH · Cuenta GOD (sólo lectura) ==\n");
        fwrite(STDOUT, sprintf("Cuentas con rol GOD: %d · activas: %d\n", $estado['cuentas_god'], $estado['cuentas_god_activas']));
        foreach (($estado['cuenta'] ?? []) as $k => $v) {
            fwrite(STDOUT, sprintf("  %-28s %s\n", $k, is_bool($v) ? ($v ? 'sí' : 'no') : ($v === null ? '—' : (string) $v)));
        }
        foreach ($estado['problemas'] as $p) {
            fwrite(STDOUT, "  ! $p\n");
        }
        exit($estado['problemas'] === [] ? 0 : 1);
    }

    // La cuenta que se confirma es la cuenta sobre la que se opera: se resuelve aquí y cada operación la exige otra vez,
    // ya con los candados tomados (si entre tanto la cuenta GOD cambió, no se hace nada).
    $usuario = $estado['cuenta']['usuario'] ?? null;
    $restituir = false;
    if ($usuario === null && $op === 'repair' && $estado['cuentas_god'] === 0 && $estado['candidata_repair'] !== null) {
        $usuario = (string) $estado['candidata_repair'];
        $restituir = true;
    }
    if ($usuario === null) {
        fwrite(STDERR, implode("\n", $estado['problemas']) . "\n");
        exit(1);
    }
    fwrite(STDOUT, "== ConciliaRH · RECUPERACIÓN DE EMERGENCIA de la cuenta GOD ==\n");
    fwrite(STDOUT, "Operación: $op · cuenta: $usuario · ejecuta: $osUser\n");
    if ($restituir) {
        fwrite(STDOUT, "Ninguna cuenta tiene el rol GOD. Se devolverá a «{$usuario}», la cuenta creada por create-god según la auditoría.\n");
        fwrite(STDOUT, "Se le quitarán otros roles, se cerrarán sus sesiones y su contraseña quedará INUTILIZADA: después ejecute reset-password.\n");
    }
    fwrite(STDOUT, "Esta acción queda registrada en la auditoría como SYSTEM_BREAK_GLASS.\n");

    // Confirmación fuerte: en producción, terminal real y frase completa
    $frase = 'RECUPERAR GOD ' . $usuario;
    if ($production || !$opts['si']) {
        if (!stream_isatty(STDIN)) {
            fwrite(STDERR, "Se requiere una terminal interactiva para confirmar.\n");
            exit(1);
        }
        if (!hash_equals($frase, cli_prompt("Para continuar escriba exactamente «{$frase}»: "))) {
            fwrite(STDERR, "Confirmación incorrecta. No se hizo ningún cambio.\n");
            exit(1);
        }
    }

    $res = match ($op) {
        'unlock' => $recovery->unlock($opts['motivo'], $osUser, $usuario),
        'reactivate' => $recovery->reactivate($opts['motivo'], $osUser, $usuario),
        'revoke-sessions' => $recovery->revokeSessions($opts['motivo'], $osUser, $usuario),
        'repair' => $recovery->repair($opts['motivo'], $osUser, $usuario),
        'reset-password' => (static function () use ($recovery, $container, $opts, $osUser, $usuario): array {
            if ($opts['password_stdin']) {
                $line = fgets(STDIN);
                $password = $line === false ? '' : rtrim($line, "\r\n");
            } else {
                if (!stream_isatty(STDIN) || cli_stty() === null) {
                    throw new RuntimeException('Se requiere una terminal para escribir la contraseña sin eco.');
                }
                $password = cli_prompt_hidden('Contraseña nueva: ');
                if (!hash_equals($password, cli_prompt_hidden('Confirme la contraseña: '))) {
                    throw new RuntimeException('Las contraseñas no coinciden. No se hizo ningún cambio.');
                }
            }
            $min = max(12, $container->get(SettingsService::class)->int('seguridad.password_longitud_minima', 12));
            return $recovery->resetPassword($password, $min, $opts['motivo'], $osUser, $usuario);
        })(),
    };
} catch (ValidationException $e) {
    foreach ($e->errors() as $field => $msg) {
        fwrite(STDERR, "  - $field: $msg\n");
    }
    exit(1);
} catch (PDOException $e) {
    // Nunca el mensaje del controlador: puede incluir el DSN o el detalle de una fila
    fwrite(STDERR, "No se pudo completar: falla de la base de datos (SQLSTATE " . preg_replace('/[^0-9A-Z]/', '', (string) $e->getCode()) . "). No se hizo ningún cambio.\n");
    exit(2);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, "No se pudo completar (" . $e::class . "). No se hizo ningún cambio.\n");
    exit(2);
}

fwrite(STDOUT, "Hecho. Cuenta: {$res['usuario']}\n");
foreach ($res as $k => $v) {
    if ($k !== 'usuario' && !is_array($v)) {
        fwrite(STDOUT, sprintf("  %-24s %s\n", $k, is_bool($v) ? ($v ? 'sí' : 'no') : (string) $v));
    } elseif (is_array($v) && $k === 'cambios') {
        fwrite(STDOUT, sprintf("  %-24s %s\n", $k, $v === [] ? 'ninguno (los invariantes ya se cumplían)' : implode(', ', $v)));
    } elseif (is_array($v) && $v !== [] && in_array($k, ['roles_adicionales', 'roles_quitados'], true)) {
        fwrite(STDOUT, sprintf("  %-24s %s\n", $k, implode(', ', array_map('strval', $v))));
    }
}
if ($op === 'reset-password') {
    fwrite(STDOUT, "La cuenta deberá cambiar la contraseña en su siguiente acceso. Entréguela en persona.\n");
}
if ($op === 'repair' && in_array('credencial_inutilizada', $res['cambios'] ?? [], true)) {
    fwrite(STDOUT, "La contraseña quedó inutilizada: ejecute ahora «god-recovery reset-password».\n");
}
