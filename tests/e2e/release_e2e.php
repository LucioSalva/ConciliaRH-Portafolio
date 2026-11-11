<?php
/**
 * Prueba de extremo a extremo de RELEASE (FASE 9 §6): recorre el sistema completo sobre una instalación real, por HTTP como
 * lo haría una persona, más las tareas de consola de un operador. PHP con ext-curl, sin Composer. Datos FICTICIOS
 * (php tests/e2e/generar-lote-ficticio.php <directorio>).
 *
 *     php tests/e2e/release_e2e.php --url https://servidor --lote dist/e2e-lote \
 *         --god-user god.prueba --god-pass-file /ruta/0600 \
 *         --cli "sudo conciliarh-run" --sql "sudo -u postgres psql -d conciliarh -XAtc"
 *
 *   --cli  prefijo para los comandos de administración (scheduler).        En el servidor de prueba: «docker exec -i crh-rc conciliarh-run»
 *   --sql  prefijo para CONSULTAS DE SÓLO LECTURA de verificación (ids y resultados); nunca se usa para escribir.
 *   --insecure (certificado autofirmado), --prefijo (usuarios; por omisión e2e), --anio (por omisión 2091),
 *   --confirmar-duplicados (los archivos ficticios ya se cargaron antes, p. ej. antes de actualizar).
 *
 * Pasos: GOD → usuarios (dos ADMIN y un CONSULTA; sólo existen GOD, ADMIN y CONSULTA desde la migración 0026) → contraseña
 * temporal → quincena → lote → 6 cargas → verificación y análisis (o elección de perfil) → normalización → asociación manual →
 * conciliación → resultados → incidencia → exportación → reporte programado (cifrado) → notificación → cierre → pantalla de
 * la quincena (espacios de carga por población, cotejo, «Pendientes de identificar» con confirmación en bloque y nuevo
 * cotejo) → fusión con conflictos graves a cuatro ojos (un ADMIN solicita, otro aprueba) y separación. Respaldo y
 * restauración: tests/e2e/servidor-prueba/ (respaldo-servidor.sh y restaurar-servidor.sh).
 * Sale con 0 sólo si todo pasa. Nunca imprime contraseñas.
 */

declare(strict_types=1);

require __DIR__ . '/lib/Cliente.php';

$uso = "Uso: php tests/e2e/release_e2e.php --url URL --lote DIR --god-user U --god-pass-file F --cli PREFIJO --sql PREFIJO [--insecure]"
    . " [--prefijo e2e] [--anio 2091] [--confirmar-duplicados]\n";
$a = opciones(['url' => true, 'lote' => true, 'god-user' => true, 'god-pass-file' => true, 'cli' => true, 'sql' => true, 'insecure' => false,
               'prefijo' => 'e2e', 'anio' => '2091', 'confirmar-duplicados' => false], $uso);
$cli = shlex_split($a['cli']);
$sqlp = shlex_split($a['sql']);
$url = rtrim($a['url'], '/');
$insecure = $a['insecure'];
$loteDir = rtrim($a['lote'], '/');

$sql = static function (string $q) use ($sqlp): string {
    if (!str_starts_with(strtoupper(ltrim($q)), 'SELECT')) {
        throw new LogicException('sólo consultas');
    }
    [$rc, $out, $err] = ejecutar([...$sqlp, $q]);
    if ($rc !== 0) {
        throw new RuntimeException("La consulta de verificación falló: $err");
    }
    return trim($out);
};
$runCli = static fn (string ...$args): array => ejecutar([...$cli, ...$args]);
$nuevo = static fn (): Cliente => new Cliente($url, $insecure);
$esperar = static function (callable $cond, int $segundos, float $pausa = 1.0): bool {
    $fin = microtime(true) + $segundos;
    while (microtime(true) < $fin) {
        if ($cond()) {
            return true;
        }
        usleep((int) ($pausa * 1e6));
    }
    return (bool) $cond();
};
$resultados = static function (int $cid) use ($sql): array {
    $res = [];
    foreach (array_filter(explode("\n", $sql("SELECT e.numero_empleado || '|' || ce.estado FROM conciliacion_empleados ce
            JOIN empleados e ON e.id = ce.empleado_id WHERE ce.conciliacion_id = $cid ORDER BY e.numero_empleado"))) as $l) {
        [$k, $v] = explode('|', $l);
        $res[$k] = $v;
    }
    return $res;
};
$esperarConciliacion = static function (Cliente $c, int $cid): string {
    $estado = '';
    $fin = time() + 300;
    while (time() < $fin) {
        $estado = (string) ($c->api("/api/conciliaciones/$cid")['data']['conciliacion']['estado'] ?? '');
        if (in_array($estado, ['COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS', 'REVISION_MANUAL', 'ERROR'], true)) {
            break;
        }
        sleep(2);
    }
    return $estado;
};
$alertas = static fn (string $html): array => array_slice(array_map(static fn ($x) => trim(strip_tags($x)),
    preg_match_all('#class="[^"]*alert[^"]*"[^>]*>(.*?)</div>#s', $html, $m) ? $m[1] : []), 0, 2);

$man = json_decode(leer("$loteDir/manifiesto.json"), true);
$t0 = microtime(true);
echo "== Instalación y GOD\n";
$god = $nuevo();
check(($god->api('/api/health')['data']['estado'] ?? '') === 'ok', 'La instalación responde (/api/health)');
check(in_array($god->login($a['god-user'], trim(leer($a['god-pass-file']))), ['/', "$url/"], true), 'GOD inicia sesión', true);

echo "== Usuarios\n";
$temp = 'E2e-Temporal-' . bin2hex(random_bytes(6)) . '!';
$final = 'E2e-Definitiva-' . bin2hex(random_bytes(6)) . '!';
$ids = [];
foreach ([["{$a['prefijo']}.admin", 'ADMIN'], ["{$a['prefijo']}.admin2", 'ADMIN'], ["{$a['prefijo']}.consulta", 'CONSULTA']] as [$user, $rol]) {
    [$s, $h] = $god->post('/usuarios', ['username' => $user, 'nombre' => 'Usuario ' . ucfirst(strtolower($rol)) . ' E2E', 'password' => $temp,
                                        'roles[]' => [$rol]]);
    $ids[$user] = location_id($h, '/usuarios');
    check($s === 303 && $ids[$user] !== null, "GOD crea $user ($rol)", true);
}
$sessions = [];
foreach (array_keys($ids) as $user) {
    $c = $nuevo();
    $loc = $c->login($user, $temp);
    [$s, $h] = $c->get('/');
    if (str_contains($h['location'] ?? '', '/cuenta/password') || str_contains($loc, '/cuenta/password')) {
        $c->get('/cuenta/password');
        [$s] = $c->post('/cuenta/password', ['password_actual' => $temp, 'password_nueva' => $final, 'password_confirmacion' => $final]);
        check($s === 303, "$user cambia la contraseña temporal obligatoria");
        $c = $nuevo();
        $loc = $c->login($user, $final);
    }
    [$s] = $c->get('/');
    check(str_ends_with($loc, '/') && $s === 200, "$user entra al tablero", true);
    $sessions[$user] = $c;
}
$an = $sessions["{$a['prefijo']}.admin2"];
$adm = $sessions["{$a['prefijo']}.admin"];
$con = $sessions["{$a['prefijo']}.consulta"];
[$s] = $con->get('/usuarios');
check($s === 403, 'CONSULTA no administra usuarios (403)');
[$s] = $adm->get('/usuarios');
check($s === 403, 'ADMIN tampoco administra usuarios desde la migración 0024 (403)');
[$s] = $adm->get('/auditoria');
check($s === 403, 'ADMIN no consulta la auditoría desde la migración 0026 (403)');
[$s] = $god->get('/auditoria');
check($s === 200, 'GOD sí consulta la auditoría');

echo "== Quincena, lote y cargas\n";
$anio = $a['anio'];
$con->get('/periodos');
[$s] = $con->post('/periodos', ['anio' => $anio, 'mes' => '3', 'quincena' => '1']);
check($s === 403, 'CONSULTA no crea quincenas (sólo ADMIN/GOD): 403');
$adm->get('/periodos/nuevo');
[$s, $h] = $adm->post('/periodos', ['anio' => $anio, 'mes' => '3', 'quincena' => '1', 'tipo' => 'ORDINARIA', 'descripcion' => '',
                                    'fecha_inicio' => "$anio-03-01", 'fecha_fin' => "$anio-03-15"]);
$per = location_id($h, '/periodos');
check($per !== null, 'ADMIN crea la quincena', true);
[$s, $h] = $an->post("/periodos/$per/lotes", ['nombre' => 'Carga E2E', 'descripcion' => '', 'origen' => '', 'observaciones' => '']);
$lote = location_id($h, '/lotes');
check($lote !== null, 'El segundo ADMIN crea el lote', true);
$archivos = [];
foreach ($man['archivos'] as $f) {
    $tipo = $sql("SELECT id FROM tipos_documento WHERE codigo = '{$f['tipo_documento']}'");
    $origen = $sql("SELECT id FROM origenes_administrativos WHERE codigo = '{$f['origen']}'");
    $an->get("/importaciones/nueva?lote=$lote");
    [$s, $h] = $an->post('/importaciones', ['lote_id' => (string) $lote, 'tipo_documento_id' => $tipo, 'origen_administrativo_id' => $origen,
                                            'observaciones' => 'E2E', ...($a['confirmar-duplicados'] ? ['confirmar_duplicado' => '1'] : [])],
                         [['archivo', $f['archivo'], leer("$loteDir/{$f['archivo']}")]]);
    $archivos[$f['archivo']] = location_id($h, '/importaciones');
    if ($archivos[$f['archivo']] === null) {
        $cuerpo = in_array($s, [302, 303], true) ? $an->get($h['location'] ?? '/importaciones')[2] : '';
        echo '    rechazo: ' . $s . ' ' . ($h['location'] ?? '') . ' ' . json_encode($alertas($cuerpo), JSON_UNESCAPED_UNICODE) . "\n";
    }
    check($archivos[$f['archivo']] !== null, "Carga aceptada: {$f['archivo']}", true);
}

echo "== Verificación, análisis y normalización (worker)\n";
$perfiles = ['01_' => 'RRHH_TABULADO', '02_' => 'RRHH_POLIZA_GLOBAL', '03_' => 'TESORERIA_SANTANDER', '04_' => 'TESORERIA_INTERBANCARIA',
             '05_' => 'TESORERIA_PENSION_SANTANDER', '06_' => 'POLIZA_EGRESO_BANCARIA'];
foreach ($archivos as $nombre => $aid) {
    $estado = '';
    $dl = time() + 180;
    while (time() < $dl) {
        $estado = (string) ($an->api("/api/importaciones/$aid")['data']['estado'] ?? '');
        if (in_array($estado, ['ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS', 'REVISION_MANUAL', 'ERROR', 'RECHAZADO'], true)) {
            break;
        }
        sleep(1);
    }
    if ($estado === 'REVISION_MANUAL') {
        $an->get("/importaciones/$aid");
        $an->post("/importaciones/$aid/analisis/perfil", ['perfil' => $perfiles[substr($nombre, 0, 3)], 'motivo' => 'Perfil del lote E2E']);
        $dl = time() + 180;
        while (time() < $dl) {
            $estado = (string) ($an->api("/api/importaciones/$aid")['data']['estado'] ?? '');
            if (in_array($estado, ['ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS', 'ERROR'], true)) {
                break;
            }
            sleep(1);
        }
        check(str_starts_with($estado, 'ANALIZADO'), "$nombre: perfil elegido a mano y analizado ($estado)");
    } else {
        check(str_starts_with($estado, 'ANALIZADO'), "$nombre: verificado y analizado ($estado)");
    }
}
$vigentes = static fn (int $l): string => $sql("SELECT count(*) FROM importaciones_normalizadas WHERE lote_id = $l AND estado = 'VIGENTE'");
$esperar(static fn () => $vigentes($lote) === (string) count($archivos), 240, 2);
check($vigentes($lote) === (string) count($archivos), 'Los 6 archivos quedaron normalizados', true);

echo "== Asociación manual\n";
[$rid, $ver] = explode(':', $sql("SELECT id || ':' || asociacion_version FROM registros_tesoreria WHERE lote_id = $lote AND nombre_original = 'OTRA PERSONA FICTICIA'"));
$emp4 = $sql("SELECT id FROM empleados WHERE numero_empleado = '90004'");
$an->get("/asociaciones/tesoreria/$rid");
[$s] = $an->post("/asociaciones/tesoreria/$rid/asociar", ['empleado_id' => $emp4, 'version' => $ver,
                                                          'motivo' => 'Transferencia identificada por el área (E2E)']);
check($s === 303 && $sql("SELECT asociacion_estado FROM registros_tesoreria WHERE id = $rid") === 'ASOCIADO_MANUAL',
    'ADMIN asocia a mano el pago sin persona');

echo "== Conciliación\n";
$an->get('/conciliaciones');
[$s, $h] = $an->post('/conciliaciones', ['lote_id' => (string) $lote, 'motivo' => 'Conciliación E2E de release']);
$cid = location_id($h, '/conciliaciones');
check($cid !== null, 'ADMIN solicita la conciliación', true);
$estado = $esperarConciliacion($an, $cid);
check(in_array($estado, ['COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS', 'REVISION_MANUAL'], true), "El motor terminó la conciliación ($estado)");
$res = $resultados($cid);
$esperado = ['90001' => 'CORRECTO', '90002' => 'CORRECTO', '90003' => 'PAGO_MENOR', '90004' => 'PAGO_MENOR'];
check($res === $esperado, 'Resultados por persona correctos ' . json_encode($res));
$dif = $sql("SELECT ce.diferencia FROM conciliacion_empleados ce JOIN empleados e ON e.id = ce.empleado_id
              WHERE ce.conciliacion_id = $cid AND e.numero_empleado = '90003'");
check(bccomp($dif, '-500.00', 2) === 0, "Diferencia exacta de 90003 = $dif");
[$s, , $body] = $an->get("/conciliaciones/$cid");
check($s === 200 && str_contains($body, 'Cuadre de n'), 'La pantalla de la conciliación muestra el cuadre');

echo "== Incidencia\n";
$inc = $sql("SELECT id FROM conciliacion_incidencias WHERE conciliacion_id = $cid AND tipo = 'PAGO_MENOR' ORDER BY id LIMIT 1");
$an->get("/conciliaciones/$cid/incidencias");
$an->post("/conciliaciones/$cid/incidencias/$inc", ['estado' => 'RESUELTA', 'motivo' => 'Diferencia aclarada con Tesorería (E2E)',
                                                    'evidencia' => 'Oficio ficticio E2E-001']);
check($sql("SELECT estado FROM conciliacion_incidencias WHERE id = $inc") === 'RESUELTA', 'ADMIN resuelve una incidencia');

echo "== Reportes\n";
$an->get('/reportes');
[$s, $h, $body] = $an->post('/reportes/exportar', ['tipo' => 'diferencias', 'formato' => 'csv', 'conciliacion' => (string) $cid]);
check($s === 200 && str_contains($body, '90003') && str_contains($h['cache-control'] ?? '', 'no-store'), 'Exportación CSV de diferencias');
$an->post('/reportes/programados', ['nombre' => 'Diferencias E2E', 'tipo' => 'diferencias', 'formato' => 'CSV', 'frecuencia' => 'MANUAL',
                                    'hora' => '7', 'retencion_dias' => '30']);
$prog = $sql("SELECT id FROM reportes_programados WHERE nombre = 'Diferencias E2E' ORDER BY id DESC LIMIT 1");
check($prog !== '', 'ADMIN programa un reporte');
$an->get("/reportes/programados/$prog");
$an->post("/reportes/programados/$prog/generar", []);
[$rc] = $runCli('scheduler', '--solo-ejecutar');
check($rc === 0, 'El scheduler (CLI de operador) genera el reporte');
[$gid, $gest, $gcif] = array_pad(explode(':', $sql("SELECT id || ':' || estado || ':' || cifrado FROM reportes_generados WHERE programacion_id = $prog ORDER BY id DESC LIMIT 1")), 3, '');
check($gest === 'DISPONIBLE' && in_array($gcif, ['t', 'true'], true), "Reporte generado y cifrado en reposo ($gest, cifrado=$gcif)");
$ruta = $gid !== '' ? $sql("SELECT ruta FROM reportes_generados WHERE id = $gid") : '';
check(str_ends_with($ruta, '.enc'), 'En disco sólo existe el archivo cifrado (.enc)');
$an->get('/reportes/generados');
[$s, , $body] = $an->post("/reportes/generados/$gid/descargar", []);
check($s === 200 && str_contains($body, '90003'), 'El dueño descarga el reporte descifrado');
[$s] = $con->post("/reportes/generados/$gid/descargar", []);
check(in_array($s, [403, 404], true), 'CONSULTA no descarga el reporte de otro usuario');

echo "== Notificaciones y cierre\n";
$n = $an->api('/api/notificaciones/resumen')['data'] ?? [];
check((bool) array_filter($n['ultimas'] ?? [], static fn ($x) => str_contains((string) ($x['titulo'] ?? ''), 'Cotejo')),
    'La campana avisa de la conciliación terminada');
$adm->get("/conciliaciones/$cid");
$adm->post("/conciliaciones/$cid/cerrar", ['motivo' => 'Cierre de la quincena E2E']);
check($sql("SELECT estado FROM conciliaciones WHERE id = $cid") === 'CERRADA', 'ADMIN cierra la conciliación');
$an->post("/conciliaciones/$cid/incidencias/$inc", ['estado' => 'ABIERTA', 'motivo' => 'Intento tras el cierre', 'evidencia' => '']);
check($sql("SELECT estado FROM conciliacion_incidencias WHERE id = $inc") === 'RESUELTA', 'Cerrada: sus incidencias ya no cambian');
check((int) $sql("SELECT count(*) FROM auditoria WHERE accion IN ('conciliaciones.solicitar', 'conciliaciones.cerrar') AND entidad_id = '$cid'") === 2,
    'La solicitud y el cierre quedaron en la auditoría');

echo "== Pantalla de la quincena: espacios de carga por población\n";
$q = $man['quincena'] ?? null;
if (!is_array($q)) {
    check(false, 'El lote ficticio incluye la quincena por población (regenérelo con generar-lote-ficticio.php)', true);
}
$adm->get('/periodos/nuevo');
[$s, $h] = $adm->post('/periodos', ['anio' => $anio, 'mes' => '3', 'quincena' => '2', 'tipo' => 'ORDINARIA', 'descripcion' => '',
                                    'fecha_inicio' => "$anio-03-16", 'fecha_fin' => "$anio-03-31"]);
$qp = location_id($h, '/periodos');
check($qp !== null, 'ADMIN crea la segunda quincena', true);
[$s, , $body] = $an->get("/periodos/$qp");
check($s === 200 && str_contains($body, 'Archivos de la quincena') && str_contains($body, 'Falta este archivo')
    && str_contains($body, 'Policías') && str_contains($body, 'Bomberos'), 'La quincena muestra sus espacios por población (Policías y Bomberos)');
[$s, $h] = $an->post("/periodos/$qp/archivos", ['columna' => 'PAGOS', 'confirmar_duplicado' => '1'],
    [['archivos[]', '04_INTERBANCARIA_FICTICIA.xls', leer("$loteDir/04_INTERBANCARIA_FICTICIA.xls")]]);
check($s === 303 && $sql("SELECT count(*) FROM lotes_importacion WHERE periodo_id = $qp") === '0',
    'Pagos sin población declarada: se rechazan sin crear el lote interno');
$cargados = 0;
$total = 0;
foreach ($q['espacios'] as $pob => $cols) {
    foreach ($cols as $col => $lista) {
        $files = array_map(static fn ($f) => ['archivos[]', basename($f), leer("$loteDir/$f")], $lista);
        $total += count($files);
        [$s, $h] = $an->post("/periodos/$qp/archivos", ['columna' => $col, 'poblacion' => $pob, 'confirmar_duplicado' => '1'], $files);
        [, , $pag] = $an->get("/periodos/$qp");
        $ok = $s === 303 && ($h['location'] ?? '') !== '' && !array_filter($lista, static fn ($f) => !str_contains($pag, basename($f)));
        $cargados += $ok ? count($files) : 0;
        if (!$ok) {
            echo "    rechazo $pob/$col: $s " . json_encode($alertas($pag), JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
}
$qlote = (int) $sql("SELECT coalesce(max(id), 0) FROM lotes_importacion WHERE periodo_id = $qp");
check($cargados === $total && $qlote > 0 && $sql("SELECT nombre FROM lotes_importacion WHERE id = $qlote") === 'Carga de la quincena',
    "$cargados/$total archivos recibidos en sus espacios; el lote interno «Carga de la quincena» se creó solo", true);
check($sql("SELECT string_agg(DISTINCT coalesce(poblacion, '-'), ',' ORDER BY coalesce(poblacion, '-')) FROM archivos_importados WHERE lote_id = $qlote") === 'BOMBEROS,POLICIA',
    'Cada archivo guarda la población de su espacio (migración 0025)');
$listos = static fn (): string => $sql("SELECT count(*) FROM archivos_importados a JOIN importaciones_normalizadas n ON n.archivo_id = a.id
    AND n.estado = 'VIGENTE' WHERE a.lote_id = $qlote AND a.estado LIKE 'ANALIZADO%'");
$esperar(static fn () => $listos() === (string) $total, 300, 2);
check($listos() === (string) $total, "Los $total archivos de la quincena quedaron leídos («Listo»)", true);
[, , $body] = $an->get("/periodos/$qp");
check(str_contains($body, "/periodos/$qp/cotejar") && !str_contains($body, 'Para cotejar falta'), 'Con todo leído, la quincena ofrece «Cotejar quincena»');

echo "== Cotejo de la quincena y «Pendientes de identificar»\n";
$an->get("/periodos/$qp");
[$s, $h] = $an->post("/periodos/$qp/cotejar", ['motivo' => '']);
$qc1 = location_id($h, '/conciliaciones');
check($qc1 !== null, 'ADMIN coteja la quincena desde su pantalla', true);
$estado = $esperarConciliacion($an, $qc1);
check(in_array($estado, ['COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS', 'REVISION_MANUAL'], true), "El motor terminó el cotejo ($estado)");
$r1 = $resultados($qc1);
check(($r1['90006'] ?? '') !== 'CORRECTO' && ($r1['90005'] ?? '') === 'CORRECTO',
    'Primer cotejo: el pago interbancario sin número de empleado aún no se atribuye ' . json_encode($r1));
[$s, , $body] = $an->get("/periodos/$qp/pendientes");
preg_match('#<form method="post" action="/periodos/' . $qp . '/pendientes/confirmar".*?</form>#s', $body, $fm);
$form = $fm[0] ?? '';
$items = preg_match_all('#name="items\[\]" value="([^"]+)"#', $form, $mm) ? array_map('html_entity_decode', $mm[1]) : [];
$ptoken = preg_match('#name="token" value="([^"]+)"#', $form, $mt) ? html_entity_decode($mt[1]) : '';
$motivo = preg_match('#name="motivo"[^>]*>([^<]*)</textarea>#', $form, $mo) ? html_entity_decode($mo[1]) : '';
check($s === 200 && count($items) === (int) $q['esperado']['exactos'] && $ptoken !== '' && str_contains($body, 'FICTICIO6 APELLIDO6 NOMBRE6')
    && str_contains($body, 'OTRA PERSONA FICTICIA'),
    'Pendientes: ' . count($items) . ' coincidencia exacta (nombre e importe) lista para confirmar; el resto se revisa uno por uno');
$aud0 = (int) $sql("SELECT max(secuencia) FROM auditoria");
[$s] = $con->post("/periodos/$qp/pendientes/confirmar", ['items[]' => $items, 'token' => $ptoken, 'motivo' => $motivo]);
check($s === 403, 'CONSULTA no confirma en bloque (403)');
[$s, $h] = $an->post("/periodos/$qp/pendientes/confirmar", ['items[]' => $items, 'token' => $ptoken, 'motivo' => $motivo]);
$estado6 = $sql("SELECT r.asociacion_estado || ':' || coalesce(e.numero_empleado, '') FROM registros_tesoreria r LEFT JOIN empleados e ON e.id = r.empleado_id
                  WHERE r.lote_id = $qlote AND r.nombre_original = 'FICTICIO6 APELLIDO6 NOMBRE6'");
check($s === 303 && str_starts_with($estado6, 'ASOCIADO') && str_ends_with($estado6, ':90006'), "ADMIN confirma en bloque el pago exacto ($estado6)");
check((int) $sql("SELECT count(*) FROM auditoria WHERE accion = 'asociaciones.masivo' AND secuencia > $aud0") === 1,
    'La confirmación en bloque quedó en la auditoría');
[, , $body] = $an->get("/periodos/$qp");
[$s, $h] = $an->post("/periodos/$qp/cotejar", ['motivo' => 'Nuevo cotejo tras confirmar pendientes (E2E)']);
$qc2 = location_id($h, '/conciliaciones');
check($qc2 !== null && $qc2 !== $qc1, '«Volver a cotejar» con motivo crea un cotejo nuevo', true);
$estado = $esperarConciliacion($an, $qc2);
$r2 = $resultados($qc2);
$sub = array_intersect_key($r2, array_filter($q['esperado'], static fn ($k) => ctype_digit((string) $k), ARRAY_FILTER_USE_KEY));
ksort($sub);
$esp = array_filter($q['esperado'], static fn ($k) => ctype_digit((string) $k), ARRAY_FILTER_USE_KEY);
ksort($esp);
check(in_array($estado, ['COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS', 'REVISION_MANUAL'], true) && $sub === $esp,
    "Segundo cotejo ($estado): el pago confirmado cuenta para su persona " . json_encode($r2));
[$s, , $body] = $an->get("/periodos/$qp");
check($s === 200 && str_contains($body, 'personas pagadas correctamente'), 'La quincena muestra el resultado del último cotejo');
[$s, , $body] = $an->get("/conciliaciones/$qc2/pensiones");
check($s === 200 && str_contains($body, 'Policías'), 'Pensiones por población del cotejo');

echo "== Fusión con conflictos graves: cuatro ojos entre dos ADMIN\n";
$fz = $man['fusion'] ?? null;
if (!is_array($fz)) {
    check(false, 'El lote ficticio incluye el par a fusionar (regenérelo con generar-lote-ficticio.php)', true);
}
$flotes = [];
$listos = true;
foreach ([0, 1] as $g) {
    $an->get("/periodos/$per");
    [, $h] = $an->post("/periodos/$per/lotes", ['nombre' => "Fusión E2E $g", 'descripcion' => '', 'origen' => '', 'observaciones' => '']);
    $flotes[$g] = $fl = location_id($h, '/lotes');
    $grupo = array_values(array_filter($fz['archivos'], static fn ($x) => $x[3] === $g));
    foreach ($grupo as [$archivo, $tdoc, $odoc]) {
        $an->get("/importaciones/nueva?lote=$fl");
        $an->post('/importaciones', ['lote_id' => (string) $fl, 'tipo_documento_id' => $sql("SELECT id FROM tipos_documento WHERE codigo = '$tdoc'"),
                                     'origen_administrativo_id' => $sql("SELECT id FROM origenes_administrativos WHERE codigo = '$odoc'"),
                                     'observaciones' => 'E2E fusión', 'confirmar_duplicado' => '1'], [['archivo', basename($archivo), leer("$loteDir/$archivo")]]);
    }
    $nf = (string) count($grupo);
    $listos = $listos && $fl !== null && $esperar(static fn () => $vigentes($fl) === $nf, 240, 2);
}
$e1 = $sql("SELECT id FROM empleados WHERE numero_empleado = '{$fz['origen']}'");
$e2 = $sql("SELECT id FROM empleados WHERE numero_empleado = '{$fz['destino']}'");
check($listos && $e1 !== '' && $e2 !== '',
    "Dos personas ({$fz['origen']} y {$fz['destino']}) con pagos a la misma cuenta en dos lotes: archivos normalizados", true);
[$s, , $body] = $adm->get("/empleados/fusion?origen=$e1&destino=$e2");
$huella = preg_match('#name="huella" value="([0-9a-f]{64})"#', $body, $mh) ? $mh[1] : '';
$graves = preg_match_all('#name="aceptados\[\]" value="([^"]+)"#', $body, $mg) ? array_map('html_entity_decode', $mg[1]) : [];
check($s === 200 && $huella !== '' && $graves !== [] && str_contains($body, 'Requiere una segunda persona'),
    'ADMIN previsualiza una fusión con conflictos graves (' . implode(', ', $graves) . ')', true);
[$s, $h] = $adm->post('/empleados/fusion', ['origen' => $e1, 'destino' => $e2, 'huella' => $huella, 'aceptados[]' => $graves,
                                            'motivo' => 'Prueba E2E de fusión a cuatro ojos con conflictos graves aceptados']);
$sol = location_id($h, '/empleados/solicitudes-fusion');
$estadoSol = static fn (): string => $sol === null ? '' : $sql("SELECT estado FROM solicitudes_fusion WHERE id = $sol");
check($sol !== null && $estadoSol() === 'PENDIENTE_APROBACION' && $sql("SELECT fusionado_en_id IS NULL FROM empleados WHERE id = $e1") === 't',
    'Con conflictos graves se crea una solicitud y la fusión aún no se aplica', true);
[, , $body] = $adm->get("/empleados/solicitudes-fusion/$sol");
check(!str_contains($body, "/empleados/solicitudes-fusion/$sol/aprobar"), 'Quien solicitó no ve el botón para aprobar su propia solicitud');
[$s] = $adm->post("/empleados/solicitudes-fusion/$sol/aprobar", ['motivo' => 'Intento de aprobar la propia solicitud']);
check($estadoSol() === 'PENDIENTE_APROBACION', "Quien solicitó no puede aprobarla aunque envíe el formulario ($s)");
$con->get("/empleados/solicitudes-fusion/$sol");
[$s] = $con->post("/empleados/solicitudes-fusion/$sol/aprobar", ['motivo' => 'CONSULTA intenta aprobar']);
check(in_array($s, [403, 404], true) && $estadoSol() === 'PENDIENTE_APROBACION', "CONSULTA no aprueba fusiones ($s)");
[, , $body] = $an->get("/empleados/solicitudes-fusion/$sol");
check(str_contains($body, "/empleados/solicitudes-fusion/$sol/aprobar"), 'El segundo ADMIN ve la solicitud pendiente con el botón para aprobar');
[$s, $h] = $an->post("/empleados/solicitudes-fusion/$sol/aprobar", ['motivo' => 'Segunda persona revisó la evidencia (E2E)']);
$fus = location_id($h, '/empleados/fusiones');
check($fus !== null && $estadoSol() === 'EJECUTADA' && $sql("SELECT fusionado_en_id FROM empleados WHERE id = $e1") === $e2,
    'El segundo ADMIN aprueba: la fusión se aplica con doble firma', true);
check($sql("SELECT (contexto->>'solicitado_por') || ':' || (contexto->>'aprobado_por') FROM auditoria WHERE accion = 'empleados.fusion_aprobar'
             AND entidad_id = '$sol' ORDER BY secuencia DESC LIMIT 1") === $ids["{$a['prefijo']}.admin"] . ':' . $ids["{$a['prefijo']}.admin2"],
    'La auditoría registra a quien solicitó y a quien aprobó (personas distintas)');
[, , $body] = $adm->get("/empleados/fusiones/$fus");
check(str_contains($body, "/empleados/fusiones/$fus/separar"), 'ADMIN puede separar la fusión (migración 0026)');
[$s] = $adm->post("/empleados/fusiones/$fus/separar", ['motivo' => 'Separación correctiva de la prueba E2E de cuatro ojos']);
check($sql("SELECT estado FROM fusiones_empleados WHERE id = $fus") === 'SEPARADA' && $sql("SELECT fusionado_en_id IS NULL FROM empleados WHERE id = $e1") === 't',
    "ADMIN separa la fusión y el origen vuelve a ser independiente ($s)");

$ok = Resultados::ok();
printf("\nE2E: %d de %d comprobaciones en %.0f s\n", $ok, Resultados::total(), microtime(true) - $t0);
echo json_encode(['periodo' => $per, 'lote' => $lote, 'conciliacion' => $cid, 'reporte_generado' => $gid, 'quincena' => $qp,
                  'cotejos' => [$qc1, $qc2]]) . "\n";
exit($ok === Resultados::total() ? 0 : 1);
