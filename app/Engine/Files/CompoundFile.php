<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Files;

use RuntimeException;

final class CompoundFile
{
    public const MAGIC = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    private const FREE = 0xFFFFFFFF;
    private const END = 0xFFFFFFFE;
    private const MAX_CHAIN = 2_000_000;

    private string $data;
    private int $sectorSize;
    private int $miniSectorSize;
    private int $miniCutoff;
    /** @var list<int> */
    private array $fat = [];
    /** @var list<int> */
    private array $miniFat = [];
    /** @var list<array{name:string,type:int,left:int,right:int,child:int,start:int,size:int}> */
    private array $entries = [];
    private ?string $miniStream = null;

    public function __construct(string $path)
    {
        $data = @file_get_contents($path);
        if ($data === false || strlen($data) < 512 || !str_starts_with($data, self::MAGIC)) {
            throw new RuntimeException('No es un contenedor OLE2');
        }
        $this->data = $data;
        $major = $this->u16(0x1A);
        $shift = $this->u16(0x1E);
        if ($this->u16(0x1C) !== 0xFFFE || !in_array($shift, [9, 12], true) || ($major === 3 && $shift !== 9) || ($major === 4 && $shift !== 12)) {
            throw new RuntimeException('Encabezado OLE2 inválido');
        }
        $this->sectorSize = 1 << $shift;
        $this->miniSectorSize = 1 << $this->u16(0x20);
        $this->miniCutoff = $this->u32(0x38);
        $this->loadFat();
        $this->loadDirectory($this->u32(0x30));
        $this->loadMiniFat($this->u32(0x3C));
    }

    public static function isOle(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $head = (string) fread($fh, 8);
        fclose($fh);
        return $head === self::MAGIC && (int) @filesize($path) >= 1536;
    }

    /** @return list<array{path:string,type:int,size:int,index:int}> */
    public function listing(): array
    {
        $out = [];
        $root = $this->entries[0] ?? null;
        if ($root === null || $root['type'] !== 5) {
            throw new RuntimeException('Sin entrada raíz');
        }
        $seen = [];
        $this->walk($root['child'], '', $out, $seen, 0);
        return $out;
    }

    public function streamHead(int $index, int $bytes): string
    {
        $e = $this->entries[$index] ?? throw new RuntimeException('Flujo inexistente');
        $size = min($e['size'], $bytes);
        if ($e['size'] < $this->miniCutoff) {
            $mini = $this->miniStream();
            $out = '';
            $s = $e['start'];
            $guard = 0;
            while ($s !== self::END && strlen($out) < $size) {
                if (++$guard > self::MAX_CHAIN || !isset($this->miniFat[$s])) {
                    throw new RuntimeException('Cadena mini inválida');
                }
                $out .= substr($mini, $s * $this->miniSectorSize, $this->miniSectorSize);
                $s = $this->miniFat[$s];
            }
            return substr($out, 0, $size);
        }
        return substr($this->chain($e['start'], $size), 0, $size);
    }

    public function fileOffset(int $index, int $pos): int
    {
        $e = $this->entries[$index] ?? throw new RuntimeException('Flujo inexistente');
        if ($e['size'] < $this->miniCutoff) {
            $sector = $e['start'];
            for ($i = 0; $i < intdiv($pos, $this->miniSectorSize); $i++) {
                $sector = $this->miniFat[$sector] ?? throw new RuntimeException('Cadena mini inválida');
            }
            $containerPos = $sector * $this->miniSectorSize + $pos % $this->miniSectorSize;
            return $this->chainOffset($this->entries[0]['start'], $containerPos);
        }
        return $this->chainOffset($e['start'], $pos);
    }

    private function chainOffset(int $start, int $pos): int
    {
        $sector = $start;
        for ($i = 0; $i < intdiv($pos, $this->sectorSize); $i++) {
            $sector = $this->fat[$sector] ?? throw new RuntimeException('Cadena FAT inválida');
        }
        return $this->offset($sector) + $pos % $this->sectorSize;
    }

    /**
     * @param list<array{path:string,type:int,size:int,index:int}> $out
     * @param array<int,bool> $seen
     */
    private function walk(int $id, string $prefix, array &$out, array &$seen, int $depth): void
    {
        if ($id === self::FREE || $id < 0 || $depth > 64) {
            return;
        }
        if (isset($seen[$id]) || !isset($this->entries[$id])) {
            throw new RuntimeException('Directorio OLE2 inválido');
        }
        $seen[$id] = true;
        $e = $this->entries[$id];
        $this->walk($e['left'], $prefix, $out, $seen, $depth + 1);
        if (in_array($e['type'], [1, 2], true)) {
            $path = $prefix === '' ? $e['name'] : $prefix . '/' . $e['name'];
            $out[] = ['path' => $path, 'type' => $e['type'], 'size' => $e['size'], 'index' => $id];
            if ($e['type'] === 1) {
                $this->walk($e['child'], $path, $out, $seen, $depth + 1);
            }
        }
        $this->walk($e['right'], $prefix, $out, $seen, $depth + 1);
    }

    private function loadFat(): void
    {
        $sectors = [];
        for ($i = 0; $i < 109; $i++) {
            $s = $this->u32(0x4C + 4 * $i);
            if ($s !== self::FREE) {
                $sectors[] = $s;
            }
        }
        $difat = $this->u32(0x44);
        $guard = 0;
        $per = intdiv($this->sectorSize, 4) - 1;
        while ($difat !== self::END && $difat !== self::FREE) {
            if (++$guard > 100_000) {
                throw new RuntimeException('DIFAT inválida');
            }
            $off = $this->offset($difat);
            for ($i = 0; $i < $per; $i++) {
                $s = $this->u32($off + 4 * $i);
                if ($s !== self::FREE) {
                    $sectors[] = $s;
                }
            }
            $difat = $this->u32($off + 4 * $per);
        }
        foreach ($sectors as $s) {
            $off = $this->offset($s);
            $chunk = substr($this->data, $off, $this->sectorSize);
            if (strlen($chunk) !== $this->sectorSize) {
                throw new RuntimeException('FAT truncada');
            }
            foreach (unpack('V*', $chunk) as $v) {
                $this->fat[] = $v;
            }
        }
    }

    private function loadDirectory(int $start): void
    {
        $raw = $this->chain($start, PHP_INT_MAX);
        $n = intdiv(strlen($raw), 128);
        for ($i = 0; $i < $n; $i++) {
            $d = substr($raw, $i * 128, 128);
            $len = min(64, unpack('v', substr($d, 0x40, 2))[1]);
            $name = $len > 2 ? (string) mb_convert_encoding(substr($d, 0, $len - 2), 'UTF-8', 'UTF-16LE') : '';
            $u = unpack('Vleft/Vright/Vchild', substr($d, 0x44, 12));
            $this->entries[] = ['name' => $name, 'type' => ord($d[0x42]), 'left' => $u['left'], 'right' => $u['right'],
                                'child' => $u['child'], 'start' => unpack('V', substr($d, 0x74, 4))[1],
                                'size' => unpack('V', substr($d, 0x78, 4))[1]];
        }
    }

    private function loadMiniFat(int $start): void
    {
        if ($start === self::END || $start === self::FREE) {
            return;
        }
        foreach (unpack('V*', $this->chain($start, PHP_INT_MAX)) ?: [] as $v) {
            $this->miniFat[] = $v;
        }
    }

    private function miniStream(): string
    {
        return $this->miniStream ??= $this->chain($this->entries[0]['start'], $this->entries[0]['size']);
    }

    private function chain(int $start, int $limit): string
    {
        $out = '';
        $s = $start;
        $guard = 0;
        while ($s !== self::END && $s !== self::FREE && strlen($out) < $limit) {
            if (++$guard > self::MAX_CHAIN || !isset($this->fat[$s])) {
                throw new RuntimeException('Cadena FAT inválida');
            }
            $chunk = substr($this->data, $this->offset($s), $this->sectorSize);
            if ($chunk === '') {
                throw new RuntimeException('Sector fuera del archivo');
            }
            $out .= $chunk;
            $s = $this->fat[$s];
        }
        return $out;
    }

    private function offset(int $sector): int
    {
        return ($sector + 1) * $this->sectorSize;
    }

    private function u16(int $pos): int
    {
        return unpack('v', substr($this->data, $pos, 2))[1];
    }

    private function u32(int $pos): int
    {
        return unpack('V', substr($this->data, $pos, 4))[1];
    }
}
