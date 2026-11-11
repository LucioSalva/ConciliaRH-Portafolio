<?php

declare(strict_types=1);

/**
 * Datos de DEMOSTRACIÓN para desarrollo (FASE 8). NUNCA en producción: exige APP_ENV=development y credenciales del propietario.
 * Todo es FICTICIO y parte de los datos que ya dejaron make seed-dev + make smoke (fixtures). Idempotente.
 *
 *   make demo-dev
 *
 * Qué agrega (sólo si falta), para que todas las pantallas de la FASE 8 tengan casos que mostrar:
 *  - pensiones sin trabajador convertidas en SUGERENCIA con evidencia MEDIA (marcadas "demo": true en la evidencia);
 *  - un par de posibles duplicados con evidencia FUERTE y conflicto GRAVE, y una solicitud de fusión pendiente de dev.god (la aprueba dev.admin);
 *  - notificaciones de muestra para cada cuenta dev.*;
 *  - programaciones de reportes (agregado en claro y por persona cifrado) listas para `make scheduler`.
 */

use ConciliaRH\Core\Config;
use ConciliaRH\Core\Database;

$container = (require __DIR__ . '/bootstrap.php')();
$config = $container->get(Config::class);
if ($config->get('app.env') !== 'development') {
    fwrite(STDERR, "RECHAZADO: dev-demo sólo se ejecuta con APP_ENV=development.\n");
    exit(1);
}
if ((string) $config->get('db_migrate.password') === '') {
    fwrite(STDERR, "Requiere DB_MIGRATE_USERNAME/DB_MIGRATE_PASSWORD (use make demo-dev).\n");
    exit(1);
}
$db = new Database([...$config->get('db'), 'username' => $config->get('db_migrate.username'), 'password' => $config->get('db_migrate.password'),
    'application_name' => 'conciliarh_dev_demo'], (string) $config->get('app.timezone'));
$say = static fn (string $m) => fwrite(STDOUT, "  $m\n");
$uid = static fn (string $u): ?int => ($v = $db->fetchValue('SELECT id FROM usuarios WHERE username = :u AND es_desarrollo', ['u' => $u])) === null ? null : (int) $v;
if ($uid('dev.god') === null) {
    fwrite(STDERR, "Ejecute primero make seed-dev (faltan las cuentas dev.*).\n");
    exit(1);
}

$db->transaction(function () use ($db, $uid, $say): void {
    $db->execute("SET LOCAL conciliarh.identidad_mantenimiento = 'on'");
    // 2. Pensiones MEDIA: pagos de pensión sin trabajador en periodos abiertos → sugerencia con dos señales (demo)
    $pend = $db->fetchAll("SELECT r.id, r.lote_id FROM registros_tesoreria r JOIN periodos_nomina p ON p.id = r.periodo_id AND p.estado = 'ABIERTO'
                             JOIN importaciones_normalizadas n ON n.id = r.importacion_id AND n.estado = 'VIGENTE'
                            WHERE r.categoria = 'PENSION' AND r.asociacion_estado IN ('NO_IDENTIFICADO', 'SUGERENCIA') AND r.empleado_id IS NULL
                              AND r.cuenta_hmac IS NOT NULL AND coalesce(r.asociacion_evidencia->>'categoria', '') <> 'MEDIA' LIMIT 20");
    $n = 0;
    foreach ($pend as $r) {
        $cand = $db->fetchValue("SELECT e.id FROM registros_rrhh rr JOIN empleados e ON e.id = rr.empleado_id AND e.fusionado_en_id IS NULL
                                  WHERE rr.lote_id = :l ORDER BY e.id LIMIT 1 OFFSET :o", ['l' => $r['lote_id'], 'o' => $n % 3]);
        if ($cand === null) {
            continue;
        }
        $db->execute("UPDATE registros_tesoreria SET asociacion_estado = 'SUGERENCIA', asociacion_confianza = 'MEDIA', asociacion_version = asociacion_version + 1,
                             asociacion_evidencia = CAST(:ev AS jsonb) WHERE id = :id",
            ['id' => $r['id'], 'ev' => json_encode(['motivo' => 'IMPORTE_RECURRENTE_MISMA_CLABE', 'candidatos' => [(int) $cand], 'fuertes' => [],
                'senales' => ['IMPORTE_RETENCION_UNICA', 'IMPORTE_RECURRENTE_MISMA_CLABE', 'MISMO_NOMBRE_CON_ESA_CLABE'], 'categoria' => 'MEDIA',
                'detalle_senales' => ['clabe_otros_periodos' => 2], 'demo' => true])]);
        $n++;
    }
    $say("✔ $n pensión(es) con evidencia MEDIA (demo)");

    // 3. Duplicado con conflicto grave + solicitud pendiente de segunda firma (dev.god solicita)
    // Idempotente: sólo la primera vez (no vuelve a corregir el RFC de otro empleado en cada ejecución)
    if ((int) $db->fetchValue("SELECT count(*) FROM solicitudes_fusion WHERE motivo LIKE '%demostración%'") === 0) {
        $par = $db->fetchOne("SELECT a.empleado_id AS a, b.empleado_id AS b FROM registros_rrhh a JOIN registros_rrhh b ON b.lote_id = a.lote_id AND b.empleado_id > a.empleado_id
                               JOIN empleados ea ON ea.id = a.empleado_id AND ea.fusionado_en_id IS NULL AND ea.rfc IS NOT NULL
                               JOIN empleados eb ON eb.id = b.empleado_id AND eb.fusionado_en_id IS NULL
                              WHERE NOT EXISTS (SELECT 1 FROM empleados x WHERE x.fusionado_en_id IN (a.empleado_id, b.empleado_id))
                              ORDER BY a.empleado_id DESC LIMIT 1");
        if ($par !== null) {
            $rfc = $db->fetchValue('SELECT rfc FROM empleados WHERE id = :a', ['a' => $par['a']]);
            $db->execute('UPDATE empleados SET rfc = :r WHERE id = :b', ['r' => $rfc, 'b' => $par['b']]);
            $db->execute("INSERT INTO empleado_historial (empleado_id, campo, valor_anterior, valor_nuevo, fuente, usuario_id, motivo)
                          VALUES (:b, 'rfc', NULL, '[demo]', 'MANUAL', :u, 'Corrección ficticia para la demostración de fusiones')", ['b' => $par['b'], 'u' => $uid('dev.admin')]);
            $an = (string) $db->fetchValue('SELECT fn_fusion_analisis(:o, :d)', ['o' => $par['b'], 'd' => $par['a']]);
            $graves = array_values(array_map(static fn ($c) => $c['codigo'], array_filter(json_decode($an, true)['conflictos'], static fn ($c) => $c['severidad'] === 'GRAVE')));
            $db->execute("INSERT INTO duplicados_empleados (empleado_a, empleado_b, confianza, evidencias) VALUES (:a, :b, 'ALTA', '[{\"codigo\": \"MISMO_RFC\", \"fuerza\": \"FUERTE\"}]')
                          ON CONFLICT DO NOTHING", ['a' => min($par['a'], $par['b']), 'b' => max($par['a'], $par['b'])]);
            if ($graves !== [] && json_decode($an, true)['fusionable']) {
                $db->execute("INSERT INTO solicitudes_fusion (origen_id, destino_id, motivo, conflictos_graves, analisis, huella, solicitado_por, expira_at)
                              VALUES (:o, :d, 'Misma persona según expediente ficticio de demostración', CAST(:g AS text[]), CAST(:an AS jsonb),
                                      fn_fusion_huella(CAST(:an2 AS jsonb)), :u, now() + interval '48 hours')",
                    ['o' => $par['b'], 'd' => $par['a'], 'g' => '{' . implode(',', $graves) . '}', 'an' => $an, 'an2' => $an, 'u' => $uid('dev.god')]);
                $say('✔ solicitud de fusión pendiente de segunda firma (' . implode(', ', $graves) . ')');
            } else {
                $say('· par de posibles duplicados creado (sin conflicto grave: no requiere doble firma)');
            }
        }
    }

    // 4. Notificaciones de muestra (sin datos personales)
    foreach (['dev.god', 'dev.admin', 'dev.consulta'] as $u) {
        if (($id = $uid($u)) === null) {
            continue;
        }
        $db->fetchValue("SELECT fn_notificar(:u, 'SISTEMA', 'NORMAL', 'Bienvenida al centro de notificaciones',
                           'Aquí verá avisos de conciliaciones, reportes, importaciones y fusiones. Datos de demostración.', '/notificaciones', NULL, NULL, :c)",
            ['u' => $id, 'c' => 'demo:' . $id]);
    }
    $db->fetchValue("SELECT fn_notificar_permiso('empleados.fusionar_conflictos_aprobar', NULL, 'FUSION_PENDIENTE_APROBACION', 'ALTA',
                       'Solicitud de fusión pendiente de aprobación', 'Una solicitud de demostración espera la segunda firma.', '/empleados/solicitudes-fusion', NULL, NULL, 'demo:0')");
    $say('✔ notificaciones de muestra');

    // 5. Programaciones listas para `make scheduler` (un agregado en claro y uno por persona, cifrado)
    $ana = $uid('dev.admin');
    foreach ([['Resumen quincenal (demo)', 'resumen', 'PDF'], ['Diferencias por persona (demo)', 'diferencias', 'XLSX']] as [$nombre, $tipo, $formato]) {
        if ($db->fetchValue('SELECT 1 FROM reportes_programados WHERE nombre = :n', ['n' => $nombre]) === null) {
            $db->execute("INSERT INTO reportes_programados (nombre, tipo, formato, frecuencia, hora, alcance, sensible, retencion_dias, creado_por, actualizado_por,
                                  proxima_ejecucion_at)
                          VALUES (:n, :t, :f, 'QUINCENAL', 7, 'RECIENTE', false, 30, :u, :u2, now() - interval '1 minute')",
                ['n' => $nombre, 't' => $tipo, 'f' => $formato, 'u' => $ana, 'u2' => $ana]);
        }
    }
    $say('✔ programaciones de reportes (ejecute make scheduler para generarlas)');
});
fwrite(STDOUT, "Datos de demostración listos (todos ficticios).\n");
