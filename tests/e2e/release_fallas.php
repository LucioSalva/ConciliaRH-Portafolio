<?php
/**
 * Pruebas de RECUPERACIÓN ANTE FALLAS de la Release Candidate (FASE 9 §10; docs/OPERATIONS_RUNBOOK.md §9).
 *
 * Se ejecuta contra un servidor de PRUEBA ya instalado (nunca producción): provoca cada falla con comandos de root en el servidor
 * (--root, p. ej. «docker exec crh-rc» o «ssh root@servidor») y verifica por HTTP que la aplicación falla de forma CONTROLADA
 * (sin trazas, rutas ni datos a medias) y que se recupera al resolver la causa. Cada falla se revierte al terminar.
 * SIEMPRE AL FINAL: deja a propósito un reporte alterado que la salud reporta como ERROR. Si el GOD debe cambiar su contraseña
 * (p. ej. tras break_glass_rc.php, que la restablece), la cambia por una aleatoria y la guarda en --god-pass-file.
 *
 *     php tests/e2e/release_fallas.php --url https://servidor --insecure --root "docker exec crh-rc" \
 *         --god-user god.prueba --god-pass-file /ruta/0600 --lote dist/e2e-lote
 */

declare(strict_types=1);

require __DIR__ . '/lib/Cliente.php';

const FUGAS = ['Stack trace', 'PDOException', '/opt/conciliarh', '/var/lib/conciliarh', 'SQLSTATE', 'Fatal error', 'Warning:'];

function sin_fugas(string $body): bool
{
    foreach (FUGAS as $f) {
        if (str_contains($body, $f)) {
            return false;
        }
    }
    return true;
}

$uso = "Uso: php tests/e2e/release_fallas.php --url URL --root PREFIJO --god-user U --god-pass-file F --lote DIR [--insecure]\n";
$a = opciones(['url' => true, 'root' => true, 'god-user' => true, 'god-pass-file' => true, 'lote' => true, 'insecure' => false], $uso);
$root = shlex_split($a['root']);
$loteDir = rtrim($a['lote'], '/');
$godPass = static fn (): string => trim(leer($a['god-pass-file']));

$sh = static function (string $cmd, bool $checkRc = false) use ($root): array {
    [$rc, $out, $err] = ejecutar([...$root, 'bash', '-c', $cmd]);
    if ($checkRc && $rc !== 0) {
        throw new RuntimeException("$cmd: " . substr($err, 0, 300));
    }
    return [$rc, $out, $err];
};
$sql = static fn (string $q): string => trim($sh("cd /tmp && sudo -u postgres psql -d $(sed -n 's/^DB_DATABASE=//p' /etc/conciliarh/web.env) -XAtc " . escapeshellarg($q))[1]);
$sqlPrueba = static function (string $q) use ($sh): void {
    $sh("cd /tmp && sudo -u postgres psql -v ON_ERROR_STOP=1 -d $(sed -n 's/^DB_DATABASE=//p' /etc/conciliarh/web.env) -XAtc " . escapeshellarg($q), true);
};
$cliente = static fn (): Cliente => new Cliente($a['url'], $a['insecure']);
$god = static function () use ($cliente, $a, $godPass): Cliente {
    $c = $cliente();
    $loc = $c->login($a['god-user'], $godPass());
    if (str_contains($loc, '/cuenta/password') || str_contains($c->get('/')[1]['location'] ?? '', '/cuenta/password')) {
        $nueva = 'Fallas-' . bin2hex(random_bytes(8)) . '-Prueba!';
        $c->get('/cuenta/password');
        $c->post('/cuenta/password', ['password_actual' => $godPass(), 'password_nueva' => $nueva, 'password_confirmacion' => $nueva]);
        file_put_contents($a['god-pass-file'], $nueva . "\n");
        $c = $cliente();
        $c->login($a['god-user'], $nueva);
    }
    return $c;
};
$esperar = static function (callable $cond, int $segundos = 90): bool {
    $fin = time() + $segundos;
    while (time() < $fin) {
        if ($cond()) {
            return true;
        }
        sleep(2);
    }
    return false;
};
$cargar = static function (Cliente $c, string $nombre = '03_DISPERSION_SANTANDER_FICTICIA.xlsx') use ($sql, $loteDir): array {
    $per = $sql("SELECT id FROM periodos_nomina WHERE estado = 'ABIERTO' ORDER BY id LIMIT 1");
    $c->get("/periodos/$per");
    [, $hl] = $c->post("/periodos/$per/lotes", ['nombre' => 'Fallas ' . hrtime(true), 'descripcion' => '', 'origen' => '', 'observaciones' => '']);
    $lote = (string) location_id($hl, '/lotes');
    $tipo = $sql("SELECT id FROM tipos_documento WHERE codigo = 'TESORERIA_DISPERSION'");
    $orig = $sql("SELECT id FROM origenes_administrativos WHERE codigo = 'TESORERIA'");
    $c->get("/importaciones/nueva?lote=$lote");
    [$s, $h] = $c->post('/importaciones', ['lote_id' => $lote, 'tipo_documento_id' => $tipo, 'origen_administrativo_id' => $orig,
                                           'observaciones' => 'falla', 'confirmar_duplicado' => '1'],
        [['archivo', 'FALLA-' . hrtime(true) . '.xlsx', leer("$loteDir/$nombre")]]);
    return [$s, location_id($h, '/importaciones')];
};

if ($sh('test -f /etc/conciliarh/restore.env && test -d /root/gpg-area')[0] !== 0) {
    echo "Falta configurar el respaldo y la restauración (respaldo-servidor.sh y restaurar-servidor.sh) antes de las fallas.\n";
    exit(2);
}
echo "== 1. PostgreSQL no disponible\n";
$sh('systemctl stop postgresql', true);
try {
    $c = $cliente();
    [$s, , $b] = $c->get('/login');
    check(in_array($s, [200, 500, 503], true) && sin_fugas($b), "La página de acceso responde $s sin trazas ni rutas");
    [$s, , $b] = $c->post('/login', ['username' => $a['god-user'], 'password' => $godPass()]);
    check(in_array($s, [403, 500, 503], true) && sin_fugas($b), "Intentar entrar falla de forma controlada ($s), sin trazas");
    [$s2] = $c->get('/api/health');
    check($s2 === 503, "/api/health responde 503 ($s2)");
} finally {
    $sh('systemctl start postgresql', true);
}
check($esperar(static fn () => ($cliente()->api('/api/health')['data']['estado'] ?? '') === 'ok'), 'Se recupera sola al volver PostgreSQL');
check($esperar(static fn () => trim($sh('systemctl is-active conciliarh-worker')[1]) === 'active', 60), 'El worker se recupera (systemd lo reinicia)');

echo "== 2. Worker detenido\n";
$sh('systemctl stop conciliarh-worker', true);
try {
    [$s, $aid] = $cargar($god());
    check($s === 303 && $aid !== null, 'La carga se acepta con el worker detenido');
    sleep(5);
    check($sql("SELECT estado FROM archivos_importados WHERE id = $aid") === 'RECIBIDO', 'El archivo espera (RECIBIDO), no se pierde');
    check(str_contains($sh('conciliarh-run postdeploy')[1], 'worker'), 'La salud lo informa');
} finally {
    $sh('systemctl start conciliarh-worker', true);
}
check($esperar(static fn () => str_starts_with($sql("SELECT estado FROM archivos_importados WHERE id = $aid"), 'ANALIZADO')),
    'Al reiniciar el worker el archivo se procesa solo');

echo "== 3. Scheduler detenido\n";
$sh('systemctl stop conciliarh-scheduler.timer', true);
try {
    $n0 = $sql("SELECT count(*) FROM sistema_ejecuciones WHERE tipo = 'SCHEDULER'");
    sleep(3);
    check(trim($sh('systemctl is-active conciliarh-scheduler.timer')[1]) === 'inactive'
        && $sql("SELECT count(*) FROM sistema_ejecuciones WHERE tipo = 'SCHEDULER'") === $n0, 'Detenido: el timer está inactivo y no hay ejecuciones nuevas');
    check($sh("grep -q '^Persistent=true' /etc/systemd/system/conciliarh-scheduler.timer")[0] === 0, 'El timer es Persistent (ejecuta lo atrasado al volver)');
} finally {
    $sh('systemctl start conciliarh-scheduler.timer && systemctl start conciliarh-scheduler.service', true);
}
check((int) $sql("SELECT count(*) FROM sistema_ejecuciones WHERE tipo = 'SCHEDULER'") > (int) $n0, 'Al reactivarlo vuelve a ejecutar');

echo "== 4. Almacenamiento no escribible\n";
$sh('chmod 0550 /var/lib/conciliarh/imports /var/lib/conciliarh/imports/.staging', true);
try {
    $g = $god();
    $antes = $sql('SELECT count(*) FROM archivos_importados');
    [$s, $aid] = $cargar($g);
    [, , $b] = $g->get('/importaciones/nueva');
    check($aid === null && $sql('SELECT count(*) FROM archivos_importados') === $antes, 'La carga falla sin dejar un registro sin archivo');
    check(sin_fugas($b), 'Sin rutas ni trazas en la respuesta');
} finally {
    $sh('chmod 2750 /var/lib/conciliarh/imports && chmod 0700 /var/lib/conciliarh/imports/.staging', true);
}
[$s, $aid] = $cargar($god());
check($aid !== null, 'Con permisos restaurados la carga vuelve a funcionar');

echo "== 5. Original faltante\n";
$ruta = $sql("SELECT ruta_relativa FROM archivos_importados WHERE estado LIKE 'ANALIZADO%' ORDER BY id LIMIT 1");
$fid = $sql("SELECT id FROM archivos_importados WHERE ruta_relativa = '$ruta'");
$sh("mv /var/lib/conciliarh/$ruta /root/original-apartado", true);
try {
    [$rc, $out] = $sh('conciliarh-run storage-check --hash');
    check($rc !== 0 && str_contains($out, 'ORIGINAL_AUSENTE'), 'La verificación diaria lo detecta (código ≠ 0, ORIGINAL_AUSENTE)');
    $g = $god();
    $g->get("/importaciones/$fid");
    [$s, , $b] = $g->post("/importaciones/$fid/descargar", ['confirmar_macros' => '1']);
    check($s !== 200 || !str_starts_with($b, 'PK'), "La descarga del original se niega ($s)");
    check(sin_fugas($b), 'Sin rutas ni trazas');
} finally {
    $sh("mv /root/original-apartado /var/lib/conciliarh/$ruta", true);
}
check($sh('conciliarh-run storage-check --hash')[0] === 0, 'Restaurado el original, la verificación vuelve a estar limpia');

echo "== 6. Reporte alterado\n";
$g = $god();
$prog = $sql('SELECT id FROM reportes_programados ORDER BY id LIMIT 1');
$g->get("/reportes/programados/$prog");
$g->post("/reportes/programados/$prog/generar", []);
$sh('conciliarh-run scheduler --solo-ejecutar');
$gid = $sql("SELECT id FROM reportes_generados WHERE estado = 'DISPONIBLE' ORDER BY id DESC LIMIT 1");
if ($gid !== '') {
    $ruta = $sql("SELECT ruta FROM reportes_generados WHERE id = $gid");
    $sh("cp /var/lib/conciliarh/reportes/$ruta /root/reporte-respaldo && "
        . "printf 'X' | dd of=/var/lib/conciliarh/reportes/$ruta bs=1 seek=200 conv=notrunc 2>/dev/null", true);
    $g = $god();
    $g->get('/reportes/generados');
    [$s, , $b] = $g->post("/reportes/generados/$gid/descargar", []);
    check($s === 409 && !str_contains($b, 'Diferencia'), "Descarga negada (409) sin entregar contenido ($s)");
    check($sql("SELECT estado FROM reportes_generados WHERE id = $gid") === 'ALTERADO', 'El reporte queda ALTERADO (auditado)');
    check(str_contains($sh('conciliarh-run postdeploy')[1], '✘ reportes'), 'La salud lo marca como ERROR');
} else {
    check(false, 'No hay reporte disponible para la prueba');
}

echo "== 7. Proceso abandonado (worker muerto a mitad de un trabajo)\n";
$sh('systemctl stop conciliarh-worker', true);
try {
    $jid = $sql('SELECT max(id) FROM procesos') ?: '0';
    $sqlPrueba("INSERT INTO procesos (tipo, parametros, estado, bloqueado_por, bloqueado_at, heartbeat_at, intentos, token_bloqueo, iniciado_at) "
        . "VALUES ('DIAGNOSTICO', '{\"eco\": \"huérfano\"}', 'EN_EJECUCION', 'worker-muerto', now() - interval '1 hour', "
        . "now() - interval '1 hour', 1, gen_random_uuid(), now() - interval '1 hour')");
    $jid = $sql("SELECT id FROM procesos WHERE id > $jid AND bloqueado_por = 'worker-muerto' ORDER BY id DESC LIMIT 1");
    check($sql("SELECT estado FROM procesos WHERE id = $jid") === 'EN_EJECUCION', 'El trabajo quedó en ejecución sin dueño');
} finally {
    $sh('systemctl start conciliarh-worker', true);
}
check($esperar(static fn () => $sql("SELECT estado FROM procesos WHERE id = $jid") === 'COMPLETADO', 120), 'El worker recupera el trabajo abandonado y lo termina');

echo "== 8. Respaldo corrupto o sustituido\n";
$sh('systemctl start conciliarh-backup.service');
$R = trim($sh('ls -d /var/backups/conciliarh/conciliarh-* | sort | tail -1')[1]);
$sh("rm -rf /root/corrupto && cp -r $R /root/corrupto && printf 'X' | dd of=/root/corrupto/db.dump.gpg bs=1 seek=500 conv=notrunc 2>/dev/null", true);
$base = 'systemd-run --wait --pipe --collect -p EnvironmentFile=/etc/conciliarh/restore.env -E GNUPGHOME=/root/gpg-area '
    . '-E PGDATABASE=conciliarh_nunca -E CONCILIARH_STORAGE=/root/nunca -E RESTORE_REQUIRE_SIGNATURE=1 /opt/conciliarh/current/scripts/restore.sh';
[$rc, $out, $err] = $sh("$base /root/corrupto");
check($rc === 2 && str_contains($out . $err, 'NO coincide con su SHA256SUMS'), 'Byte alterado: se rechaza por las sumas ANTES de tocar nada');
$sh('cd /root/corrupto && sha256sum db.dump.gpg storage.tar.gpg manifest.json > SHA256SUMS', true);
[$rc, $out, $err] = $sh("$base /root/corrupto");
check($rc === 2 && str_contains($out . $err, 'firma del respaldo NO es válida'), 'Sumas regeneradas por un atacante: la firma no coincide y se rechaza');
check($sh('test ! -e /root/nunca')[0] === 0, 'Nada se creó en el destino');

$ok = Resultados::ok();
printf("\nFallas: %d de %d comprobaciones\n", $ok, Resultados::total());
exit($ok === Resultados::total() ? 0 : 1);
