<?php

declare(strict_types=1);

namespace ConciliaRH\Core;

/**
 * Respuesta de descarga de un archivo del almacenamiento privado (nunca una URL pública).
 * Se transmite por bloques; el nombre se envía en Content-Disposition (ASCII + RFC 5987).
 */
final class FileResponse
{
    public static function download(string $path, string $asciiName, string $utf8Name, int $size): Response
    {
        $response = new Response('', 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $size,
            'Content-Disposition' => sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $asciiName, rawurlencode($utf8Name)),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
        return $response->withStream($path);
    }
}
