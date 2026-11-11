<?php

declare(strict_types=1);

namespace ConciliaRH\Core;

/**
 * Modo mantenimiento (FASE 9 §46; docs/OPERATIONS_RUNBOOK.md §6). Un archivo <STORAGE_PATH>/mantenimiento.json que SÓLO
 * crea y borra la CLI (bin/maintenance.php). Mientras existe:
 *  · la web responde 503 en toda ruta salvo /api/health (que informa «mantenimiento» para el monitoreo);
 *  · el scheduler termina sin hacer nada y el worker no toma trabajos nuevos (termina el que tenga en curso).
 * Sirve para migraciones, restauraciones y rotaciones: nadie escribe mientras tanto. No es un control de acceso.
 */
final class Maintenance
{
    public const FILE = 'mantenimiento.json';

    public function __construct(private readonly string $storage)
    {
    }

    /** @return array{desde:string,motivo:string}|null estado del almacenamiento de ESTA instalación */
    public function current(): ?array
    {
        return self::status($this->storage);
    }

    public static function path(string $storage): string
    {
        return rtrim($storage, '/') . '/' . self::FILE;
    }

    /** @return array{desde:string,motivo:string}|null */
    public static function status(string $storage): ?array
    {
        $f = self::path($storage);
        if (!is_file($f)) {
            return null;
        }
        $d = json_decode((string) @file_get_contents($f), true);
        // Un archivo ilegible o alterado sigue significando «en mantenimiento» (falla cerrada)
        return ['desde' => is_array($d) && is_string($d['desde'] ?? null) ? $d['desde'] : '',
                'motivo' => is_array($d) && is_string($d['motivo'] ?? null) ? mb_substr($d['motivo'], 0, 200) : ''];
    }

    public static function enable(string $storage, string $motivo): void
    {
        $tmp = self::path($storage) . '.tmp';
        if (file_put_contents($tmp, json_encode(['desde' => date(DATE_ATOM), 'motivo' => mb_substr($motivo, 0, 200)],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false || !rename($tmp, self::path($storage))) {
            throw new \RuntimeException('No se pudo activar el modo mantenimiento (¿permisos de escritura en STORAGE_PATH?).');
        }
        @chmod(self::path($storage), 0644);
    }

    public static function disable(string $storage): void
    {
        $f = self::path($storage);
        if (is_file($f) && !unlink($f)) {
            throw new \RuntimeException('No se pudo desactivar el modo mantenimiento.');
        }
    }
}
