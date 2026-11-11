<?php

declare(strict_types=1);

/**
 * Rotación de la llave de cifrado de REPORTES generados (docs/REPORT_ENCRYPTION.md §6). SÓLO CLI; nunca desde la web.
 *
 *   php bin/rotate-report-key.php --status                    Reportes disponibles por llave (y los legados en claro que deben cifrarse)
 *   php bin/rotate-report-key.php --dry-run [--to=ID]         Verifica huella y cifrado de cada reporte a procesar, sin escribir
 *   php bin/rotate-report-key.php --to=ID --confirm-backup    Re-cifra con la llave destino (y cifra los legados); reanudable
 *
 * Requisitos: REPORT_ENC_KEYS con la llave anterior Y la destino; destino por omisión = REPORT_ENC_KEY_ID; credenciales del rol
 * propietario (DB_MIGRATE_USERNAME/PASSWORD) sólo al ejecutar el comando. El claro NUNCA se escribe en disco: se descifra y
 * re-cifra por segmentos en memoria. Nunca imprime llaves ni contenido. Un reporte que se está descargando espera su candado.
 */

use ConciliaRH\Core\Config;
use ConciliaRH\Core\Database;
use ConciliaRH\Domain\Reports\ReportCipher;
use ConciliaRH\Domain\Reports\ReportCipherException;
use ConciliaRH\Domain\Reports\ReportService;
use ConciliaRH\Domain\Reports\ScheduledReportService;

$container = (require __DIR__ . '/bootstrap.php')();
$config = $container->get(Config::class);
$opts = getopt('', ['status', 'dry-run', 'to:', 'confirm-backup']);
$fail = static function (string $msg, int $code = 1): never {
    fwrite(STDERR, $msg . "\n");
    exit($code);
};
if ((string) $config->get('db_migrate.password') === '') {
    $fail('Defina DB_MIGRATE_USERNAME y DB_MIGRATE_PASSWORD (rol propietario) sólo para este comando.');
}
$to = (string) ($opts['to'] ?? $config->get('app.report_enc_key_id'));
$cipher = ReportCipher::fromConfig((string) $config->get('app.report_enc_keys'), $to);
if ($cipher === null) {
    $fail('REPORT_ENC_KEYS inválido o sin la llave destino (formato "id:64hex[,id:64hex]").');
}
$storage = rtrim((string) $config->get('storage.path'), '/');
$db = new Database([...$config->get('db'), 'username' => $config->get('db_migrate.username'),
    'password' => $config->get('db_migrate.password'), 'application_name' => 'conciliarh_rotacion_reportes'], (string) $config->get('app.timezone'));
$audit = static function (string $accion, array $contexto) use ($db): void {
    $db->execute("INSERT INTO auditoria (origen, accion, entidad, entidad_id, contexto, hash)
                  VALUES ('CLI', :a, 'reportes_generados', 'rotacion', CAST(:c AS jsonb), repeat('0', 64))",
        ['a' => $accion, 'c' => json_encode($contexto, JSON_THROW_ON_ERROR)]);
};
$path = static function (string $ruta) use ($storage): string {
    if (!preg_match('#^generados/\d{4}/\d{2}/[0-9a-f]{32}\.(xlsx|csv|pdf)(\.enc)?$#', $ruta)) {
        throw new RuntimeException('Ruta de reporte inválida.');
    }
    return $storage . '/reportes/' . $ruta;
};
// Pendientes: cifrados con otra llave, o legados en claro que por su contenido deben cifrarse
$pending = static function () use ($db, $to): array {
    return array_values(array_filter($db->fetchAll(
        "SELECT id, tipo, sensible, cifrado, cifrado_llave, ruta, sha256 FROM reportes_generados WHERE estado = 'DISPONIBLE' ORDER BY id"),
        static fn ($r) => ($r['cifrado'] && $r['cifrado_llave'] !== $to)
            || (!$r['cifrado'] && ReportService::requiresEncryption((string) $r['tipo'], (bool) $r['sensible']))));
};

try {
    fwrite(STDOUT, 'Llave destino: ' . $to . "\n");
    if (isset($opts['status'])) {
        foreach ($db->fetchAll("SELECT coalesce(cifrado_llave, '(en claro)') AS llave, count(*) AS n FROM reportes_generados
                                 WHERE estado = 'DISPONIBLE' GROUP BY 1 ORDER BY 1") as $r) {
            fwrite(STDOUT, sprintf("  %-12s %d reporte(s) disponibles\n", $r['llave'], $r['n']));
        }
        fwrite(STDOUT, '  Por procesar hacia ' . $to . ': ' . count($pending()) . "\n");
        exit(0);
    }
    $dry = isset($opts['dry-run']);
    if (!$dry && !isset($opts['confirm-backup'])) {
        $fail("Antes: respalde la base de datos y storage/reportes (docs/BACKUP_RESTORE.md) y agregue la llave destino a REPORT_ENC_KEYS.\n"
            . 'Después repita con --confirm-backup (o use --dry-run para simular).');
    }
    $ok = 0;
    $errores = [];
    foreach ($pending() as $r) {
        $id = (int) $r['id'];
        $lock = ScheduledReportService::fileLock($id);
        $db->fetchValue('SELECT pg_advisory_lock(:k)', ['k' => $lock]);          // exclusivo: espera a las descargas en curso
        $new = null;
        try {
            $src = $path((string) $r['ruta']);
            if (!is_file($src) || !hash_equals((string) $r['sha256'], (string) hash_file('sha256', $src))) {
                throw new ReportCipherException('HUELLA_NO_COINCIDE');
            }
            if ($r['cifrado']) {
                $cipher->decrypt($src, 'g' . $id);                                  // verificación completa antes de tocar nada
            }
            if ($dry) {
                $ok++;
                continue;
            }
            $ext = preg_replace('/\.enc$/', '', pathinfo((string) $r['ruta'], PATHINFO_BASENAME));
            $ext = substr((string) $ext, strpos((string) $ext, '.') + 1);
            $rel = sprintf('%s/%s.%s.enc', dirname((string) $r['ruta']), bin2hex(random_bytes(16)), $ext);
            $new = $path($rel);
            $r['cifrado'] ? $cipher->reencryptFile($src, $new, 'g' . $id) : $cipher->encryptFile($src, $new, 'g' . $id);
            $db->transaction(static function () use ($db, $id, $rel, $new, $to, $r): void {
                $n = $db->execute("UPDATE reportes_generados SET ruta = :r, sha256 = :s, bytes = :b, cifrado = true, cifrado_llave = :k, cifrado_at = now()
                                    WHERE id = :id AND estado = 'DISPONIBLE' AND ruta = :old",
                    ['r' => $rel, 's' => hash_file('sha256', $new), 'b' => filesize($new), 'k' => $to, 'id' => $id, 'old' => $r['ruta']]);
                if ($n !== 1) {
                    throw new RuntimeException('El reporte cambió durante la rotación.');
                }
            });
            $new = null;
            // Sólo tras confirmar la fila. Si el archivo anterior (sobre todo un legado EN CLARO) no se puede borrar, se informa:
            // nunca se da por procesado un claro que siguió en disco.
            $borrado = @unlink($src) || !is_file($src);
            $audit('reportes.rotacion_llave_reporte', ['reporte_id' => $id, 'destino' => $to, 'era_claro' => !$r['cifrado'], 'anterior_borrado' => $borrado]);
            if (!$borrado) {
                $errores[] = ['id' => $id, 'error' => 'ARCHIVO_ANTERIOR_NO_BORRADO'];
                continue;
            }
            $ok++;
        } catch (Throwable $e) {
            $errores[] = ['id' => $id, 'error' => $e instanceof ReportCipherException ? $e->codigo : ($e instanceof PDOException ? 'SQLSTATE ' . ($e->errorInfo[0] ?? '?') : $e::class)];
        } finally {
            if ($new !== null && is_file($new)) {
                @unlink($new);
            }
            $db->fetchValue('SELECT pg_advisory_unlock(:k)', ['k' => $lock]);
        }
    }
    $audit($dry ? 'reportes.rotacion_llave_simulada' : ($errores === [] ? 'reportes.rotacion_llave_completada' : 'reportes.rotacion_llave_parcial'),
        ['destino' => $to, 'procesados' => $ok, 'errores' => $errores]);
    fwrite(STDOUT, ($dry ? 'Simulación: ' : 'Rotación: ') . $ok . ' reporte(s) correctos, ' . count($errores) . " con error.\n");
    foreach ($errores as $e) {
        fwrite(STDOUT, "  #{$e['id']}: {$e['error']}\n");
    }
    exit($errores === [] ? 0 : 2);
} catch (Throwable $e) {
    $fail('Rotación detenida: ' . ($e instanceof PDOException ? 'SQLSTATE ' . ($e->errorInfo[0] ?? '?') : $e::class), 3);
}
