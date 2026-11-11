<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Imports;

final class NativeUploadMover implements UploadMover
{
    public function isUploaded(string $path): bool
    {
        return is_uploaded_file($path);
    }

    public function move(string $from, string $to): bool
    {
        return move_uploaded_file($from, $to);
    }
}
