<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Support;

use ConciliaRH\Domain\Imports\UploadMover;

/**
 * En pruebas no existen cargas HTTP reales: se consideran "subidos" sólo los archivos del
 * directorio temporal de la prueba (nunca rutas arbitrarias) y se COPIAN, conservando el fixture.
 */
final class TestUploadMover implements UploadMover
{
    public function __construct(private readonly string $allowedDir, private readonly bool $uploaded = true)
    {
    }

    public function isUploaded(string $path): bool
    {
        $real = realpath($path);
        return $this->uploaded && $real !== false && str_starts_with($real, realpath($this->allowedDir) . '/');
    }

    public function move(string $from, string $to): bool
    {
        return @copy($from, $to);
    }
}
