<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Workbook;

use ConciliaRH\Engine\Support\Py;
use ZipArchive;

final class OoxmlReader
{
    private const EXT_REF = '/\[\d+\]/';
    private const DDE = '/\b[A-Za-z0-9_.]+\|\'?[^!]*\'?!/';
    private const FORMULA_KEEP = 120;
    private const FORMULA_MAX = 8192;
    private const TRANSLATE_MAX = 200_000;
    private const MAX_MERGES = 100_000;
    private const MAX_RELS = 10_000;

    public int $cellsTotal = 0;
    public int $elementsTotal = 0;
    private int $translations = 0;
    private int $lastCol = -1;
    private XmlEvents $xml;
    /** @var array<string,int> */
    private array $sizes = [];

    /** @param callable():void $tick */
    public function __construct(private readonly string $path, private readonly int $maxCells, int $maxXml, callable $tick)
    {
        $this->xml = new XmlEvents($path, $maxXml, $tick);
    }

    public function read(): Workbook
    {
        $zip = new ZipArchive();
        if ($zip->open($this->path, ZipArchive::RDONLY) !== true) {
            throw new AnalysisError('ARCHIVO_CORRUPTO', 'El contenedor OOXML no se puede abrir.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if ($st !== false) {
                $this->sizes[(string) $st['name']] = (int) $st['size'];
            }
        }
        $zip->close();
        $names = $this->sizes;
        $wbPart = $this->workbookPart($names);
        $wb = new Workbook('OOXML');
        foreach (array_keys($names) as $n) {
            if (str_ends_with(strtolower((string) $n), 'vbaproject.bin')) {
                $wb->hasMacros = true;
            }
        }
        $meta = $this->readWorkbook($wbPart, $wb);
        $rels = $this->rels($wbPart);
        foreach ($rels as [$type, , $ext]) {
            if ($ext) {
                $wb->externalRelationships[$type] = ($wb->externalRelationships[$type] ?? 0) + 1;
            }
        }
        $sorted = array_map('strval', array_keys($names));
        sort($sorted, SORT_STRING);
        $wbRels = $this->join(dirname($wbPart) === '.' ? '' : dirname($wbPart), '_rels/' . basename($wbPart) . '.rels');
        foreach ($sorted as $n) {
            if (preg_match('#^xl/externalLinks/externalLink\d+\.xml$#', $n) === 1) {
                $wb->externalLinks++;
                foreach ($this->xml->parse($n, $names[$n]) as [, $tag]) {
                    if ($tag === 'ddeLink' || $tag === 'oleLink') {
                        $wb->ddeLinks++;
                    }
                }
            }
            if (str_ends_with($n, '.rels') && $n !== $wbRels) {
                $seen = 0;
                foreach ($this->xml->parse($n, $names[$n]) as [$ev, $tag, $a]) {
                    if ($ev === 'end' && $tag === 'Relationship') {
                        if (++$seen > self::MAX_RELS) {
                            throw new AnalysisError('ESTRUCTURA_EXCESIVA', 'El libro declara demasiadas relaciones internas.');
                        }
                        if (($a['TargetMode'] ?? '') === 'External') {
                            $t = self::lastSegment($a['Type'] ?? '');
                            $wb->externalRelationships[$t] = ($wb->externalRelationships[$t] ?? 0) + 1;
                        }
                    }
                }
            }
        }
        $shared = $this->sharedStrings($rels, $names);
        $dateStyles = $this->dateStyles($rels, $names);
        foreach ($meta as $idx => [$name, $state, $rid]) {
            $rel = $rels[$rid] ?? null;
            if ($rel === null || $rel[2] || !isset($names[$rel[1]])) {
                throw new AnalysisError('ESTRUCTURA_ILEGIBLE', 'Una hoja declarada no existe en el contenedor.');
            }
            $sheet = new Sheet($idx, $name, match ($state) { 'hidden' => 'OCULTA', 'veryHidden' => 'MUY_OCULTA', default => 'VISIBLE' });
            $this->readSheet($rel[1], $sheet, $shared, $dateStyles, $wb->date1904);
            $wb->sheets[] = $sheet;
        }
        return $wb;
    }

    /** @param array<string,int> $names */
    private function workbookPart(array $names): string
    {
        if (isset($names['_rels/.rels'])) {
            foreach ($this->rels('') as [, $target, $ext]) {
                if (!$ext && str_ends_with($target, '.xml') && str_contains(strtolower($target), 'workbook')) {
                    return $target;
                }
            }
        }
        if (isset($names['xl/workbook.xml'])) {
            return 'xl/workbook.xml';
        }
        throw new AnalysisError('ESTRUCTURA_ILEGIBLE', 'No se encontró el libro principal.');
    }

    /** @return array<string,array{string,string,bool}> */
    private function rels(string $part): array
    {
        $folder = $part === '' ? '' : (str_contains($part, '/') ? substr($part, 0, strrpos($part, '/')) : '');
        $base = $part === '' ? '' : basename($part);
        $relName = $this->join($folder, '_rels/' . $base . '.rels');
        $out = [];
        if (!isset($this->sizes[$relName])) {
            return $out;
        }
        foreach ($this->xml->parse($relName, $this->sizes[$relName]) as [$ev, $tag, $a]) {
            if ($ev === 'end' && $tag === 'Relationship') {
                if (count($out) >= self::MAX_RELS) {
                    throw new AnalysisError('ESTRUCTURA_EXCESIVA', 'El libro declara demasiadas relaciones internas.');
                }
                $ext = ($a['TargetMode'] ?? '') === 'External';
                $target = $a['Target'] ?? '';
                if (!$ext) {
                    $target = str_starts_with($target, '/') ? ltrim($target, '/') : self::normpath($this->join($folder, $target));
                }
                $out[$a['Id'] ?? ''] = [self::lastSegment($a['Type'] ?? ''), $target, $ext];
            }
        }
        return $out;
    }

    /** @return list<array{string,string,string}> */
    private function readWorkbook(string $part, Workbook $wb): array
    {
        $sheets = [];
        foreach ($this->xml->parse($part, $this->sizes[$part] ?? 0) as [$ev, $tag, $a, $text]) {
            if ($ev !== 'end') {
                continue;
            }
            if ($tag === 'sheet') {
                $name = mb_substr($a['name'] ?? '', 0, 255);
                $sheets[] = [$name !== '' ? $name : 'Hoja' . (count($sheets) + 1), $a['state'] ?? 'visible', $a['id'] ?? ''];
            } elseif ($tag === 'workbookPr') {
                $wb->date1904 = in_array($a['date1904'] ?? '', ['1', 'true'], true);
            } elseif ($tag === 'definedName') {
                $wb->definedNames++;
                $t = (string) $text;
                if (preg_match(self::EXT_REF, $t) === 1 || str_contains($t, '|') || preg_match('#https?://|\\\\\\\\#i', $t) === 1) {
                    $wb->suspiciousNames++;
                }
            }
        }
        if ($sheets === []) {
            throw new AnalysisError('ESTRUCTURA_ILEGIBLE', 'El libro no declara hojas.');
        }
        return $sheets;
    }

    /**
     * @param array<string,array{string,string,bool}> $rels
     * @param array<string,int> $names
     * @return list<string>
     */
    private function sharedStrings(array $rels, array $names): array
    {
        $target = null;
        foreach ($rels as [$type, $t, $ext]) {
            if ($type === 'sharedStrings' && !$ext) {
                $target = $t;
                break;
            }
        }
        if ($target === null || !isset($names[$target])) {
            return [];
        }
        $out = [];
        $parts = [];
        $phonetic = 0;
        foreach ($this->xml->parse($target, $names[$target]) as [$ev, $tag, , $text]) {
            if ($tag === 'rPh') {
                $phonetic += $ev === 'start' ? 1 : -1;
            } elseif ($ev === 'end' && $tag === 't' && $phonetic === 0) {
                $parts[] = (string) $text;
            } elseif ($ev === 'end' && $tag === 'si') {
                $out[] = implode('', $parts);
                $parts = [];
                if (count($out) > $this->maxCells) {
                    throw new AnalysisError('ESTRUCTURA_EXCESIVA', 'El libro excede el máximo de celdas que se analizan.');
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string,array{string,string,bool}> $rels
     * @param array<string,int> $names
     * @return array<int,true>
     */
    private function dateStyles(array $rels, array $names): array
    {
        $target = null;
        foreach ($rels as [$type, $t, $ext]) {
            if ($type === 'styles' && !$ext) {
                $target = $t;
                break;
            }
        }
        if ($target === null || !isset($names[$target])) {
            return [];
        }
        $custom = [];
        $xfs = [];
        $inXfs = false;
        foreach ($this->xml->parse($target, $names[$target]) as [$ev, $tag, $a]) {
            if ($tag === 'cellXfs') {
                $inXfs = $ev === 'start';
            } elseif ($ev === 'end' && $tag === 'numFmt') {
                $id = $a['numFmtId'] ?? '-1';
                if (preg_match('/^-?\d+$/', $id) === 1) {
                    $custom[(int) $id] = $a['formatCode'] ?? '';
                }
            } elseif ($ev === 'end' && $tag === 'xf' && $inXfs) {
                $id = $a['numFmtId'] ?? '0';
                $xfs[] = preg_match('/^-?\d+$/', $id) === 1 ? (int) $id : 0;
            }
        }
        $dates = [];
        foreach ($xfs as $i => $fmtId) {
            $code = ($custom[$fmtId] ?? '') !== '' ? $custom[$fmtId] : DateFormat::builtin($fmtId);
            if (DateFormat::isBuiltinDateId($fmtId) || ($code !== '' && DateFormat::isDateFormat($code))) {
                $dates[$i] = true;
            }
        }
        return $dates;
    }

    /**
     * @param list<string> $shared
     * @param array<int,true> $dateStyles
     */
    private function readSheet(string $part, Sheet $sheet, array $shared, array $dateStyles, bool $date1904): void
    {
        $sharedFormulas = [];
        foreach ($this->rels($part) as [$type, , $ext]) {
            if ($ext && $type === 'hyperlink') {
                $sheet->externalHyperlinks++;
            }
        }
        $row = null;
        $rowCounter = 0;
        $ref = $t = $s = null;
        $inCell = false;
        $flags = [false, false];
        $v = null;
        $f = null;
        $fSeen = false;
        $isText = [];
        foreach ($this->xml->parse($part, $this->sizes[$part] ?? 0) as [$ev, $tag, $a, $text]) {
            if ($ev === 'start') {
                if ($tag === 'row') {
                    $rowCounter++;
                    $r = $a['r'] ?? null;
                    $rIdx = $r !== null && preg_match('/^\s*[+-]?\d+\s*$/', $r) === 1 ? (int) $r : $rowCounter;
                    $rowCounter = $rIdx;
                    $hidden = in_array($a['hidden'] ?? '', ['1', 'true'], true);
                    if ($hidden) {
                        $sheet->hiddenRows++;
                    }
                    $row = new Row($rIdx, $hidden);
                    $this->lastCol = -1;
                } elseif ($tag === 'c') {
                    [$ref, $t, $s] = [$a['r'] ?? null, $a['t'] ?? 'n', $a['s'] ?? null];
                    $v = $f = null;
                    $fSeen = false;
                    $flags = [false, false];
                    $inCell = true;
                    $isText = [];
                }
                continue;
            }
            if ($tag === 'v') {
                $v = $text;
            } elseif ($tag === 'f') {
                $fSeen = true;
                $txt = $text;
                if ($txt !== null && mb_strlen($txt) > self::FORMULA_MAX) {
                    throw new AnalysisError('ESTRUCTURA_EXCESIVA', 'Una fórmula excede la longitud máxima de Excel.');
                }
                $si = $a['si'] ?? null;
                if (($a['t'] ?? null) === 'shared' && $si !== null) {
                    if ($txt !== null && $txt !== '') {
                        $sharedFormulas[$si] = [$txt, $ref ?? ''];
                    } else {
                        $txt = $this->translate($sharedFormulas[$si] ?? null, $ref);
                    }
                }
                $full = (string) $txt;
                $flags = [preg_match(self::EXT_REF, $full) === 1, preg_match(self::DDE, $full) === 1];
                $f = mb_substr($full, 0, self::FORMULA_KEEP);
            } elseif ($tag === 't' && $inCell) {
                $isText[] = (string) $text;
            } elseif ($tag === 'c' && $row !== null) {
                $this->addCell($sheet, $row, $ref, $t, $s, $v, $fSeen ? $f : null, $isText, $shared, $dateStyles, $date1904, $flags);
                $ref = null;
                $inCell = false;
            } elseif ($tag === 'row' && $row !== null) {
                if ($row->cells !== []) {
                    $sheet->rows[] = $row;
                }
                $row = null;
            } elseif ($tag === 'dimension') {
                $d = mb_substr($a['ref'] ?? '', 0, 40);
                $sheet->dimension = $d !== '' ? $d : null;
            } elseif ($tag === 'sheetPr') {
                $sheet->filterMode = in_array($a['filterMode'] ?? '', ['1', 'true'], true);
            } elseif ($tag === 'col' && in_array($a['hidden'] ?? '', ['1', 'true'], true)) {
                $lo = $a['min'] ?? '0';
                $hi = $a['max'] ?? '0';
                if (preg_match('/^-?\d+$/', $lo) === 1 && preg_match('/^-?\d+$/', $hi) === 1) {
                    for ($c = (int) $lo - 1; $c < min((int) $hi, 16384); $c++) {
                        $sheet->hiddenCols[$c] = true;
                    }
                }
            } elseif ($tag === 'autoFilter') {
                $af = mb_substr($a['ref'] ?? '', 0, 40);
                $sheet->autofilter = $af !== '' ? $af : '?';
            } elseif ($tag === 'mergeCell' && count($sheet->merges) < self::MAX_MERGES) {
                $b = Coordinates::rangeBoundaries($a['ref'] ?? '');
                if ($b !== null) {
                    $sheet->merges[] = [$b[1], $b[0] - 1, $b[3], $b[2] - 1];
                }
            }
        }
    }

    /** @param array{string,string}|null $master */
    private function translate(?array $master, ?string $dest): string
    {
        if ($master === null || $master[1] === '' || $dest === null || $dest === '') {
            return $master[0] ?? '';
        }
        if (mb_strlen($master[0]) > 256 || preg_match('/^\s*\+?\s*(?:_XLFN\.)?(SUM|SUBTOTAL|AGGREGATE)\s*\(/i', $master[0]) !== 1) {
            return $master[0];
        }
        if (++$this->translations > self::TRANSLATE_MAX) {
            return $master[0];
        }
        return FormulaTranslator::translate($master[0], $master[1], $dest) ?? $master[0];
    }

    /**
     * @param list<string> $isText
     * @param list<string> $shared
     * @param array<int,true> $dateStyles
     * @param array{bool,bool} $flags
     */
    private function addCell(Sheet $sheet, Row $row, ?string $ref, ?string $t, ?string $s, ?string $v, ?string $formula, array $isText,
                             array $shared, array $dateStyles, bool $date1904, array $flags): void
    {
        if (++$this->elementsTotal > 4 * $this->maxCells) {
            throw new AnalysisError('ESTRUCTURA_EXCESIVA', 'El libro declara demasiadas celdas.');
        }
        $col = $this->col($ref);
        if ($formula !== null) {
            $sheet->formulas = ($sheet->formulas ?? 0) + 1;
            $sheet->externalFormulas += $flags[0] ? 1 : 0;
            $sheet->ddeFormulas += $flags[1] ? 1 : 0;
            if ($v === null && $t !== 'inlineStr') {
                $sheet->formulasWithoutCache = ($sheet->formulasWithoutCache ?? 0) + 1;
            }
        }
        $value = null;
        $isDate = false;
        if ($t === 's') {
            $value = $v !== null && preg_match('/^\s*[+-]?\d+\s*$/', $v) === 1 ? ($shared[(int) $v] ?? null) : null;
        } elseif ($t === 'inlineStr') {
            $value = implode('', $isText);
        } elseif ($t === 'str') {
            $value = $v;
        } elseif ($t === 'b') {
            $value = $v === null ? null : in_array($v, ['1', 'true'], true);
        } elseif ($t === 'e') {
            $value = new ErrorValue($v ?? '#ERROR');
        } elseif ($t === 'd') {
            $value = $v;
        } elseif ($v !== null) {
            $value = self::number($v);
            if ($value !== null && $s !== null && ctype_digit($s) && isset($dateStyles[(int) $s])) {
                $d = DateFormat::serialToDate((float) $value, $date1904);
                if ($d !== null) {
                    $value = $d;
                    $isDate = true;
                }
            }
        }
        if ($value === null && $formula === null) {
            return;
        }
        if (is_string($value) && Py::strip($value) === '' && $formula === null) {
            return;
        }
        $row->cells[$col] = new Cell($value, $formula, $isDate);
        $sheet->cellsNonempty++;
        if (++$this->cellsTotal > $this->maxCells) {
            throw new AnalysisError('ESTRUCTURA_EXCESIVA', 'El libro excede el máximo de celdas que se analizan.');
        }
    }

    private static function number(string $v): int|float|null
    {
        if (preg_match('/^-?\d+$/', $v) === 1) {
            $i = filter_var($v, FILTER_VALIDATE_INT);
            return $i === false ? (float) $v : $i;
        }
        $t = trim($v);
        if (preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/', $t) === 1) {
            return (float) $t;
        }
        if (preg_match('/^([+-]?)(inf|infinity|nan)$/i', $t, $m) === 1) {
            return strtolower($m[2]) === 'nan' ? NAN : ($m[1] === '-' ? -INF : INF);
        }
        return null;
    }

    private function col(?string $ref): int
    {
        if ($ref !== null && preg_match('/^([A-Z]{1,3})(\d+)$/', $ref, $m) === 1) {
            return $this->lastCol = Coordinates::index($m[1]) - 1;
        }
        return ++$this->lastCol;
    }

    private function join(string $folder, string $rel): string
    {
        return $folder === '' ? $rel : $folder . '/' . $rel;
    }

    private static function normpath(string $p): string
    {
        $out = [];
        foreach (explode('/', $p) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if ($out !== [] && end($out) !== '..') {
                    array_pop($out);
                } else {
                    $out[] = '..';
                }
                continue;
            }
            $out[] = $seg;
        }
        return implode('/', $out);
    }

    private static function lastSegment(string $type): string
    {
        $p = strrpos($type, '/');
        return $p === false ? $type : substr($type, $p + 1);
    }
}
