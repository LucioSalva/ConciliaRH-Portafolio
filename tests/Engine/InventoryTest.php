<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Engine;

use ConciliaRH\Engine\Tools\Inventory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class InventoryTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/crh-inv-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    private static function xlsx(string $path, bool $macros = false, string $extra = ''): void
    {
        $z = new ZipArchive();
        $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', '<Types/>' . $extra);
        if ($macros) {
            $z->addFromString('xl/vbaProject.bin', "\x00fake");
        }
        $z->close();
    }

    /** @return array<string,string> */
    private static function snapshot(string $dir): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $out[$f->getPathname()] = (string) file_get_contents($f->getPathname());
        }
        ksort($out);
        return $out;
    }

    public function testDetectsSameNameDifferentHashAndCopies(): void
    {
        $src = "$this->tmp/datos_ficticios";
        mkdir("$src/carpeta_a", 0775, true);
        mkdir("$src/carpeta_b");
        self::xlsx("$src/carpeta_a/SANTANDER ARCHIVO 1.xlsm", true);
        self::xlsx("$src/carpeta_b/SANTANDER ARCHIVO 1.xlsm", true, 'v2');
        self::xlsx("$src/carpeta_a/BBVA ARCHIVO 7.xlsx");
        copy("$src/carpeta_a/BBVA ARCHIVO 7.xlsx", "$src/carpeta_b/BBVA copia.xlsx");
        file_put_contents("$src/carpeta_a/INTERBANCARIA.xls", "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\x00", 64));
        file_put_contents("$src/carpeta_a/disfrazado.xlsx", "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\x00", 8));
        file_put_contents("$src/carpeta_a/~\$bloqueo.xlsx", 'x');
        $before = self::snapshot($src);

        $report = Inventory::analyze(Inventory::scan($src));

        self::assertSame(6, $report['total_archivos']);
        self::assertSame(['SANTANDER ARCHIVO 1.xlsm'], array_keys($report['mismo_nombre_distinto_hash']));
        self::assertCount(1, $report['copias_identicas']);
        self::assertSame(2, $report['con_macros']);
        self::assertSame(['OLE2' => 2, 'OOXML' => 4], $report['por_firma']);
        self::assertSame(['carpeta_a/disfrazado.xlsx'], $report['extension_incoherente']);
        self::assertSame($before, self::snapshot($src));
    }

    public function testRefusesToWriteInsideSourceOrRepo(): void
    {
        $src = "$this->tmp/origen";
        mkdir($src);
        $repo = "$this->tmp/repo";
        mkdir("$repo/storage", 0775, true);
        foreach (["$src/reporte.json", "$repo/docs/reporte.json"] as $bad) {
            try {
                Inventory::checkOutputPath($bad, $src, $repo);
                self::fail("Debió rechazar $bad");
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
        Inventory::checkOutputPath("$repo/storage/trabajo/r.json", $src, $repo);
        Inventory::checkOutputPath("$this->tmp/fuera/r.md", $src, $repo);
        $out = "$this->tmp/fuera/r.json";
        Inventory::main($src, $out, $repo);
        self::assertSame(0, json_decode((string) file_get_contents($out), true)['total_archivos']);
    }
}
