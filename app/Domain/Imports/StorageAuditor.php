<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Imports;

use ConciliaRH\Core\Database;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Reconciliación almacenamiento ↔ base de datos (cierra la ventana no transaccional).
 *  - huerfanos:   archivos bajo imports/ sin registro (caída entre rename y COMMIT).
 *  - staging:     restos en imports/.staging con antigüedad mayor a la indicada.
 *  - faltantes:   registros vigentes cuyo original no existe, cambió de tamaño o de SHA-256.
 * Nunca borra: en modo cuarentena MUEVE huérfanos y staging a storage/cuarentena/{fecha}/.
 * El reporte contiene sólo rutas internas (ids/uuid) y ids; ningún nombre de archivo ni contenido.
 */
final class StorageAuditor
{
    public function __construct(private readonly Database $db, private readonly ImportStorage $storage)
    {
    }

    /** @return array{huerfanos:list<string>,staging:list<string>,faltantes:list<array{id:int,problema:string}>,revisados:int} */
    public function audit(bool $verifyHash = false, int $stagingMaxAgeSeconds = 3600, int $orphanMinAgeSeconds = 3600): array
    {
        $root = $this->storage->root();
        $known = [];
        foreach ($this->db->fetchAll('SELECT id, ruta_relativa, tamano_bytes, sha256, estado FROM archivos_importados') as $r) {
            $known[(string) $r['ruta_relativa']] = $r;
        }
        $orphans = [];
        $staging = [];
        $importsDir = $root . '/imports';
        if (is_dir($importsDir)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($importsDir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file->isFile() || $file->getFilename() === '.gitkeep') {
                    continue;
                }
                $rel = substr($file->getPathname(), strlen($root) + 1);
                if (str_starts_with($rel, 'imports/.staging/')) {
                    if (time() - $file->getMTime() > $stagingMaxAgeSeconds) {
                        $staging[] = $rel;
                    }
                    continue;
                }
                // Un archivo recién movido puede pertenecer a una carga aún sin COMMIT: sólo se
                // consideran huérfanos los que superan la antigüedad mínima.
                if (!isset($known[$rel]) && time() - $file->getMTime() > $orphanMinAgeSeconds) {
                    $orphans[] = $rel;
                }
            }
        }
        $missing = [];
        foreach ($known as $rel => $r) {
            if ($r['estado'] === 'ANULADO') {
                continue;
            }
            $abs = $root . '/' . $rel;
            if (!is_file($abs)) {
                $missing[] = ['id' => (int) $r['id'], 'problema' => 'ORIGINAL_AUSENTE'];
            } elseif (filesize($abs) !== (int) $r['tamano_bytes']) {
                $missing[] = ['id' => (int) $r['id'], 'problema' => 'TAMANO_DISTINTO'];
            } elseif ($verifyHash && !hash_equals((string) $r['sha256'], (string) hash_file('sha256', $abs))) {
                $missing[] = ['id' => (int) $r['id'], 'problema' => 'SHA256_DISTINTO'];
            }
        }
        sort($orphans);
        sort($staging);
        return ['huerfanos' => $orphans, 'staging' => $staging, 'faltantes' => $missing, 'revisados' => count($known)];
    }

    /** Mueve (no borra) huérfanos y staging vencido a storage/cuarentena/{fecha}/. @param list<string> $relatives */
    public function quarantine(array $relatives): string
    {
        $dir = $this->storage->root() . '/cuarentena/' . date('Ymd-His');
        foreach ($relatives as $rel) {
            if (!str_starts_with($rel, 'imports/') || str_contains($rel, '..')) {
                continue;
            }
            // Re-verificación inmediata: nunca se aparta un original que ya tiene registro
            if ($this->db->fetchValue('SELECT 1 FROM archivos_importados WHERE ruta_relativa = :r', ['r' => $rel])) {
                continue;
            }
            $target = $dir . '/' . $rel;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0750, true);
            }
            @rename($this->storage->root() . '/' . $rel, $target);
        }
        return $dir;
    }
}
