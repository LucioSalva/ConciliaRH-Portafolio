<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Imports;

/**
 * Almacenamiento de originales FUERA del web root:
 *   STORAGE_PATH/imports/{periodo_id}/{lote_id}/{uuid}/original.{ext}
 * - La ruta se construye sólo con enteros, un UUID generado por el servidor y una extensión
 *   de lista blanca; el nombre del usuario jamás interviene (CWE-22).
 * - En la BD se guarda la ruta RELATIVA (portable entre servidores).
 * - Staging: imports/.staging/{uuid}.part (mismo sistema de archivos → rename atómico).
 * - Los originales quedan en 0440 (sólo lectura) y nunca se reescriben.
 */
class ImportStorage
{
    public const RELATIVE_PATTERN = '#^imports/[0-9]+/[0-9]+/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/original\.(xls|xlsx|xlsm)$#';
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function __construct(private readonly string $root)
    {
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20));
    }

    public function root(): string
    {
        return rtrim($this->root, '/');
    }

    public static function relativePath(int $periodoId, int $loteId, string $uuid, string $ext): string
    {
        $rel = sprintf('imports/%d/%d/%s/original.%s', $periodoId, $loteId, $uuid, $ext);
        if ($periodoId <= 0 || $loteId <= 0 || preg_match(self::UUID, $uuid) !== 1 || preg_match(self::RELATIVE_PATTERN, $rel) !== 1) {
            throw new ImportException('ALMACENAMIENTO_ERROR');
        }
        return $rel;
    }

    /** Ruta absoluta de una ruta relativa validada; verifica que no escape de la raíz. */
    public function absolute(string $relative): string
    {
        if (preg_match(self::RELATIVE_PATTERN, $relative) !== 1) {
            throw new ImportException('ALMACENAMIENTO_ERROR');
        }
        return $this->root() . '/' . $relative;
    }

    /** Traslada el temporal recibido al área de staging. Devuelve la ruta del staging. */
    public function stage(UploadedFile $file, UploadMover $mover, string $uuid): string
    {
        if (preg_match(self::UUID, $uuid) !== 1) {
            throw new ImportException('ALMACENAMIENTO_ERROR');
        }
        $dir = $this->root() . '/imports/.staging';
        $this->ensureDir($dir);
        $target = $dir . '/' . $uuid . '.part';
        if (file_exists($target) || !@$mover->move($file->tmpPath, $target)) {
            throw new ImportException('ALMACENAMIENTO_ERROR');
        }
        @chmod($target, 0640);
        return $target;
    }

    /** Mueve el staging a su ubicación definitiva (rename atómico) y lo deja de sólo lectura. */
    public function finalize(string $staged, string $relative): string
    {
        $final = $this->absolute($relative);
        $this->ensureDir(dirname($final));
        if (file_exists($final) || is_link($staged) || !is_file($staged) || !@rename($staged, $final)) {
            throw new ImportException('ALMACENAMIENTO_ERROR');
        }
        @chmod($final, 0440);
        return $final;
    }

    /** Compensación: elimina un archivo de staging o definitivo y su directorio UUID si quedó vacío. */
    public function discard(?string $path): void
    {
        if ($path === null || $path === '' || !str_starts_with($path, $this->root() . '/imports/')) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @chmod($path, 0640);
            @unlink($path);
        }
        $dir = dirname($path);
        if (preg_match('#/imports/[0-9]+/[0-9]+/[0-9a-f-]{36}$#', $dir) === 1) {
            @rmdir($dir);
        }
    }

    /** Verifica que el original exista, sea un archivo regular y conserve tamaño y SHA-256. */
    public function verify(string $relative, int $size, string $sha256): bool
    {
        $path = $this->absolute($relative);
        clearstatcache(true, $path);
        if (!is_file($path) || is_link($path) || filesize($path) !== $size) {
            return false;
        }
        return hash_equals($sha256, (string) hash_file('sha256', $path));
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new ImportException('ALMACENAMIENTO_ERROR');
        }
        if (!is_writable($dir)) {
            throw new ImportException('ALMACENAMIENTO_ERROR');
        }
    }
}
