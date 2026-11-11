<?php
/**
 * Línea base de rendimiento y prueba de carga RAZONABLE de la Release Candidate (FASE 9 §59–61). Datos ficticios.
 *
 *     php tests/e2e/carga_rc.php --url https://servidor --insecure --usuarios usuario:archivo_pass,... --lote dist/e2e-lote \
 *         --sql "docker exec crh-rc sudo -u postgres psql -d conciliarh -XAtc" [--segundos 60] [--concurrencia 20]
 *
 * Mide: login, tablero, listas y búsquedas (latencia p50/p95/máx), exportaciones, cargas simultáneas y su procesamiento,
 * y el máximo de conexiones del rol web en PostgreSQL durante la carga. La concurrencia usa curl_multi (requiere ext-curl);
 * la vigilancia de PostgreSQL corre en un proceso hijo (ext-pcntl; sin ella no se mide el pico). No es una prueba de escala
 * extrema: simula el uso esperado (decenas de usuarios internos).
 */

declare(strict_types=1);

require __DIR__ . '/lib/Cliente.php';

$uso = "Uso: php tests/e2e/carga_rc.php --url URL --usuarios u:pass[,…] --lote DIR --sql PREFIJO [--segundos 60] [--concurrencia 20] [--insecure]\n";
$a = opciones(['url' => true, 'usuarios' => true, 'lote' => true, 'sql' => true, 'segundos' => '60', 'concurrencia' => '20', 'insecure' => false], $uso);
if (!function_exists('curl_multi_init')) {
    fwrite(STDERR, "Se requiere ext-curl.\n");
    exit(2);
}
$sqlp = shlex_split($a['sql']);
$loteDir = rtrim($a['lote'], '/');
$segundos = (int) $a['segundos'];
$concurrencia = (int) $a['concurrencia'];
$sql = static function (string $q) use ($sqlp): string {
    [$rc, $out, $err] = ejecutar([...$sqlp, $q]);
    if ($rc !== 0) {
        throw new RuntimeException("La consulta falló: $err");
    }
    return trim($out);
};
$cliente = static fn (): Cliente => new Cliente($a['url'], $a['insecure']);
$creds = array_map(static function (string $u): array {
    [$user, $ruta] = explode(':', $u, 2);
    return [$user, trim(leer($ruta))];
}, explode(',', $a['usuarios']));

/** @param list<float> $xs */
function pct(array $xs, float $p): float
{
    if ($xs === []) {
        return 0.0;
    }
    sort($xs);
    return $xs[min(count($xs) - 1, (int) round($p / 100 * (count($xs) - 1)))];
}

/** @param list<float> $xs */
function mediana(array $xs): float
{
    sort($xs);
    $n = count($xs);
    return $n === 0 ? 0.0 : ($n % 2 ? $xs[intdiv($n, 2)] : ($xs[$n / 2 - 1] + $xs[$n / 2]) / 2);
}

$lat = [];
$errores = [];
$medir = static function (string $nombre, callable $fn) use (&$lat) {
    $t = hrtime(true);
    $r = $fn();
    $lat[$nombre][] = (hrtime(true) - $t) / 1e6;
    return $r;
};

echo "== Línea base (1 usuario)\n";
[$u, $p] = $creds[0];
$c = $cliente();
for ($i = 0; $i < 3; $i++) {
    $c2 = $cliente();
    $medir('login', static fn () => $c2->login($u, $p));
}
$c->login($u, $p);
$conc = $sql('SELECT max(id) FROM conciliaciones');
$per = $sql("SELECT min(id) FROM periodos_nomina WHERE estado = 'ABIERTO'");
for ($i = 0; $i < 10; $i++) {
    foreach ([['tablero', '/'], ['periodos', '/periodos'], ['quincena', "/periodos/$per"], ['conciliacion', "/conciliaciones/$conc"],
              ['personas', "/conciliaciones/$conc/empleados"], ['busqueda_empleados', '/empleados?q=FICTICIO'], ['auditoria', '/auditoria'],
              ['revision_masiva', '/revision-masiva'], ['salud_api', '/api/health']] as [$nombre, $ruta]) {
        [$s] = $medir($nombre, static fn () => $c->get($ruta));
        if ($s >= 500 || $s === 0) {
            $errores[] = "$ruta: $s";
        }
    }
}
$c->get('/reportes');
for ($i = 0; $i < 3; $i++) {
    $medir('export_resumen_pdf', static fn () => $c->post('/reportes/exportar', ['tipo' => 'resumen', 'formato' => 'pdf', 'conciliacion' => $conc]));
    $medir('export_empleados_xlsx', static fn () => $c->post('/reportes/exportar', ['tipo' => 'empleados', 'formato' => 'xlsx', 'conciliacion' => $conc]));
    sleep(7);
}
ksort($lat);
foreach ($lat as $k => $v) {
    printf("   %-24s p50 %7.1f ms   máx %7.1f ms   (n=%d)\n", $k, mediana($v), max($v), count($v));
}
$lat = [];

echo "== Carga: $concurrencia usuarios concurrentes durante $segundos s\n";
$rutas = ['/', '/periodos', "/periodos/$per", "/conciliaciones/$conc", "/conciliaciones/$conc/empleados?page=1", '/empleados?q=FICT',
          '/auditoria?accion=auth', '/notificaciones', '/api/notificaciones/resumen', '/revision-masiva?q=PERSONA'];
$navegantes = [];
for ($i = 0; $i < $concurrencia; $i++) {
    [$u, $p] = $creds[$i % count($creds)];
    $n = $cliente();
    if ($n->login($u, $p) === '') {
        $errores[] = "login $u";
        continue;
    }
    $navegantes[] = ['c' => $n, 'k' => $i];
}
$tipo = $sql("SELECT id FROM tipos_documento WHERE codigo = 'TESORERIA_DISPERSION'");
$orig = $sql("SELECT id FROM origenes_administrativos WHERE codigo = 'TESORERIA'");
$cargadores = [];
for ($j = 0; $j < 5; $j++) {
    [$u, $p] = $creds[$j % count($creds)];
    $n = $cliente();
    $n->login($u, $p);
    $n->get("/periodos/$per");
    [, $h] = $n->post("/periodos/$per/lotes", ['nombre' => "Carga concurrente $j " . time(), 'descripcion' => '', 'origen' => '', 'observaciones' => '']);
    $lote = location_id($h, '/lotes');
    $n->get("/importaciones/nueva?lote=$lote");
    $cargadores[] = ['c' => $n, 'j' => $j, 'cuerpo' => $n->cuerpo('/importaciones', ['lote_id' => (string) $lote, 'tipo_documento_id' => $tipo,
        'origen_administrativo_id' => $orig, 'observaciones' => 'carga', 'confirmar_duplicado' => '1'],
        [['archivo', "CONC-$j.xlsx", leer("$loteDir/03_DISPERSION_SANTANDER_FICTICIA.xlsx")]])];
}

$pico = 'no medido (sin pcntl)';
$archivoPico = tempnam(sys_get_temp_dir(), 'crh-pico');
$archivoAlto = $archivoPico . '.alto';
$vigia = function_exists('pcntl_fork') ? pcntl_fork() : -1;
if ($vigia === 0) {
    $max = 0;
    while (!is_file($archivoAlto)) {
        try {
            $max = max($max, (int) $sql("SELECT count(*) FROM pg_stat_activity WHERE usename = 'conciliarh_web'"));
            file_put_contents($archivoPico, (string) $max);
        } catch (Throwable) {
        }
        usleep(500000);
    }
    exit(0);
}

$mh = curl_multi_init();
$vuelo = [];
$fin = microtime(true) + $segundos;
$lanzar = static function (array $ag, string $tipo, string $metodo, string $ruta, ?array $cuerpo = null) use ($mh, &$vuelo): void {
    $ch = $cuerpo === null ? $ag['c']->handle($metodo, $ruta) : $ag['c']->handle($metodo, $ruta, $cuerpo[1], ['Content-Type' => $cuerpo[0]]);
    curl_multi_add_handle($mh, $ch);
    $vuelo[spl_object_id($ch)] = ['ag' => $ag, 'tipo' => $tipo, 'ruta' => $ruta, 't' => hrtime(true), 'ch' => $ch];
};
$t0 = microtime(true);
foreach ($navegantes as $i => $ag) {
    $navegantes[$i]['k']++;
    $lanzar($navegantes[$i], 'nav', 'GET', $rutas[$ag['k'] % count($rutas)]);
}
foreach ($cargadores as $ag) {
    $lanzar($ag, 'carga', 'POST', '/importaciones', $ag['cuerpo']);
}
$archivosCargados = [];
do {
    curl_multi_exec($mh, $activos);
    curl_multi_select($mh, 0.05);
    while (($info = curl_multi_info_read($mh)) !== false) {
        $ch = $info['handle'];
        $v = $vuelo[spl_object_id($ch)];
        unset($vuelo[spl_object_id($ch)]);
        $dt = (hrtime(true) - $v['t']) / 1e6;
        [$s, $h] = $v['ag']['c']->terminar($ch, (string) curl_multi_getcontent($ch));
        curl_multi_remove_handle($mh, $ch);
        if ($v['tipo'] === 'nav') {
            $lat['carga_get'][] = $dt;
            if ($s >= 500 || $s === 0) {
                $errores[] = "{$v['ruta']}: $s";
            }
            if (microtime(true) < $fin) {
                $ag = $v['ag'];
                $ag['k']++;
                $lanzar($ag, 'nav', 'GET', $rutas[$ag['k'] % count($rutas)]);
            }
        } else {
            $lat['carga_upload'][] = $dt;
            $aid = location_id($h, '/importaciones');
            if ($aid !== null) {
                $archivosCargados[] = $aid;
            } else {
                $errores[] = "carga {$v['ag']['j']}: $s";
            }
        }
    }
} while ($vuelo !== []);
curl_multi_close($mh);
$dur = microtime(true) - $t0;
if ($vigia > 0) {
    touch($archivoAlto);
    pcntl_waitpid($vigia, $st);
    $pico = (string) (int) @file_get_contents($archivoPico);
}
@unlink($archivoPico);
@unlink($archivoAlto);
$g = $lat['carga_get'] ?? [];
printf("   peticiones: %d en %.0f s (%.1f/s)   p50 %.0f ms   p95 %.0f ms   máx %.0f ms\n", count($g), $dur, count($g) / $dur, pct($g, 50), pct($g, 95),
    $g !== [] ? max($g) : 0);
$up = $lat['carga_upload'] ?? [];
printf("   cargas simultáneas: %d/5 aceptadas   p50 %.0f ms   máx %.0f ms\n", count($archivosCargados), pct($up, 50), $up !== [] ? max($up) : 0);
$lista = implode(',', $archivosCargados !== [] ? $archivosCargados : [0]);
$analizados = static fn (): string => $sql("SELECT count(*) FROM archivos_importados WHERE id IN ($lista) AND estado LIKE 'ANALIZADO%'");
$t1 = microtime(true);
while (microtime(true) - $t1 < 180 && $archivosCargados !== [] && $analizados() !== (string) count($archivosCargados)) {
    sleep(2);
}
$proc = microtime(true) - $t1;
$listos = $analizados();
printf("   procesamiento de las cargas por el worker: %s/%d analizadas en %.0f s tras la carga\n", $listos, count($archivosCargados), $proc);
echo "   conexiones simultáneas del rol web (pico): $pico\n";
echo '   errores: ' . count($errores) . ' ' . json_encode(array_slice($errores, 0, 5), JSON_UNESCAPED_UNICODE) . "\n";
$ok = $errores === [] && count($archivosCargados) === 5 && $listos === '5' && pct($g, 95) < 2000;
echo 'RESULTADO: ' . ($ok ? 'OK' : 'REVISAR') . "\n";
exit($ok ? 0 : 1);
