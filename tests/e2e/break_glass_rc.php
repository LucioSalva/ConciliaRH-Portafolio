<?php
/**
 * Recuperación de emergencia de la cuenta GOD (docs/BREAK_GLASS_RECOVERY.md) sobre el SERVIDOR DE PRUEBA.
 *
 * Se ejecuta DENTRO del servidor de prueba, como root, con el php-cli del servidor (sin ext-curl: el cliente HTTP usa los
 * flujos de PHP), SIEMPRE AL FINAL (cambia la contraseña del GOD de prueba, que se guarda en /root/god-pass):
 *
 *     docker cp tests/e2e/break_glass_rc.php crh-rc:/root/ && docker cp tests/e2e/lib crh-rc:/root/
 *     docker exec crh-rc php /root/break_glass_rc.php --god-user god.prueba [--url https://127.0.0.1]
 *
 * Comprueba: ejecución no autorizada, invocación desde la web, abuso de sudo, inyección de comandos y argumentos, PATH y
 * entorno manipulados, concurrencia, falla de la BD y que la contraseña no aparece en la lista de procesos ni en ningún log.
 * Las preguntas se contestan en una terminal real (proc_open con pty). Nunca contra producción: provoca bloqueos, detiene
 * PostgreSQL y restablece la contraseña del GOD.
 */

declare(strict_types=1);

require __DIR__ . '/lib/Cliente.php';

const RUN = '/usr/local/sbin/conciliarh-run';
const MOTIVO = '--motivo=Prueba automatica de recuperacion de emergencia';

$OK = 0;
$FAIL = 0;

function bg_check(string $name, bool $cond, string $extra = ''): void
{
    global $OK, $FAIL;
    $cond ? $OK++ : $FAIL++;
    echo ($cond ? '  ✔ ' : '  ✘ ') . $name . ($extra !== '' && !$cond ? '  [' . mb_substr($extra, 0, 260) . ']' : '') . "\n";
}

/**
 * @param list<string> $args
 * @param array<string,string>|null $env
 * @return array{0:int,1:string}
 */
function sh(array $args, ?array $env = null, ?string $user = null, string $stdin = ''): array
{
    if ($user !== null) {
        $args = ['runuser', '-u', $user, '--', ...$args];
    }
    [$rc, $out, $err] = ejecutar($args, $stdin, $env, null, 120);
    return [$rc, $out . $err];
}

function sql(string $q): string
{
    global $DB;
    return trim(ejecutar(['sudo', '-u', 'postgres', 'psql', '-d', $DB, '-XAtc', $q], '', null, '/tmp')[1]);
}

function ps_args(): string
{
    return ejecutar(['ps', '-eo', 'args'])[1];
}

/** @return array<string,string> */
function entorno(array $extra = []): array
{
    return [...getenv(), ...$extra];
}

/**
 * @param list<array{args:list<string>,respuestas:list<array{0:string,1:string}>,env?:array<string,string>|null,espiar?:string|null}> $trabajos
 * @return list<array{0:int,1:string,2:bool}>
 */
function interactivos(array $trabajos): array
{
    $vivos = [];
    foreach ($trabajos as $i => $t) {
        $p = proc_open($t['args'], [['pty'], ['pty'], ['pty']], $pipes, null, $t['env'] ?? null);
        stream_set_blocking($pipes[1], false);
        $vivos[$i] = ['p' => $p, 'in' => $pipes[0], 'out' => $pipes[1], 'buf' => '', 'pend' => $t['respuestas'], 'espiar' => $t['espiar'] ?? null,
                      'visto' => false, 'rc' => null, 'fin' => false];
    }
    $limite = microtime(true) + 90;
    while (microtime(true) < $limite && array_filter($vivos, static fn ($v) => !$v['fin']) !== []) {
        $r = array_values(array_map(static fn ($v) => $v['out'], array_filter($vivos, static fn ($v) => !$v['fin'])));
        $w = $e = null;
        @stream_select($r, $w, $e, 0, 200000);
        foreach ($vivos as $i => &$v) {
            if ($v['fin']) {
                continue;
            }
            $chunk = @fread($v['out'], 4096);
            if (is_string($chunk) && $chunk !== '') {
                $v['buf'] .= $chunk;
            }
            $st = proc_get_status($v['p']);
            if (!$st['running']) {
                $v['rc'] ??= $st['exitcode'];
                while (is_string($chunk = @fread($v['out'], 4096)) && $chunk !== '') {
                    $v['buf'] .= $chunk;
                }
                $v['fin'] = true;
                continue;
            }
            if ($v['pend'] !== []) {
                $lineas = explode("\n", $v['buf']);
                $cola = end($lineas) . substr($v['buf'], -200);
                [$pregunta, $resp] = $v['pend'][0];
                if (str_contains($cola, $pregunta)) {
                    array_shift($v['pend']);
                    usleep(300000);
                    fwrite($v['in'], $resp . "\n");
                    $v['buf'] .= "\n<<respondido>>\n";
                    if ($v['espiar'] !== null) {
                        usleep(300000);
                        $v['visto'] = $v['visto'] || str_contains(ps_args(), $v['espiar']);
                    }
                }
            }
        }
        unset($v);
    }
    $out = [];
    foreach ($vivos as $i => $v) {
        if (!$v['fin']) {
            proc_terminate($v['p'], 9);
            usleep(200000);
            $v['rc'] ??= proc_get_status($v['p'])['exitcode'];
        }
        @fclose($v['in']);
        @fclose($v['out']);
        $rc = proc_close($v['p']);
        $codigo = $v['rc'] ?? $rc;
        $visto = $v['visto'] || ($v['espiar'] !== null && str_contains($v['buf'], $v['espiar']));
        $out[$i] = [(int) $codigo, $v['buf'], $visto];
    }
    return $out;
}

/**
 * @param list<string> $args
 * @param list<array{0:string,1:string}> $respuestas
 * @param array<string,string>|null $env
 * @return array{0:int,1:string,2:bool}
 */
function interactivo(array $args, array $respuestas, ?array $env = null, ?string $espiar = null): array
{
    return interactivos([['args' => $args, 'respuestas' => $respuestas, 'env' => $env, 'espiar' => $espiar]])[0];
}

$uso = "Uso: php /root/break_glass_rc.php --god-user USUARIO [--url https://127.0.0.1]\n";
$a = opciones(['god-user' => true, 'url' => 'https://127.0.0.1'], $uso);
$god = $a['god-user'];
$url = $a['url'];
$cli = static fn (): Cliente => new Cliente($url, true);
$entra = static fn (string $pass): bool => !in_array($cli()->login($god, $pass), ['', '/login'], true);
$DB = trim(ejecutar(['sed', '-n', 's/^DB_DATABASE=//p', '/etc/conciliarh/web.env'])[1]) ?: 'conciliarh';
if (!str_contains(ejecutar(['sed', '-n', 's/^APP_URL=//p', '/etc/conciliarh/web.env'])[1], 'prueba')) {
    echo "Este servidor no parece el de prueba (APP_URL). No se ejecuta.\n";
    exit(2);
}
$usuarios0 = sql('SELECT count(*) FROM usuarios');
$frase = "RECUPERAR GOD $god";
$hashGod = static fn (): string => sql("SELECT md5(password_hash) FROM usuarios WHERE username = '$god'");
$sesionesAbiertas = static fn (): string => sql("SELECT count(*) FROM sesiones s JOIN usuarios u ON u.id = s.usuario_id WHERE u.username = '$god' AND s.revocada_at IS NULL");
$unGod = "SELECT count(*) FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id WHERE r.codigo = 'GOD'";

echo "1. Estado (sólo lectura)\n";
$aud0 = sql('SELECT count(*) FROM auditoria');
[$rc, $out] = sh([RUN, 'god-recovery', 'status']);
bg_check('status responde 0 y nombra la cuenta GOD', $rc === 0 && str_contains($out, $god), $out);
bg_check('status no muestra hashes', !str_contains($out, 'argon2') && !str_contains($out, '$2y$'));
bg_check('status no escribe en la auditoría', sql('SELECT count(*) FROM auditoria') === $aud0);

echo "2. Ejecución no autorizada\n";
foreach (['conciliarh-web', 'conciliarh-worker', 'conciliarh-sched', 'conciliarh-backup', 'nobody'] as $u) {
    [$rc, $out] = sh([RUN, 'god-recovery', 'status'], null, $u);
    bg_check("$u: conciliarh-run exige root", $rc !== 0 && !str_contains($out, $god), $out);
    [$rc, $out] = sh(['cat', '/etc/conciliarh/cli.env'], null, $u);
    bg_check("$u: no lee las credenciales del propietario", $rc !== 0 && !str_contains($out, 'DB_MIGRATE_PASSWORD'));
}
[$rc, $out] = sh(['bash', '-c', "set -a; . /etc/conciliarh/web.env; set +a; exec /usr/bin/php /opt/conciliarh/current/bin/god-recovery.php reset-password "
    . "'--motivo=intento directo del usuario web'"], null, 'conciliarh-web');
bg_check('usuario web con SU configuración: rechazado por falta de credenciales del propietario', $rc === 2 && str_contains($out, 'rol propietario'), $out);
$pw = preg_split('/\s+/', trim(sh(['passwd', '-S', 'conciliarh-admin'])[1]));
bg_check('conciliarh-admin no tiene shell ni contraseña',
    (str_ends_with(trim(sh(['getent', 'passwd', 'conciliarh-admin'])[1]), 'nologin') || str_ends_with(trim(sh(['getent', 'passwd', 'conciliarh-admin'])[1]), 'false'))
    && in_array($pw[1] ?? '', ['L', 'LK'], true));

echo "3. Invocación desde la web\n";
$c = $cli();
foreach (['/bin/god-recovery.php', '/god-recovery', '/bin/_cli.php', '/bin/audit-anchor.php', '/emergencia', '/recuperar-god', '/../bin/god-recovery.php',
          '/index.php/bin/god-recovery.php'] as $path) {
    [$s, , $body] = $c->get($path);
    bg_check("GET $path no existe ($s)", in_array($s, [302, 303, 400, 404], true) && !str_contains($body, 'RECUPERAC'));
}
$rutas = strtolower(leer('/opt/conciliarh/current/config/routes.php'));
bg_check('ninguna ruta web menciona la recuperación', !array_filter(['recovery', 'break', 'emergencia', 'godrecovery'], static fn ($k) => str_contains($rutas, $k)));
bg_check('bin/ está fuera del directorio servido', !file_exists('/opt/conciliarh/current/public/bin'));
bg_check('PHP-FPM tiene proc_open deshabilitado (la web no lanza procesos)', str_contains(sh(['grep', '-rh', 'disable_functions', '/etc/php'])[1], 'proc_open'));

echo "4. Abuso de sudo, inyección de comandos y de argumentos\n";
$marca = '/tmp/crh-inyeccion';
@unlink($marca);
foreach ([['god-recovery; touch /tmp/crh-inyeccion'], ['god-recovery', 'status; touch /tmp/crh-inyeccion'], ['god-recovery', '$(touch /tmp/crh-inyeccion)'],
          ['god-recovery', 'status', '--usuario=`touch /tmp/crh-inyeccion`'], ['../../bin/sh', '-c', 'touch /tmp/crh-inyeccion'],
          ['god-recovery', 'reset-password', '--password=Secreta-Por-Argumento-9'], ['god-recovery', 'reset-password', MOTIVO, '--password-stdin', '--si-confirmo'],
          ['god-recovery', 'reset-password', MOTIVO, 'Secreta-Por-Argumento-Posicional'], ['create-god', '--password=Secreta-Por-Argumento-8'],
          ['god-recovery', 'status', '--usuario=../../../etc/passwd'], ['god-recovery', 'crear-god'], ['god-recovery', 'status', '-d', 'auto_prepend_file=/tmp/x.php']] as $args) {
    [$rc, $out] = sh([RUN, ...$args], null, null, "Clave-Por-Tuberia-2027\n");
    bg_check('rechazado: ' . mb_substr(implode(' ', $args), 0, 60), $rc !== 0 && !str_contains($out, 'Hecho') && !str_contains($out, 'Secreta'), $out);
}
bg_check('ninguna inyección se ejecutó', !file_exists($marca));
foreach (['e2e.admin', 'e2e.admin2', 'e2e.consulta', 'no.existe'] as $otro) {
    [$rc, $out] = interactivo([RUN, 'god-recovery', 'reset-password', MOTIVO, "--usuario=$otro"], [['escriba exactamente', "RECUPERAR GOD $otro"]]);
    bg_check("no opera sobre «{$otro}» (no es GOD)", $rc !== 0 && !str_contains($out, 'Hecho'), $out);
}
bg_check('no hay segundo GOD ni usuarios nuevos', sql($unGod) === '1' && sql('SELECT count(*) FROM usuarios') === $usuarios0);

echo "5. PATH y entorno manipulados\n";
$evil = '/tmp/crh-evil';
@mkdir($evil);
foreach (['php', 'systemd-run', 'stty', 'id', 'readlink', 'stat', 'find', 'sed'] as $b) {
    file_put_contents("$evil/$b", "#!/bin/sh\ntouch /tmp/crh-evil/EJECUTADO\nexit 0\n");
    chmod("$evil/$b", 0755);
}
$env = entorno(['PATH' => $evil . ':' . (getenv('PATH') ?: '/usr/bin:/bin')]);
[$rc, $out] = sh([RUN, 'god-recovery', 'status'], $env);
bg_check('con un PATH envenenado funciona igual y no ejecuta los binarios falsos', $rc === 0 && str_contains($out, $god) && !file_exists("$evil/EJECUTADO"), $out);
$env = entorno(['APP_ENV' => 'testing', 'DB_DATABASE' => 'postgres', 'DB_MIGRATE_USERNAME' => 'postgres', 'APP_DEBUG' => 'true',
                'CONCILIARH_BASE_PATH' => $evil, 'LD_PRELOAD' => '/tmp/crh-evil/x.so', 'PHPRC' => $evil, 'PHP_INI_SCAN_DIR' => $evil]);
[$rc, $out] = sh([RUN, 'audit-anchor'], $env);
bg_check('el entorno de quien invoca NO llega al programa (APP_ENV, DB_*, PHPRC, LD_PRELOAD)', $rc === 0 && str_contains($out, '"entorno": "production"'), substr($out, -300));
[$rc, $out] = sh([RUN, 'god-recovery', 'status'], $env);
bg_check('status con el entorno manipulado sigue leyendo la base real', $rc === 0 && str_contains($out, $god), substr($out, -300));
[$rc, $out] = interactivo([RUN, 'god-recovery', 'unlock', MOTIVO], [['escriba exactamente', 'si']], $env);
bg_check('APP_ENV=testing heredado NO relaja la confirmación', $rc !== 0 && str_contains($out, 'Confirmación incorrecta'), substr($out, -300));
foreach (['bin', 'app', 'config', 'vendor'] as $d) {
    @mkdir("$evil/$d");
}
file_put_contents("$evil/bin/god-recovery.php", "<?php touch('/tmp/crh-evil/EJECUTADO');");
chown($evil, 65534);
chgrp($evil, 65534);
[$rc, $out] = sh([RUN, 'god-recovery', 'status'], entorno(['CONCILIARH_HOME' => $evil]));
bg_check('CONCILIARH_HOME hacia un directorio ajeno: rechazado', $rc !== 0 && str_contains($out, 'no confiable') && !file_exists("$evil/EJECUTADO"), $out);
ejecutar(['rm', '-rf', $evil]);

echo "6. Restablecer la contraseña (terminal real, confirmación fuerte)\n";
$hash0 = $hashGod();
[$rc, $out] = interactivo([RUN, 'god-recovery', 'reset-password', MOTIVO], [['escriba exactamente', 'si']]);
bg_check('frase de confirmación incorrecta: no cambia nada', $rc !== 0 && str_contains($out, 'Confirmación incorrecta') && $hashGod() === $hash0, $out);
[$rc, $out] = interactivo([RUN, 'god-recovery', 'reset-password'], [['escriba exactamente', $frase], ['Contraseña nueva', 'Valida-Pero-Sin-Motivo-2027'],
                                                                    ['Confirme', 'Valida-Pero-Sin-Motivo-2027']]);
bg_check('sin motivo: rechazado', $rc !== 0 && str_contains($out, 'motivo') && $hashGod() === $hash0, $out);
[$rc, $out] = interactivo([RUN, 'god-recovery', 'reset-password', MOTIVO], [['escriba exactamente', $frase], ['Contraseña nueva', 'corta'], ['Confirme', 'corta']]);
bg_check('contraseña débil: rechazada', $rc !== 0 && $hashGod() === $hash0, $out);
[$rc, $out] = interactivo([RUN, 'god-recovery', 'reset-password', MOTIVO], [['escriba exactamente', $frase], ['Contraseña nueva', 'Una-Clave-Larga-2027'],
                                                                            ['Confirme', 'Otra-Clave-Larga-2027']]);
bg_check('contraseñas distintas: rechazadas', $rc !== 0 && str_contains($out, 'no coinciden') && $hashGod() === $hash0, $out);

$c1 = $cli();
$viejo = trim(leer('/root/god-pass'));
bg_check('(preparación) el GOD tiene una sesión abierta', $c1->login($god, $viejo) !== '' && $sesionesAbiertas() !== '0');
$nueva = 'Bg-' . bin2hex(random_bytes(9)) . '-Emergencia!7';
[$rc, $out, $visto] = interactivo([RUN, 'god-recovery', 'reset-password', MOTIVO], [['escriba exactamente', $frase], ['Contraseña nueva', $nueva], ['Confirme', $nueva]],
    null, $nueva);
bg_check('restablecida', $rc === 0 && str_contains($out, 'Hecho'), $out);
bg_check('la contraseña NO se ve en pantalla ni en la lista de procesos', !$visto);
bg_check('obliga a cambiarla y revoca las sesiones', sql("SELECT debe_cambiar_password FROM usuarios WHERE username = '$god'") === 't' && $sesionesAbiertas() === '0');
bg_check('la sesión anterior ya no entra', in_array($c1->get('/usuarios')[0], [302, 303], true));
bg_check('la contraseña anterior ya no sirve y la nueva sí', !$entra($viejo) && $entra($nueva));
$aud = sql("SELECT username || '|' || origen || '|' || coalesce(usuario_id::text, 'NULL') || '|' || (contexto->>'tipo') || '|' || (contexto->>'usuario_so') "
    . "FROM auditoria WHERE accion = 'emergencia.god_reset_password' ORDER BY secuencia DESC LIMIT 1");
bg_check('auditada como SYSTEM_BREAK_GLASS, origen CLI, sin usuario de aplicación y con el usuario del sistema',
    str_starts_with($aud, 'SYSTEM_BREAK_GLASS|CLI|NULL|SYSTEM_BREAK_GLASS|conciliarh-admin'), $aud);
bg_check('el operador (quien invocó) queda registrado', str_contains($aud, 'operador: root'), $aud);
file_put_contents('/root/god-pass', $nueva . "\n");
chmod('/root/god-pass', 0600);

echo "7. La contraseña no queda en ningún registro\n";
$fuentes = [
    'auditoría' => sql("SELECT string_agg(coalesce(valor_anterior::text,'') || coalesce(valor_nuevo::text,'') || coalesce(contexto::text,''), ' ') FROM auditoria"),
    'journal' => sh(['journalctl', '--no-pager', '-o', 'cat', '--since', '-30min'])[1],
    'logs de la aplicación' => sh(['bash', '-c', 'cat /var/lib/conciliarh/logs/* /var/log/apache2/* /var/log/php* /var/log/postgresql/* 2>/dev/null'])[1],
    'historial de shell' => sh(['bash', '-c', 'cat /root/.bash_history /home/*/.bash_history 2>/dev/null'])[1],
];
foreach ($fuentes as $nombre => $txt) {
    bg_check("$nombre: sin la contraseña", !str_contains($txt, $nueva) && !str_contains($txt, 'Secreta-Por-Argumento') && !str_contains($txt, 'Clave-Por-Tuberia'));
}
$hashNuevo = sql("SELECT password_hash FROM usuarios WHERE username = '$god'");
bg_check('auditoría y logs: sin el hash', !str_contains($fuentes['auditoría'], $hashNuevo) && !str_contains($fuentes['journal'], $hashNuevo)
    && !str_contains($fuentes['logs de la aplicación'], $hashNuevo));

echo "8. Desbloquear, revocar sesiones y reactivar\n";
for ($i = 0; $i < 6; $i++) {
    $cli()->login($god, 'incorrecta-' . bin2hex(random_bytes(4)));
}
[$rc, $out] = sh([RUN, 'god-recovery', 'status']);
$trasBloqueo = explode("\n", explode('bloqueada_por_intentos', $out, 2)[1] ?? '')[0];
bg_check('tras 6 fallos la cuenta queda bloqueada', str_contains($out, 'bloqueada_por_intentos') && str_contains($trasBloqueo, 'sí'), $out);
bg_check('bloqueada: ni con la contraseña correcta entra', !$entra($nueva));
[$rc, $out] = interactivo([RUN, 'god-recovery', 'unlock', MOTIVO], [['escriba exactamente', $frase]]);
bg_check('desbloqueada', $rc === 0 && str_contains($out, 'intentos_eliminados'), $out);
$c3 = $cli();
bg_check('ya entra con su contraseña', !in_array($c3->login($god, $nueva), ['', '/login'], true));
[$rc, $out] = interactivo([RUN, 'god-recovery', 'revoke-sessions', MOTIVO], [['escriba exactamente', $frase]]);
bg_check('sesiones revocadas', $rc === 0 && in_array($c3->get('/usuarios')[0], [302, 303], true), $out);
sql("UPDATE usuarios SET activo = false WHERE username = '$god'");
[$rc, $out] = sh([RUN, 'god-recovery', 'status']);
bg_check('status detecta que no hay GOD activo (código 1)', $rc === 1 && str_contains($out, 'No hay ninguna cuenta GOD activa'), $out);
[$rc, $out] = interactivo([RUN, 'god-recovery', 'reactivate', MOTIVO], [['escriba exactamente', $frase]]);
bg_check('reactivada', $rc === 0 && sql("SELECT activo FROM usuarios WHERE username = '$god'") === 't', $out);
[$rc, $out] = interactivo([RUN, 'god-recovery', 'repair', MOTIVO], [['escriba exactamente', $frase]]);
bg_check('repair con los invariantes en orden no cambia nada', $rc === 0 && str_contains($out, 'ninguno'), $out);
$gid = sql("SELECT id FROM usuarios WHERE username = '$god'");
sql("SET ROLE conciliarh_owner; DELETE FROM usuario_rol WHERE usuario_id = $gid");
[$rc, $out] = sh([RUN, 'god-recovery', 'status']);
bg_check('sin rol GOD: status lo dice', $rc === 1 && str_contains($out, 'Ninguna cuenta tiene el rol GOD'), $out);
[$rc, $out] = interactivo([RUN, 'god-recovery', 'repair', MOTIVO], [['escriba exactamente', $frase]]);
bg_check('repair nombra la cuenta ANTES de confirmar y devuelve el rol', $rc === 0 && str_contains($out, "Se devolverá a «{$god}»") && str_contains($out, 'rol_god_restituido'), $out);
bg_check('tras repair la contraseña anterior ya no entra (hay que restablecerla)', !$entra($nueva));
[$rc, $out] = interactivo([RUN, 'god-recovery', 'reset-password', MOTIVO], [['escriba exactamente', $frase], ['Contraseña nueva', $nueva . 'x'], ['Confirme', $nueva . 'x']]);
$nueva .= 'x';
bg_check('y con reset-password vuelve a entrar', $rc === 0 && $entra($nueva), $out);
file_put_contents('/root/god-pass', $nueva . "\n");

echo "9. Concurrencia\n";
$claves = ['Cc-' . bin2hex(random_bytes(9)) . '-Paralela!1', 'Cc-' . bin2hex(random_bytes(9)) . '-Paralela!2'];
$n0 = (int) sql("SELECT count(*) FROM auditoria WHERE accion = 'emergencia.god_reset_password'");
$alta = proc_open([RUN, 'create-god', '--username=segundo.god', '--nombre=Segundo God', '--password-stdin'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $ap);
fwrite($ap[0], "Segundo-God-Clave-2027\n");
fclose($ap[0]);
$res = interactivos(array_map(static fn ($k) => ['args' => [RUN, 'god-recovery', 'reset-password', MOTIVO],
    'respuestas' => [['escriba exactamente', $frase], ['Contraseña nueva', $k], ['Confirme', $k]]], $claves));
$outGod = stream_get_contents($ap[1]) . stream_get_contents($ap[2]);
fclose($ap[1]);
fclose($ap[2]);
$rcGod = proc_close($alta);
bg_check('dos recuperaciones simultáneas terminan las dos, en serie', $res[0][0] === 0 && $res[1][0] === 0, substr($res[0][1], -200) . substr($res[1][1], -200));
bg_check('quedan las dos en la auditoría', (int) sql("SELECT count(*) FROM auditoria WHERE accion = 'emergencia.god_reset_password'") === $n0 + 2);
$entran = array_map($entra, $claves);
bg_check('la cuenta queda con EXACTAMENTE una de las dos contraseñas', count(array_filter($entran)) === 1, json_encode($entran));
bg_check('un alta de GOD simultánea se rechaza (ya hay un GOD activo)', $rcGod !== 0 && str_contains($outGod, 'Ya existe'), $outGod);
bg_check('sigue habiendo un solo GOD y los mismos usuarios', sql($unGod) === '1' && sql('SELECT count(*) FROM usuarios') === $usuarios0);
$vigente = array_search(true, $entran, true);
if ($vigente !== false) {
    file_put_contents('/root/god-pass', $claves[$vigente] . "\n");
}

echo "10. Falla de la base de datos\n";
$hash1 = $hashGod();
sh(['systemctl', 'stop', 'postgresql']);
try {
    [$rc, $out] = sh([RUN, 'god-recovery', 'status']);
    bg_check('status con la BD caída: código 2, sin detalles de conexión', $rc === 2 && str_contains($out, 'No se pudo completar')
        && !array_filter(['pgsql:', 'host=', 'password', 'conciliarh_owner'], static fn ($k) => str_contains($out, $k)), $out);
    [$rc, $out] = interactivo([RUN, 'god-recovery', 'unlock', MOTIVO], [['escriba exactamente', $frase]]);
    bg_check('operación con la BD caída: código 2 y ningún cambio', $rc === 2 && !str_contains($out, 'Hecho'), $out);
} finally {
    sh(['systemctl', 'start', 'postgresql']);
    for ($i = 0; $i < 30; $i++) {
        if (sh(['pg_isready', '-q'])[0] === 0) {
            break;
        }
        sleep(1);
    }
}
bg_check('tras volver la BD la cuenta está intacta', $hashGod() === $hash1);

echo "11. Ancla de auditoría y cadena\n";
[$rc, $ancla] = sh([RUN, 'audit-anchor']);
bg_check('audit-anchor entrega el ancla', $rc === 0 && str_contains($ancla, '"ultimo_hash"') && str_contains($ancla, '"entorno": "production"'), $ancla);
bg_check('el ancla no contiene usuarios ni direcciones', !str_contains($ancla, $god) && !str_contains($ancla, 'e2e.') && !str_contains($ancla, '127.0.0.1')
    && !str_contains($ancla, 'SYSTEM_BREAK_GLASS'));
[$rc, $out] = sh([RUN, 'audit-anchor', '--verificar'], null, null, $ancla);
bg_check('el ancla verifica contra la BD', $rc === 0 && str_contains($out, 'COINCIDE'), $out);
[$rc, $out] = sh([RUN, 'audit-anchor', '--verificar'], null, null, substr(str_replace('"ultimo_hash": "', '"ultimo_hash": "0000', $ancla), 0, strlen($ancla)));
bg_check('un ancla que no corresponde se detecta', in_array($rc, [1, 3], true), $out);
bg_check('la verificación incluye la integridad de TODA la cadena', str_contains(sh([RUN, 'audit-anchor', '--verificar'], null, null, $ancla)[1], 'la cadena es íntegra'));

printf("\nBreak-glass: %d de %d comprobaciones\n", $OK, $OK + $FAIL);
exit($FAIL ? 1 : 0);
