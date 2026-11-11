<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Support;

use ConciliaRH\Domain\Imports\UploadedFile;
use RuntimeException;

final class ImportFixtures
{
    public static function dir(): string
    {
        $dir = dirname(__DIR__, 2) . '/tests/fixtures/excel';
        if (!is_file($dir . '/valid.xlsx')) {
            throw new RuntimeException('Faltan los fixtures Excel ficticios: ejecute `make fixtures`.');
        }
        return $dir;
    }

    public static function upload(string $fixture, string $tmpDir, ?string $clientName = null, int $error = UPLOAD_ERR_OK): UploadedFile
    {
        $tmp = $tmpDir . '/php' . bin2hex(random_bytes(6));
        copy(self::dir() . '/' . $fixture, $tmp);
        return new UploadedFile($tmp, $clientName ?? $fixture, 'application/octet-stream', $error);
    }

    public static function tmpDir(): string
    {
        $d = sys_get_temp_dir() . '/crh-test-' . bin2hex(random_bytes(6));
        mkdir($d, 0700, true);
        return $d;
    }

    public static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            @chmod($f->getPathname(), 0700);
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
