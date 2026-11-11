<?php

declare(strict_types=1);

/**
 * Utilidades de terminal para los comandos que piden secretos (create-god, god-recovery). Sólo CLI.
 * `stty` se invoca SIN shell, con ruta absoluta (nunca por PATH) y con argumentos constantes.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function cli_prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $line = fgets(STDIN);
    return $line === false ? '' : trim($line);
}

function cli_stty(): ?string
{
    foreach (['/usr/bin/stty', '/bin/stty'] as $p) {
        if (is_executable($p)) {
            return $p;
        }
    }
    return null;
}

function cli_echo(bool $on): bool
{
    $stty = cli_stty();
    if ($stty === null || !function_exists('proc_open') || !stream_isatty(STDIN)) {
        return false;
    }
    $p = @proc_open([$stty, $on ? 'echo' : '-echo'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, null, []);
    return is_resource($p) && proc_close($p) === 0;
}

/**
 * Lee un secreto de la terminal SIN eco. Si no se puede apagar el eco, no lo pide (nunca se muestra una contraseña en pantalla).
 * Si llega una señal mientras espera, restituye el eco antes de salir.
 */
function cli_prompt_hidden(string $label): string
{
    if (!cli_echo(false)) {
        cli_echo(true);
        throw new RuntimeException('No se pudo ocultar la escritura en esta terminal: no se pide la contraseña.');
    }
    $restaurar = static function (): void {
        cli_echo(true);
        fwrite(STDOUT, PHP_EOL);
        exit(130);
    };
    if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM, SIGHUP] as $sig) {
            pcntl_signal($sig, $restaurar);
        }
    }
    fwrite(STDOUT, $label);
    try {
        $line = fgets(STDIN);
    } finally {
        cli_echo(true);
        fwrite(STDOUT, PHP_EOL);
        if (function_exists('pcntl_signal')) {
            foreach ([SIGINT, SIGTERM, SIGHUP] as $sig) {
                pcntl_signal($sig, SIG_DFL);
            }
        }
    }
    return $line === false ? '' : rtrim($line, "\r\n");
}

/**
 * Quién ejecuta: usuario real del proceso y, si conciliarh-run lo informó, el operador que invocó sudo.
 * El operador es DECLARATIVO (lo fija conciliarh-run desde SUDO_USER); se valida su forma para que no inyecte texto en la auditoría.
 */
function cli_os_user(): string
{
    $real = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? (string) posix_geteuid()) : (string) getenv('USER');
    $oper = (string) getenv('CONCILIARH_OPERADOR');
    if ($oper !== '' && preg_match('/^[a-z_][a-z0-9_.-]{0,31}\$?$/i', $oper) === 1 && $oper !== $real) {
        return $real . ' (operador: ' . $oper . ')';
    }
    return $real;
}
