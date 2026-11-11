<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Fixtures;

use ConciliaRH\Engine\Files\CompoundFile;

final class ExcelFixtures
{
    private const ROWS = [
        ['1001', 'PERSONA FICTICIA UNO', 'XAXX010101000', 8500.00],
        ['1002', 'PERSONA FICTICIA DOS', 'XAXX010101000', 7250.50],
        ['1003', 'PERSONA FICTICIA TRES', 'XAXX010101000', 9100.25],
    ];

    public static function dir(): string
    {
        return dirname(__DIR__) . '/fixtures/excel';
    }

    public static function validXlsx(): string
    {
        $b = new Book('Hoja1');
        $b->append(0, ['FICTICIO']);
        $b->append(0, ['Núm.', 'Nombre', 'R.F.C.', 'IMPORTE']);
        foreach (self::ROWS as $r) {
            $b->append(0, $r);
        }
        return $b->xlsx();
    }

    public static function validXls(): string
    {
        $b = new Book('Hoja1');
        $b->append(0, ['Num', 'Nombre', 'RFC', 'IMPORTE']);
        foreach (self::ROWS as $r) {
            $b->append(0, $r);
        }
        return $b->xls();
    }

    public static function encryptedXls(string $valid): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'enc');
        file_put_contents($tmp, $valid);
        $cf = new CompoundFile($tmp);
        $book = null;
        foreach ($cf->listing() as $e) {
            if (strtolower($e['path']) === 'workbook') {
                $book = $e;
            }
        }
        $head = $cf->streamHead($book['index'], 8);
        $bofLen = unpack('v', substr($head, 2, 2))[1];
        $off = $cf->fileOffset($book['index'], 4 + $bofLen);
        $data = $valid;
        $data[$off] = "\x2F";
        $data[$off + 1] = "\x00";
        unlink($tmp);
        return $data;
    }

    /** @return array<string,string> */
    public static function generate(?string $dest = null): array
    {
        $dest ??= self::dir();
        if (!is_dir($dest)) {
            mkdir($dest, 0775, true);
        }
        $base = self::validXlsx();
        $xlsm = Book::toXlsm($base);
        $xls = self::validXls();
        $many = [];
        for ($i = 0; $i < 5200; $i++) {
            $many["xl/extra/p$i.xml"] = '<a/>';
        }
        $files = [
            'valid.xlsx' => $base,
            'valid.xlsm' => $xlsm,
            'valid.xls' => $xls,
            'encrypted.xls' => self::encryptedXls($xls),
            'valid-copy.xlsx' => $base,
            'corrupt.xlsx' => substr($base, 0, 300),
            'corrupt.xls' => CompoundFile::MAGIC . str_repeat("\x00", 504) . str_repeat('BASURA', 50),
            'fake-extension.xlsx' => str_repeat("Esto no es un libro de Excel; es texto ficticio.\n", 20),
            'zip-as.xls' => $base,
            'empty.xlsx' => '',
            'zipbomb.xlsx' => Book::rewriteZip($base, [], ['xl/media/relleno.bin' => str_repeat("\x00", 40 * 1024 * 1024)], 9),
            'xxe.xlsx' => Book::rewriteZip($base, ['[Content_Types].xml' => '<?xml version="1.0"?><!DOCTYPE t [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Override PartName="/xl/workbook.xml" ContentType="' . Book::XLSX_MAIN . '"/></Types>']),
            'xxe-utf16.xlsx' => Book::rewriteZip($base, [], ['xl/worksheets/extra.xml' => "\xFF\xFE" . mb_convert_encoding(
                '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE t [<!ENTITY x "x">]><t>&x;</t>', 'UTF-16LE', 'UTF-8')]),
            'xxe-otra-extension.xlsx' => Book::rewriteZip($base, [], ['xl/datos.dat' => '<?xml version="1.0"?><!DOCTYPE t [<!ENTITY x "x">]><t>&x;</t>']),
            'traversal.xlsx' => Book::rewriteZip($base, [], ['../fuera.txt' => 'ficticio']),
            'macros-as.xlsx' => $xlsm,
            'not-excel.xlsx' => Book::rewriteZip($base, ['[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>']),
            'many-entries.xlsx' => Book::rewriteZip($base, [], $many),
        ];
        $written = [];
        foreach ($files as $name => $data) {
            file_put_contents("$dest/$name", $data);
            $written[$name] = "$dest/$name";
        }
        foreach (AnalysisFixtures::generate("$dest/analisis") as $name => $path) {
            $written["analisis/$name"] = $path;
        }
        return $written;
    }
}
