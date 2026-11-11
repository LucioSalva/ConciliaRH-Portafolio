<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Imports;

use finfo;
use ZipArchive;

final class FileInspector
{
    public const ALLOWED_MIME = [
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/cdfv2', 'application/msexcel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'xlsm' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip',
                   'application/vnd.ms-excel.sheet.macroenabled.12'],
    ];

    private const MAIN_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';
    private const MAIN_XLSM = 'application/vnd.ms-excel.sheet.macroenabled.main+xml';
    private const OLE2 = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    private const ZIP = "PK\x03\x04";

    /**
     * @param array{max_entries:int,max_uncompressed:int,max_ratio:int,max_xml:int} $limits
     * @return array{firma:string,mime:string,tiene_macros:bool,contenedor:array<string,int|float|bool>}
     */
    public function inspect(string $path, string $extension, array $limits): array
    {
        if (!isset(self::ALLOWED_MIME[$extension])) {
            throw new ImportException('EXTENSION_NO_PERMITIDA');
        }
        $size = @filesize($path);
        if ($size === false) {
            throw new ImportException('UPLOAD_ERROR_SERVIDOR');
        }
        if ($size === 0) {
            throw new ImportException('ARCHIVO_VACIO');
        }
        $head = (string) @file_get_contents($path, false, null, 0, 8);
        $firma = str_starts_with($head, self::OLE2) ? 'OLE2' : (str_starts_with($head, self::ZIP) ? 'OOXML' : null);
        if ($firma === null || ($firma === 'OLE2') !== ($extension === 'xls')) {
            throw new ImportException('FIRMA_INVALIDA');
        }
        $mime = strtolower((string) (new finfo(FILEINFO_MIME_TYPE))->file($path));
        if (!in_array($mime, self::ALLOWED_MIME[$extension], true)) {
            throw new ImportException('MIME_NO_PERMITIDO', null, ['mime_detectado' => $mime]);
        }
        if ($firma === 'OLE2') {
            return ['firma' => 'OLE2', 'mime' => $mime, 'tiene_macros' => false, 'contenedor' => ['tamano' => $size]];
        }
        return ['firma' => 'OOXML', 'mime' => $mime] + $this->inspectOoxml($path, $extension, $limits, $size);
    }

    /**
     * @param array{max_entries:int,max_uncompressed:int,max_ratio:int,max_xml:int} $limits
     * @return array{tiene_macros:bool,contenedor:array<string,int|float|bool>}
     */
    private function inspectOoxml(string $path, string $extension, array $limits, int $size): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ImportException('ARCHIVO_CORRUPTO');
        }
        try {
            $n = $zip->numFiles;
            if ($n <= 0) {
                throw new ImportException('ARCHIVO_CORRUPTO');
            }
            if ($n > $limits['max_entries']) {
                throw new ImportException('CONTENEDOR_PELIGROSO', null, ['motivo' => 'entradas', 'entradas' => $n]);
            }
            $total = 0;
            $maxRatio = 0.0;
            $macros = false;
            $names = [];
            for ($i = 0; $i < $n; $i++) {
                $st = $zip->statIndex($i);
                if ($st === false) {
                    throw new ImportException('ARCHIVO_CORRUPTO');
                }
                $name = (string) $st['name'];
                if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/')
                    || preg_match('#(^|/)\.\.(/|$)#', $name) === 1) {
                    throw new ImportException('CONTENEDOR_PELIGROSO', null, ['motivo' => 'ruta_interna']);
                }
                $usize = (int) $st['size'];
                $csize = max(1, (int) $st['comp_size']);
                $total += $usize;
                if ($usize > 1_048_576) {
                    $maxRatio = max($maxRatio, $usize / $csize);
                    if ($usize / $csize > $limits['max_ratio']) {
                        throw new ImportException('CONTENEDOR_PELIGROSO', null, ['motivo' => 'ratio']);
                    }
                }
                if (str_ends_with(strtolower($name), '.xml') && $usize > $limits['max_xml']) {
                    throw new ImportException('CONTENEDOR_PELIGROSO', null, ['motivo' => 'xml']);
                }
                if ($total > $limits['max_uncompressed']) {
                    throw new ImportException('CONTENEDOR_PELIGROSO', null, ['motivo' => 'descomprimido']);
                }
                if (str_ends_with(strtolower($name), 'vbaproject.bin')) {
                    $macros = true;
                }
                $names[$name] = true;
            }
            if (!isset($names['[Content_Types].xml'])) {
                throw new ImportException('ARCHIVO_CORRUPTO');
            }
            $ct = $zip->getFromName('[Content_Types].xml', 1_048_576);
            if (!is_string($ct) || $ct === '') {
                throw new ImportException('ARCHIVO_CORRUPTO');
            }
            if (preg_match('/<!DOCTYPE|<!ENTITY/i', $ct) === 1) {
                throw new ImportException('CONTENEDOR_PELIGROSO', null, ['motivo' => 'entidades_xml']);
            }
            $ctLower = strtolower($ct);
            $isXlsm = str_contains($ctLower, self::MAIN_XLSM);
            $isXlsx = str_contains($ctLower, self::MAIN_XLSX);
            if (!$isXlsm && !$isXlsx) {
                throw new ImportException('EXTENSION_NO_COINCIDE');
            }
            if (($extension === 'xlsm' && !$isXlsm) || ($extension === 'xlsx' && ($isXlsm || $macros))) {
                throw new ImportException('EXTENSION_NO_COINCIDE');
            }
            return [
                'tiene_macros' => $macros,
                'contenedor' => [
                    'tamano' => $size,
                    'entradas' => $n,
                    'descomprimido_declarado' => $total,
                    'ratio_maximo' => round($maxRatio, 2),
                ],
            ];
        } finally {
            $zip->close();
        }
    }
}
