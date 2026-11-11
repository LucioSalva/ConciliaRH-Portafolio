<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Files;

use ConciliaRH\Engine\Support\Py;
use Throwable;
use ZipArchive;

final class ContainerVerifier
{
    private const ZIP = "PK\x03\x04";
    private const MAIN_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';
    private const MAIN_XLSM = 'application/vnd.ms-excel.sheet.macroenabled.main+xml';
    private const CHUNK = 65536;
    private const UTF8_BOM = "\xEF\xBB\xBF";
    private const WIDE = ["\xFF\xFE", "\xFE\xFF", "\x00\x00\xFE\xFF", "<\x00", "\x00<", "\x00\x00\x00<"];
    private const XML_EXT = ['.xml', '.rels', '.vml'];

    /**
     * @param callable(float,string):void $progress
     * @param callable():void $tick
     */
    public function verify(string $path, string $sha256, int $size, string $extension, Limits $limits, callable $progress,
                           callable $tick): Verdict
    {
        if (!is_file($path) || is_link($path)) {
            return Verdict::fail('INTEGRIDAD_FALLIDA', 'El original almacenado no es un archivo regular.');
        }
        if ((int) filesize($path) !== $size) {
            return Verdict::fail('INTEGRIDAD_FALLIDA', 'El tamaño del original no coincide con el registrado.');
        }
        $progress(20, 'Calculando SHA-256 del original');
        if (hash_file('sha256', $path) !== $sha256) {
            return Verdict::fail('INTEGRIDAD_FALLIDA', 'El SHA-256 del original no coincide con el registrado.');
        }
        $head = (string) file_get_contents($path, false, null, 0, 8);
        $progress(40, 'Inspeccionando el contenedor');
        if ($extension === 'xls') {
            if (!str_starts_with($head, CompoundFile::MAGIC)) {
                return Verdict::fail('FIRMA_INVALIDA', 'El archivo .xls no tiene firma OLE2.');
            }
            return $this->ole($path, $limits);
        }
        if (!str_starts_with($head, self::ZIP)) {
            return Verdict::fail('FIRMA_INVALIDA', 'El archivo no tiene firma OOXML (ZIP).');
        }
        return $this->ooxml($path, $extension, $limits, $progress, $tick);
    }

    /**
     * @param callable(float,string):void $progress
     * @param callable():void $tick
     */
    private function ooxml(string $path, string $extension, Limits $limits, callable $progress, callable $tick): Verdict
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return Verdict::fail('ARCHIVO_CORRUPTO', 'El contenedor ZIP está dañado.');
        }
        try {
            $n = $zip->numFiles;
            if ($n <= 0) {
                return Verdict::fail('ARCHIVO_CORRUPTO', 'El contenedor está vacío.');
            }
            if ($n > $limits->maxEntries) {
                return Verdict::fail('CONTENEDOR_PELIGROSO', 'Demasiadas partes internas.', ['entradas' => $n]);
            }
            $names = [];
            $total = 0;
            $maxRatio = 0.0;
            $macros = false;
            $external = 0;
            $contentTypes = '';
            $chunks = 0;
            for ($idx = 0; $idx < $n; $idx++) {
                $st = $zip->statIndex($idx);
                if ($st === false) {
                    return Verdict::fail('ARCHIVO_CORRUPTO', 'Una parte del contenedor está dañada.');
                }
                $name = (string) $st['name'];
                if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/')
                    || in_array('..', explode('/', $name), true)) {
                    return Verdict::fail('CONTENEDOR_PELIGROSO', 'Ruta interna no permitida en el contenedor.');
                }
                if ((int) ($st['encryption_method'] ?? 0) !== 0) {
                    return Verdict::fail('CONTENEDOR_PELIGROSO', 'El contenedor tiene partes cifradas.');
                }
                $names[$name] = true;
                $lower = strtolower($name);
                $macros = $macros || str_ends_with($lower, 'vbaproject.bin');
                $external += str_starts_with($lower, 'xl/externallinks/') ? 1 : 0;
                $isXml = false;
                foreach (self::XML_EXT as $ext) {
                    $isXml = $isXml || str_ends_with($lower, $ext);
                }
                $stream = $zip->getStreamIndex($idx);
                if ($stream === false) {
                    return Verdict::fail('ARCHIVO_CORRUPTO', 'Una parte del contenedor está dañada.');
                }
                $first = true;
                $produced = 0;
                $tail = '';
                $dangerous = false;
                $crc = hash_init('crc32b');
                try {
                    while (!feof($stream)) {
                        $chunk = fread($stream, self::CHUNK);
                        if ($chunk === false) {
                            return Verdict::fail('ARCHIVO_CORRUPTO', 'Una parte del contenedor está dañada.');
                        }
                        if ($chunk === '') {
                            break;
                        }
                        $chunks++;
                        if ($chunks % 64 === 0) {
                            $tick();
                        }
                        if ($first) {
                            $first = false;
                            foreach (self::WIDE as $w) {
                                if (str_starts_with(substr($chunk, 0, 4), $w)) {
                                    return Verdict::fail('CONTENEDOR_PELIGROSO', 'Una parte usa una codificación distinta de UTF-8 (no permitido).');
                                }
                            }
                            $body = substr($chunk, 0, 512);
                            $body = str_starts_with($body, self::UTF8_BOM) ? substr($body, 3) : $body;
                            $isXml = $isXml || str_starts_with(ltrim($body, " \t\r\n"), '<');
                        }
                        hash_update($crc, $chunk);
                        $produced += strlen($chunk);
                        $total += strlen($chunk);
                        if ($total > $limits->maxUncompressed) {
                            return Verdict::fail('CONTENEDOR_PELIGROSO', 'El tamaño descomprimido excede el límite.');
                        }
                        if ($isXml) {
                            if ($produced > $limits->maxXml) {
                                return Verdict::fail('CONTENEDOR_PELIGROSO', 'Una parte XML excede el tamaño permitido.');
                            }
                            $window = $tail . $chunk;
                            if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $window) === 1) {
                                $dangerous = true;
                                break;
                            }
                            $tail = substr($window, -32);
                            if ($name === '[Content_Types].xml' && strlen($contentTypes) < 1_048_576) {
                                $contentTypes .= $chunk;
                            }
                        }
                    }
                } finally {
                    fclose($stream);
                }
                if ($dangerous) {
                    return Verdict::fail('CONTENEDOR_PELIGROSO', 'El XML declara DOCTYPE/ENTITY (no permitido).');
                }
                if ($produced !== (int) $st['size'] || sprintf('%u', hexdec(hash_final($crc))) !== sprintf('%u', (int) $st['crc'])) {
                    return Verdict::fail('ARCHIVO_CORRUPTO', 'Una parte del contenedor está dañada.');
                }
                if ($produced > 1_048_576) {
                    $ratio = $produced / max(1, (int) $st['comp_size']);
                    $maxRatio = max($maxRatio, $ratio);
                    if ($ratio > $limits->maxRatio) {
                        return Verdict::fail('CONTENEDOR_PELIGROSO', 'Relación de compresión sospechosa (posible bomba ZIP).');
                    }
                }
                if ($idx % 50 === 0) {
                    $progress(40 + 50 * ($idx + 1) / $n, 'Descomprimiendo partes con límites de seguridad');
                }
            }
            if (!isset($names['[Content_Types].xml'])) {
                return Verdict::fail('ARCHIVO_CORRUPTO', 'Falta [Content_Types].xml.');
            }
            $ct = strtolower($contentTypes);
            $isXlsm = str_contains($ct, self::MAIN_XLSM);
            $isXlsx = str_contains($ct, self::MAIN_XLSX);
            if ((!$isXlsm && !$isXlsx) || ($extension === 'xlsm' && !$isXlsm) || ($extension === 'xlsx' && ($isXlsm || $macros))) {
                return Verdict::fail('EXTENSION_NO_COINCIDE', 'El libro no corresponde a su extensión.');
            }
            return Verdict::ok($macros, ['entradas' => $n, 'descomprimido_real' => $total, 'ratio_maximo' => Py::round($maxRatio, 2),
                                         'vinculos_externos' => $external]);
        } finally {
            $zip->close();
        }
    }

    private function ole(string $path, Limits $limits): Verdict
    {
        try {
            if (!CompoundFile::isOle($path)) {
                return Verdict::fail('ARCHIVO_CORRUPTO', 'El contenedor OLE2 no es válido.');
            }
            $cf = new CompoundFile($path);
            $entries = $cf->listing();
            if (count($entries) > $limits->maxEntries) {
                return Verdict::fail('CONTENEDOR_PELIGROSO', 'Demasiados flujos internos.');
            }
            $flat = [];
            foreach ($entries as $e) {
                $flat[strtolower($e['path'])] = $e;
            }
            if (isset($flat['encryptedpackage']) || isset($flat['encryptioninfo'])) {
                return Verdict::fail('CONTENEDOR_PELIGROSO', 'El archivo está cifrado (paquete protegido con contraseña).');
            }
            if (!isset($flat['workbook']) && !isset($flat['book'])) {
                return Verdict::fail('EXTENSION_NO_COINCIDE', 'El contenedor OLE2 no contiene un libro de Excel.');
            }
            $total = 0;
            foreach ($entries as $e) {
                $total += $e['type'] === 2 ? $e['size'] : 0;
            }
            if ($total > $limits->maxUncompressed) {
                return Verdict::fail('CONTENEDOR_PELIGROSO', 'El contenido excede el límite de tamaño.');
            }
            $book = $flat['workbook'] ?? $flat['book'];
            if (self::biffEncrypted($cf->streamHead($book['index'], 8192))) {
                return Verdict::fail('CONTENEDOR_PELIGROSO', 'El libro está cifrado con contraseña (registro FILEPASS).');
            }
            $macros = false;
            foreach (array_keys($flat) as $p) {
                $macros = $macros || str_starts_with($p, '_vba_project_cur') || str_contains('/' . $p . '/', '/vba/');
            }
            return Verdict::ok($macros, ['flujos' => count($entries), 'bytes_flujos' => $total]);
        } catch (Throwable) {
            return Verdict::fail('ARCHIVO_CORRUPTO', 'El contenedor OLE2 está dañado.');
        }
    }

    private static function biffEncrypted(string $head): bool
    {
        $pos = 0;
        for ($i = 0; $i < 64; $i++) {
            if ($pos + 4 > strlen($head)) {
                break;
            }
            ['t' => $type, 'l' => $len] = unpack('vt/vl', substr($head, $pos, 4));
            if ($type === 0x002F) {
                return true;
            }
            if ($type === 0x000A) {
                break;
            }
            $pos += 4 + $len;
        }
        return false;
    }
}
