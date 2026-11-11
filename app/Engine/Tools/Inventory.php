<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Tools;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

final class Inventory
{
    private const EXTENSIONS = ['.xls', '.xlsx', '.xlsm', '.xlsb'];

    public static function signature(string $head): string
    {
        return match (true) {
            str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") => 'OLE2',
            str_starts_with($head, "PK\x03\x04") => 'OOXML',
            default => 'DESCONOCIDA',
        };
    }

    public static function hasMacros(string $path): ?bool
    {
        $z = new ZipArchive();
        if ($z->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }
        try {
            for ($i = 0; $i < $z->numFiles; $i++) {
                if (str_ends_with(strtolower((string) $z->getNameIndex($i)), 'vbaproject.bin')) {
                    return true;
                }
            }
            return false;
        } finally {
            $z->close();
        }
    }

    /** @return list<array<string,mixed>> */
    public static function scan(string $root): array
    {
        $base = realpath($root);
        if ($base === false || !is_dir($base)) {
            throw new RuntimeException("No es un directorio: $root");
        }
        $paths = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $f) {
            $ext = strtolower('.' . $f->getExtension());
            if ($f->isFile() && in_array($ext, self::EXTENSIONS, true) && !str_starts_with($f->getFilename(), '~$')) {
                $paths[] = $f->getPathname();
            }
        }
        sort($paths, SORT_STRING);
        $entries = [];
        foreach ($paths as $path) {
            $fh = fopen($path, 'rb');
            $head = (string) fread($fh, 8);
            fclose($fh);
            $sig = self::signature($head);
            $ext = strtolower('.' . pathinfo($path, PATHINFO_EXTENSION));
            $entries[] = [
                'ruta_relativa' => substr($path, strlen($base) + 1),
                'nombre' => basename($path),
                'extension' => $ext,
                'tamano' => filesize($path),
                'sha256' => hash_file('sha256', $path),
                'firma' => $sig,
                'tiene_macros' => $sig === 'OOXML' ? self::hasMacros($path) : false,
                'extension_coherente' => ($sig === 'OLE2' && $ext === '.xls') || ($sig === 'OOXML' && in_array($ext, ['.xlsx', '.xlsm', '.xlsb'], true)),
            ];
        }
        return $entries;
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @return array<string,mixed>
     */
    public static function analyze(array $entries): array
    {
        $byName = [];
        $byHash = [];
        foreach ($entries as $e) {
            $byName[$e['nombre']][] = $e;
            $byHash[$e['sha256']][] = $e;
        }
        ksort($byName, SORT_STRING);
        ksort($byHash, SORT_STRING);
        $sameName = [];
        foreach ($byName as $name => $group) {
            if (count(array_unique(array_column($group, 'sha256'))) > 1) {
                $sameName[$name] = array_map(static fn ($e) => ['ruta' => $e['ruta_relativa'], 'sha256' => $e['sha256'], 'tamano' => $e['tamano']], $group);
            }
        }
        $copies = [];
        foreach ($byHash as $h => $group) {
            if (count($group) > 1) {
                $copies[substr((string) $h, 0, 16)] = array_column($group, 'ruta_relativa');
            }
        }
        $sigs = array_unique(array_column($entries, 'firma'));
        sort($sigs, SORT_STRING);
        $porFirma = [];
        foreach ($sigs as $s) {
            $porFirma[$s] = count(array_filter($entries, static fn ($e) => $e['firma'] === $s));
        }
        return [
            'total_archivos' => count($entries),
            'hashes_unicos' => count($byHash),
            'por_firma' => $porFirma === [] ? new \stdClass() : $porFirma,
            'con_macros' => count(array_filter($entries, static fn ($e) => $e['tiene_macros'] === true)),
            'extension_incoherente' => array_values(array_map(static fn ($e) => $e['ruta_relativa'], array_filter($entries, static fn ($e) => !$e['extension_coherente']))),
            'mismo_nombre_distinto_hash' => $sameName === [] ? new \stdClass() : $sameName,
            'copias_identicas' => $copies === [] ? new \stdClass() : $copies,
            'archivos' => $entries,
        ];
    }

    public static function checkOutputPath(string $output, string $source, string $repo): void
    {
        if (self::within($output, $source)) {
            throw new RuntimeException('La salida no puede escribirse dentro de la carpeta de origen (sólo lectura).');
        }
        if (self::within($output, $repo) && !self::within($output, rtrim($repo, '/') . '/storage')) {
            throw new RuntimeException('La salida no puede quedar dentro del repositorio (use storage/ o una ruta externa).');
        }
    }

    /** @param array<string,mixed> $report */
    public static function toMarkdown(array $report): string
    {
        $porFirma = [];
        foreach ((array) $report['por_firma'] as $k => $v) {
            $porFirma[] = "'$k': $v";
        }
        $lines = ['# Inventario por SHA-256', '',
                  "- Archivos: {$report['total_archivos']} · hashes únicos: {$report['hashes_unicos']}",
                  '- Por firma: {' . implode(', ', $porFirma) . "} · con macros: {$report['con_macros']}", '',
                  '## Mismo nombre, distinto SHA-256', ''];
        foreach ((array) $report['mismo_nombre_distinto_hash'] as $name => $group) {
            $lines[] = "- **$name**";
            foreach ($group as $g) {
                $lines[] = '  - `' . substr($g['sha256'], 0, 16) . "…` {$g['tamano']} B — {$g['ruta']}";
            }
        }
        $lines[] = '';
        $lines[] = '## Copias idénticas (mismo SHA-256)';
        $lines[] = '';
        foreach ((array) $report['copias_identicas'] as $h => $paths) {
            $lines[] = "- `$h…`: " . implode(' | ', $paths);
        }
        return implode("\n", $lines) . "\n";
    }

    public static function main(string $source, ?string $output, string $repo): string
    {
        $report = self::analyze(self::scan($source));
        $json = (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($output === null) {
            return $json . "\n";
        }
        self::checkOutputPath($output, $source, $repo);
        if (!is_dir(dirname($output))) {
            mkdir(dirname($output), 0775, true);
        }
        file_put_contents($output, str_ends_with($output, '.md') ? self::toMarkdown($report) : $json);
        return "Inventario escrito en $output ({$report['total_archivos']} archivos)\n";
    }

    private static function within(string $child, string $parent): bool
    {
        $c = self::resolve($child);
        $p = self::resolve($parent);
        return $c === $p || str_starts_with($c, rtrim($p, '/') . '/');
    }

    private static function resolve(string $path): string
    {
        $abs = str_starts_with($path, '/') ? $path : getcwd() . '/' . $path;
        $suffix = [];
        $cur = $abs;
        while (($real = realpath($cur)) === false && $cur !== '/' && $cur !== '') {
            array_unshift($suffix, basename($cur));
            $cur = dirname($cur);
        }
        $out = [];
        foreach (explode('/', ($real ?: '') . '/' . implode('/', $suffix)) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }
        return '/' . implode('/', $out);
    }
}
