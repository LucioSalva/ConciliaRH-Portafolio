<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Imports;

use Normalizer;

/**
 * Normaliza el nombre ORIGINAL de un archivo para guardarlo como metadato.
 * El nombre nunca se usa como ruta física (ésta se genera con un UUID).
 *  - elimina cualquier componente de ruta (/, \) y bytes nulos o de control;
 *  - normaliza Unicode a NFC y quita caracteres de control bidireccional (spoofing
 *    tipo "gpj.xlsx" con U+202E) y de ancho cero;
 *  - colapsa espacios y limita a 200 caracteres conservando la extensión.
 */
final class FilenameSanitizer
{
    private const MAX = 200;

    public static function sanitize(string $name): string
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        }
        // Último componente de ruta, sin importar el separador que envió el cliente
        $parts = preg_split('#[/\\\\]#u', $name) ?: [$name];
        $name = (string) end($parts);
        if (class_exists(Normalizer::class)) {
            $name = (string) Normalizer::normalize($name, Normalizer::FORM_C);
        }
        // Controles C0/C1, bidi (U+202A–202E, U+2066–2069), ancho cero y BOM
        $name = (string) preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), " .\t");
        if ($name === '' || $name === '.' || $name === '..') {
            return '';
        }
        if (mb_strlen($name) > self::MAX) {
            $ext = self::extension($name);
            $keep = self::MAX - ($ext === '' ? 0 : mb_strlen($ext) + 1);
            $name = mb_substr($name, 0, $keep) . ($ext === '' ? '' : '.' . $ext);
        }
        return $name;
    }

    public static function extension(string $name): string
    {
        $dot = mb_strrpos($name, '.');
        return $dot === false ? '' : mb_strtolower(mb_substr($name, $dot + 1));
    }

    /** Nombre ASCII seguro para la cabecera Content-Disposition (el original va en filename*). */
    public static function asciiFallback(string $name): string
    {
        $ascii = (string) preg_replace('/[^A-Za-z0-9._ -]/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: 'archivo');
        return mb_substr(trim($ascii) === '' ? 'archivo' : $ascii, 0, 150);
    }
}
