<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Conciliation;

use RuntimeException;
use ZipArchive;

/**
 * Exportación de tablas a CSV y XLSX sin dependencias.
 *
 * Inyección de fórmulas (CWE-1236): una celda de TEXTO que empieza con = + - @ tabulador o retorno se prefija con
 * un apóstrofo en CSV. En XLSX el texto se escribe como cadena en línea (`inlineStr`) y nunca se escribe `<f>`,
 * así que ninguna celda es fórmula; los importes van como números reales (`n`) sólo si su forma es decimal estricta.
 */
final class Exporter
{
    /** Directorio de trabajo PRIVADO para el ZIP temporal (STORAGE_PATH/trabajo/reportes, fijado al arrancar; no /tmp del sistema). */
    private static ?string $workDir = null;

    public static function useWorkDir(string $dir): void
    {
        self::$workDir = rtrim($dir, '/');
    }

    /** @param list<string> $columns @param list<list<string|null>> $rows @param list<int> $numeric */
    public static function csv(array $columns, array $rows, array $numeric): string
    {
        $out = fopen('php://temp', 'w+');
        if ($out === false) {
            throw new RuntimeException('No se pudo generar el CSV.');
        }
        fwrite($out, "\xEF\xBB\xBF");            // BOM: Excel abre UTF-8 correctamente
        // Escape '' = RFC 4180 puro (comillas duplicadas). Con '\\' una secuencia \" dentro de un valor cerraría el campo
        // y permitiría abrir una celda nueva que empiece con «=».
        fputcsv($out, array_map([self::class, 'text'], $columns), ',', '"', '');
        foreach ($rows as $r) {
            $cells = [];
            foreach ($r as $i => $v) {
                $cells[] = in_array($i, $numeric, true) && self::isDecimal($v) ? $v : self::text($v);
            }
            fputcsv($out, $cells, ',', '"', '');
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }

    /** @param list<string> $columns @param list<list<string|null>> $rows @param list<int> $numeric */
    public static function xlsx(array $columns, array $rows, array $numeric, string $sheetName = 'Conciliación'): string
    {
        $xmlRows = [self::xlsxRow(1, $columns, [])];
        foreach ($rows as $i => $r) {
            $xmlRows[] = self::xlsxRow($i + 2, $r, $numeric);
        }
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . implode('', $xmlRows) . '</sheetData></worksheet>';
        $dir = self::$workDir !== null && is_dir(self::$workDir) && is_writable(self::$workDir) ? self::$workDir : sys_get_temp_dir();
        $tmp = tempnam($dir, 'crhx');
        if ($tmp === false) {
            throw new RuntimeException('No se pudo generar el XLSX.');
        }
        try {
            return self::buildXlsx($tmp, $sheet, $sheetName);
        } finally {
            @unlink($tmp);   // también si falla a medias: nunca queda una exportación con datos de personas en disco
        }
    }

    private static function buildXlsx(string $tmp, string $sheet, string $sheetName): string
    {
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::xml(mb_substr($sheetName, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        return (string) file_get_contents($tmp);
    }

    /** Neutraliza un texto que una hoja de cálculo interpretaría como fórmula. */
    public static function text(?string $v): string
    {
        $v ??= '';
        return $v !== '' && str_contains("=+-@\t\r", $v[0]) ? "'" . $v : $v;
    }

    private static function isDecimal(?string $v): bool
    {
        return $v !== null && preg_match('/^-?\d{1,15}(\.\d{1,2})?$/', $v) === 1;
    }

    /** @param list<string|null> $cells @param list<int> $numeric */
    private static function xlsxRow(int $n, array $cells, array $numeric): string
    {
        $out = '<row r="' . $n . '">';
        foreach ($cells as $i => $v) {
            $ref = self::col($i) . $n;
            if (in_array($i, $numeric, true) && self::isDecimal($v)) {
                $out .= '<c r="' . $ref . '" t="n"><v>' . $v . '</v></c>';
            } elseif ($v !== null && $v !== '') {
                $out .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . self::xml(self::text($v)) . '</t></is></c>';
            }
        }
        return $out . '</row>';
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    private static function xml(string $v): string
    {
        $v = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v);
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
