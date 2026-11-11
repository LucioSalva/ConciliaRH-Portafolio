<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Fixtures;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipArchive;

final class Book
{
    public const XLSX_MAIN = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';
    public const XLSM_MAIN = 'application/vnd.ms-excel.sheet.macroEnabled.main+xml';

    public readonly Spreadsheet $spreadsheet;
    /** @var array<int,int> */
    private array $rows = [];

    public function __construct(string $firstSheet = 'Hoja1')
    {
        $this->spreadsheet = new Spreadsheet();
        $this->spreadsheet->getActiveSheet()->setTitle($firstSheet);
    }

    public function sheet(int $i = 0): Worksheet
    {
        return $this->spreadsheet->getSheet($i);
    }

    public function add(string $title): int
    {
        $ws = $this->spreadsheet->createSheet();
        $ws->setTitle($title);
        return $this->spreadsheet->getIndex($ws);
    }

    /** @param list<mixed> $values */
    public function append(int $sheet, array $values): int
    {
        $row = ($this->rows[$sheet] ?? 0) + 1;
        $this->rows[$sheet] = $row;
        foreach ($values as $i => $v) {
            $this->set($sheet, $i + 1, $row, $v);
        }
        return $row;
    }

    public function set(int $sheet, int $col, int $row, mixed $v): void
    {
        $this->rows[$sheet] = max($this->rows[$sheet] ?? 0, $row);
        if ($v === null) {
            return;
        }
        $cell = $this->sheet($sheet)->getCell([$col, $row]);
        match (true) {
            is_string($v) && str_starts_with($v, '=') => $cell->setValueExplicit($v, DataType::TYPE_FORMULA),
            is_string($v) => $cell->setValueExplicit($v, DataType::TYPE_STRING),
            is_bool($v) => $cell->setValueExplicit($v, DataType::TYPE_BOOL),
            default => $cell->setValueExplicit($v, DataType::TYPE_NUMERIC),
        };
    }

    public function maxRow(int $sheet): int
    {
        return $this->rows[$sheet] ?? 0;
    }

    public function hideRow(int $sheet, int $row): void
    {
        $this->sheet($sheet)->getRowDimension($row)->setVisible(false);
    }

    public function xlsx(): string
    {
        $w = new Xlsx($this->spreadsheet);
        $w->setPreCalculateFormulas(false);
        $tmp = tempnam(sys_get_temp_dir(), 'fx');
        $w->save($tmp);
        $data = (string) file_get_contents($tmp);
        unlink($tmp);
        return $data;
    }

    public function xls(): string
    {
        $w = new Xls($this->spreadsheet);
        $w->setPreCalculateFormulas(false);
        $tmp = tempnam(sys_get_temp_dir(), 'fx');
        $w->save($tmp);
        $data = (string) file_get_contents($tmp);
        unlink($tmp);
        return $data;
    }

    /**
     * @param array<string,string> $replace
     * @param array<string,string> $add
     */
    public static function rewriteZip(string $src, array $replace = [], array $add = [], ?int $level = null): string
    {
        $in = tempnam(sys_get_temp_dir(), 'zi');
        $out = tempnam(sys_get_temp_dir(), 'zo');
        file_put_contents($in, $src);
        @unlink($out);
        $zin = new ZipArchive();
        $zin->open($in);
        $zout = new ZipArchive();
        $zout->open($out, ZipArchive::CREATE);
        for ($i = 0; $i < $zin->numFiles; $i++) {
            $name = (string) $zin->getNameIndex($i);
            $zout->addFromString($name, $replace[$name] ?? (string) $zin->getFromIndex($i));
            if ($level !== null) {
                $zout->setCompressionName($name, ZipArchive::CM_DEFLATE, $level);
            }
        }
        foreach ($add as $name => $data) {
            $zout->addFromString($name, $data);
            if ($level !== null) {
                $zout->setCompressionName($name, ZipArchive::CM_DEFLATE, $level);
            }
        }
        $zin->close();
        $zout->close();
        $data = (string) file_get_contents($out);
        unlink($in);
        unlink($out);
        return $data;
    }

    public static function read(string $zip, string $name): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'zr');
        file_put_contents($tmp, $zip);
        $z = new ZipArchive();
        $z->open($tmp);
        $data = (string) $z->getFromName($name);
        $z->close();
        unlink($tmp);
        return $data;
    }

    /** @param array<string,string> $cached */
    public static function withCache(string $data, int $sheet, array $cached): string
    {
        $name = 'xl/worksheets/sheet' . ($sheet + 1) . '.xml';
        $xml = self::read($data, $name);
        foreach ($cached as $ref => $value) {
            $q = preg_quote($ref, '/');
            $xml = (string) preg_replace('/(<c r="' . $q . '"[^>]*>)(<f>[^<]*<\/f>)<v\s*\/?>(<\/v>)?/', '$1$2<v>' . $value . '</v>', $xml);
            $xml = (string) preg_replace('/(<c r="' . $q . '"[^>]*>)(<f>[^<]*<\/f>)(<\/c>)/', '$1$2<v>' . $value . '</v>$3', $xml);
        }
        return self::rewriteZip($data, [$name => $xml]);
    }

    /** @param list<int> $rows */
    public static function withHiddenRows(string $data, int $sheet, array $rows): string
    {
        $name = 'xl/worksheets/sheet' . ($sheet + 1) . '.xml';
        $xml = self::read($data, $name);
        foreach ($rows as $r) {
            $xml = (string) preg_replace_callback('/<row r="' . $r . '"([^>]*)>/', static function (array $m) use ($r): string {
                $attrs = (string) preg_replace('/\s+hidden="[^"]*"/', '', $m[1]);
                return '<row r="' . $r . '"' . $attrs . ' hidden="1">';
            }, $xml);
        }
        return self::rewriteZip($data, [$name => $xml]);
    }

    public static function toXlsm(string $base): string
    {
        $ct = self::read($base, '[Content_Types].xml');
        $rels = self::read($base, 'xl/_rels/workbook.xml.rels');
        $ct = str_replace(self::XLSX_MAIN, self::XLSM_MAIN, $ct);
        $ct = preg_replace('/<Default Extension="xml"/', '<Default Extension="bin" ContentType="application/vnd.ms-office.vbaProject"/><Default Extension="xml"', $ct, 1);
        $rels = str_replace('</Relationships>',
            '<Relationship Id="rIdVba" Type="http://schemas.microsoft.com/office/2006/relationships/vbaProject" Target="vbaProject.bin"/></Relationships>', $rels);
        return self::rewriteZip($base, ['[Content_Types].xml' => (string) $ct, 'xl/_rels/workbook.xml.rels' => $rels],
            ['xl/vbaProject.bin' => str_repeat('FICTICIO-SIN-CODIGO-EJECUTABLE', 10)]);
    }

    public static function pyRound(float $v, int $places = 2): float
    {
        return \ConciliaRH\Engine\Support\Py::round($v, $places);
    }
}
