<?php

declare(strict_types=1);

/**
 * Reconciliación almacenamiento ↔ BD de los archivos importados (sólo lectura por omisión).
 *
 *   php bin/verificar-almacenamiento.php                 reporte
 *   php bin/verificar-almacenamiento.php --hash          además recalcula SHA-256 de cada original
 *   php bin/verificar-almacenamiento.php --cuarentena    mueve huérfanos y staging vencido a storage/cuarentena/
 *
 * Código de salida: 0 sin hallazgos, 2 con hallazgos. Recomendado en cron diario (docs/IMPORTS.md).
 */

use ConciliaRH\Core\Logger;
use ConciliaRH\Domain\Imports\StorageAuditor;

$container = (require __DIR__ . '/bootstrap.php')();
$args = array_slice($argv, 1);
foreach ($args as $a) {
    if (!in_array($a, ['--hash', '--cuarentena'], true)) {
        fwrite(STDERR, "Opción desconocida: $a\n");
        exit(1);
    }
}
$auditor = $container->get(StorageAuditor::class);
$inicio = date(DATE_ATOM);
$r = $auditor->audit(in_array('--hash', $args, true));
// Salud del sistema (FASE 8): sólo conteos, nunca rutas
try {
    $hallazgos = count($r['huerfanos']) + count($r['staging']) + count($r['faltantes']);
    $container->get(\ConciliaRH\Core\Database::class)->fetchValue("SELECT fn_sistema_ejecucion('ALMACENAMIENTO', 'CLI', CAST(:i AS timestamptz), :r, CAST(:d AS jsonb))",
        ['i' => $inicio, 'r' => $hallazgos === 0 ? 'OK' : 'ADVERTENCIA', 'd' => json_encode(['revisados' => $r['revisados'], 'huerfanos' => count($r['huerfanos']),
            'staging' => count($r['staging']), 'faltantes' => count($r['faltantes']), 'hash' => in_array('--hash', $args, true)])]);
} catch (Throwable) {
    fwrite(STDERR, "Aviso: no se pudo registrar la ejecución en la salud del sistema.\n");
}

printf("Registros revisados: %d\n", $r['revisados']);
printf("Archivos huérfanos (sin registro): %d\n", count($r['huerfanos']));
foreach ($r['huerfanos'] as $p) {
    echo "  - $p\n";
}
printf("Staging vencido: %d\n", count($r['staging']));
foreach ($r['staging'] as $p) {
    echo "  - $p\n";
}
printf("Registros con original ausente/alterado: %d\n", count($r['faltantes']));
foreach ($r['faltantes'] as $f) {
    printf("  - archivo #%d: %s\n", $f['id'], $f['problema']);
}

$issues = count($r['huerfanos']) + count($r['staging']) + count($r['faltantes']);
if ($issues > 0) {
    $container->get(Logger::class)->security('Verificación de almacenamiento con hallazgos', [
        'huerfanos' => count($r['huerfanos']), 'staging' => count($r['staging']), 'faltantes' => $r['faltantes'],
    ]);
}
if (in_array('--cuarentena', $args, true) && ($r['huerfanos'] !== [] || $r['staging'] !== [])) {
    $dir = $auditor->quarantine([...$r['huerfanos'], ...$r['staging']]);
    echo "Movidos a cuarentena: $dir\n";
}
exit($issues > 0 ? 2 : 0);
