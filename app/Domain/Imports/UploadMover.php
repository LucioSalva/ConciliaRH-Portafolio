<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Imports;

/**
 * Traslado del archivo temporal recibido. La implementación productiva exige que el
 * archivo provenga realmente de una carga HTTP (is_uploaded_file/move_uploaded_file).
 */
interface UploadMover
{
    public function isUploaded(string $path): bool;

    public function move(string $from, string $to): bool;
}
