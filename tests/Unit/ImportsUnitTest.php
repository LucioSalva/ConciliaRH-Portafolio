<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Unit;

use ConciliaRH\Core\Request;
use ConciliaRH\Domain\Imports\FileInspector;
use ConciliaRH\Domain\Imports\FilenameSanitizer;
use ConciliaRH\Domain\Imports\ImportException;
use ConciliaRH\Domain\Imports\ImportStorage;
use ConciliaRH\Domain\Imports\UploadedFile;
use ConciliaRH\Domain\Periods\PeriodCalendar;
use ConciliaRH\Tests\Support\ImportFixtures;
use ConciliaRH\Tests\Support\TestUploadMover;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ImportsUnitTest extends TestCase
{
    private const LIMITS = ['max_entries' => 5000, 'max_uncompressed' => 300 * 1048576, 'max_ratio' => 150, 'max_xml' => 200 * 1048576];

    /** @return list<array{string,string}> */
    public static function names(): array
    {
        return [
            ['../../etc/passwd.xlsx', 'passwd.xlsx'],
            ['C:\\Users\\x\\NOMINA.xls', 'NOMINA.xls'],
            ["archivo\0malo.xlsx", 'archivomalo.xlsx'],
            ["factura\u{202E}xslx.exe", 'facturaxslx.exe'],
            ["NÓMINA POLICÍA MAYO (1).xlsx", 'NÓMINA POLICÍA MAYO (1).xlsx'],
            ["  espacios   múltiples  .xlsx ", 'espacios múltiples .xlsx'],
            ['..', ''],
            ['/', ''],
        ];
    }

    #[DataProvider('names')]
    public function testFilenameSanitizer(string $in, string $out): void
    {
        self::assertSame($out, FilenameSanitizer::sanitize($in));
    }

    public function testLongNamesKeepExtensionAndNfcNormalization(): void
    {
        $long = str_repeat('Á', 400) . '.xlsx';
        $s = FilenameSanitizer::sanitize($long);
        self::assertSame(200, mb_strlen($s));
        self::assertStringEndsWith('.xlsx', $s);
        // "é" descompuesta (e + acento combinante) se normaliza a NFC
        self::assertSame("n\u{00E9}mina.xls", FilenameSanitizer::sanitize("ne\u{0301}mina.xls"));
        self::assertSame('xlsm', FilenameSanitizer::extension('LIBRO.XLSM'));
        self::assertSame('NOMINA POLICIA _1_.xlsx', FilenameSanitizer::asciiFallback('NÓMINA POLICÍA (1).xlsx'));
    }

    /** @return list<array{string,string,?string}> fixture, extensión, código esperado (null = válido) */
    public static function inspections(): array
    {
        return [
            ['valid.xlsx', 'xlsx', null],
            ['valid.xlsm', 'xlsm', null],
            ['valid.xls', 'xls', null],
            ['fake-extension.xlsx', 'xlsx', 'FIRMA_INVALIDA'],
            ['zip-as.xls', 'xls', 'FIRMA_INVALIDA'],
            ['valid.xls', 'xlsx', 'FIRMA_INVALIDA'],
            ['corrupt.xlsx', 'xlsx', 'ARCHIVO_CORRUPTO'],
            ['empty.xlsx', 'xlsx', 'ARCHIVO_VACIO'],
            ['zipbomb.xlsx', 'xlsx', 'CONTENEDOR_PELIGROSO'],
            ['xxe.xlsx', 'xlsx', 'CONTENEDOR_PELIGROSO'],
            ['traversal.xlsx', 'xlsx', 'CONTENEDOR_PELIGROSO'],
            ['many-entries.xlsx', 'xlsx', 'CONTENEDOR_PELIGROSO'],
            ['macros-as.xlsx', 'xlsx', 'EXTENSION_NO_COINCIDE'],
            ['valid.xlsx', 'xlsm', 'EXTENSION_NO_COINCIDE'],
            ['not-excel.xlsx', 'xlsx', 'EXTENSION_NO_COINCIDE'],
            ['valid.xlsx', 'csv', 'EXTENSION_NO_PERMITIDA'],
        ];
    }

    #[DataProvider('inspections')]
    public function testFileInspector(string $fixture, string $ext, ?string $expected): void
    {
        $path = ImportFixtures::dir() . '/' . $fixture;
        try {
            $r = (new FileInspector())->inspect($path, $ext, self::LIMITS);
            self::assertNull($expected, "Se esperaba $expected");
            self::assertSame($ext === 'xls' ? 'OLE2' : 'OOXML', $r['firma']);
            self::assertSame($ext === 'xlsm', $r['tiene_macros']);
        } catch (ImportException $e) {
            self::assertSame($expected, $e->errorCode(), $e->getMessage());
        }
    }

    public function testMimeSpoofingIsRejected(): void
    {
        // ZIP válido que libmagic identifica como EPUB: extensión .xlsx pero MIME real no permitido
        $dir = ImportFixtures::tmpDir();
        $p = $dir . '/libro.xlsx';
        $z = new ZipArchive();
        $z->open($p, ZipArchive::CREATE);
        $z->addFromString('mimetype', 'application/epub+zip');
        $z->setCompressionName('mimetype', ZipArchive::CM_STORE);
        $z->addFromString('META-INF/container.xml', '<container/>');
        $z->close();
        try {
            (new FileInspector())->inspect($p, 'xlsx', self::LIMITS);
            self::fail('MIME falso aceptado');
        } catch (ImportException $e) {
            self::assertSame('MIME_NO_PERMITIDO', $e->errorCode());
            self::assertSame('application/epub+zip', $e->context()['mime_detectado']);
        } finally {
            ImportFixtures::rmTree($dir);
        }
    }

    public function testLowerLimitsAreEnforced(): void
    {
        $this->expectExceptionObject(new ImportException('CONTENEDOR_PELIGROSO'));
        (new FileInspector())->inspect(ImportFixtures::dir() . '/valid.xlsx', 'xlsx', ['max_entries' => 3] + self::LIMITS);
    }

    public function testStoragePathsAreGeneratedAndConfined(): void
    {
        $uuid = ImportStorage::uuid();
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
        self::assertNotSame($uuid, ImportStorage::uuid());
        self::assertSame("imports/3/7/$uuid/original.xlsm", ImportStorage::relativePath(3, 7, $uuid, 'xlsm'));
        $storage = new ImportStorage('/srv/storage');
        foreach (['../etc/passwd', "imports/1/1/$uuid/original.php", "imports/1/1/../../x/original.xls", '/etc/passwd'] as $bad) {
            try {
                $storage->absolute($bad);
                self::fail("Ruta aceptada: $bad");
            } catch (ImportException $e) {
                self::assertSame('ALMACENAMIENTO_ERROR', $e->errorCode());
            }
        }
        foreach ([[0, 1, $uuid, 'xls'], [1, 1, 'no-uuid', 'xls'], [1, 1, $uuid, 'php']] as $args) {
            try {
                ImportStorage::relativePath(...$args);
                self::fail('Ruta relativa inválida aceptada');
            } catch (ImportException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testStageFinalizeDiscardAndNoOverwrite(): void
    {
        $tmp = ImportFixtures::tmpDir();
        $root = ImportFixtures::tmpDir();
        try {
            $storage = new ImportStorage($root);
            $file = ImportFixtures::upload('valid.xlsx', $tmp);
            $uuid = ImportStorage::uuid();
            $staged = $storage->stage($file, new TestUploadMover($tmp), $uuid);
            self::assertFileExists($staged);
            $rel = ImportStorage::relativePath(1, 2, $uuid, 'xlsx');
            $final = $storage->finalize($staged, $rel);
            self::assertFileDoesNotExist($staged);
            self::assertSame('0440', substr(sprintf('%o', fileperms($final)), -4));
            self::assertTrue($storage->verify($rel, filesize($final), hash_file('sha256', $final)));
            self::assertFalse($storage->verify($rel, filesize($final), str_repeat('0', 64)));
            // Nunca se sobrescribe un original existente
            $again = $storage->stage(ImportFixtures::upload('valid.xls', $tmp), new TestUploadMover($tmp), ImportStorage::uuid());
            try {
                $storage->finalize($again, $rel);
                self::fail('Sobrescritura permitida');
            } catch (ImportException) {
                $this->addToAssertionCount(1);
            }
            $storage->discard($final);
            self::assertFileDoesNotExist($final);
            self::assertDirectoryDoesNotExist(dirname($final));
            // discard ignora rutas fuera de imports/
            $storage->discard('/etc/hostname');
            self::assertFileExists('/etc/hostname');
        } finally {
            ImportFixtures::rmTree($tmp);
            ImportFixtures::rmTree($root);
        }
    }

    public function testUploadedFileRejectsMultiFileArrays(): void
    {
        self::assertNull(UploadedFile::fromArray(['tmp_name' => ['a'], 'name' => ['b'], 'error' => [0]]));
        self::assertNull(UploadedFile::fromArray('x'));
        self::assertSame('a.xls', UploadedFile::fromArray(['tmp_name' => '/tmp/x', 'name' => 'a.xls', 'error' => 0])?->clientName);
    }

    public function testPostMaxSizeDetection(): void
    {
        self::assertSame(30 * 1048576, Request::iniBytes('30M'));
        self::assertSame(1073741824, Request::iniBytes('1G'));
        self::assertSame(0, Request::iniBytes('abc'));
        $huge = new Request('POST', '/importaciones', [], [], ['CONTENT_LENGTH' => (string) (PHP_INT_MAX >> 1)]);
        self::assertTrue($huge->exceededPostMaxSize());
        $normal = new Request('POST', '/importaciones', [], ['_token' => 'x'], ['CONTENT_LENGTH' => '100']);
        self::assertFalse($normal->exceededPostMaxSize());
    }

    public function testPeriodCalendar(): void
    {
        self::assertSame(['2026-05-01', '2026-05-15'], PeriodCalendar::defaultDates(2026, 5, 1));
        self::assertSame(['2026-02-16', '2026-02-28'], PeriodCalendar::defaultDates(2026, 2, 2));
        self::assertSame(['2028-02-16', '2028-02-29'], PeriodCalendar::defaultDates(2028, 2, 2));
        self::assertSame('1RA QUINCENA MAYO 2026', PeriodCalendar::label(2026, 5, 1));
    }
}
