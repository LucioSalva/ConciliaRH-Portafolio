<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Workbook;

use ConciliaRH\Engine\Support\Py;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

final class XlsReader
{
    private const NON_DATE = ['0.00E+00', '##0.0E+0', 'General', 'GENERAL', 'general', '@'];

    public int $cellsTotal = 0;
    public int $elementsTotal = 0;

    /** @param callable():void $tick */
    public function __construct(private readonly string $path, private readonly int $maxCells, private $tick)
    {
    }

    public function read(bool $hasMacros): Workbook
    {
        try {
            $reader = new Xls();
            $reader->setReadEmptyCells(false);
            $book = $reader->load($this->path);
        } catch (Throwable) {
            throw new AnalysisError('ARCHIVO_CORRUPTO', 'El libro .xls no se puede interpretar.');
        }
        try {
            $wb = new Workbook('OLE2');
            $wb->hasMacros = $hasMacros;
            $wb->date1904 = $book->getExcelCalendar() === \PhpOffice\PhpSpreadsheet\Shared\Date::CALENDAR_MAC_1904;
            $wb->definedNames = count($book->getDefinedNames());
            foreach ($book->getAllSheets() as $idx => $ws) {
                $wb->sheets[] = $this->sheet($idx, $ws, $wb->date1904);
            }
            return $wb;
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function sheet(int $idx, Worksheet $ws, bool $date1904): Sheet
    {
        $title = $ws->getTitle();
        $sheet = new Sheet($idx, Py::sub($title !== '' ? $title : 'Hoja' . ($idx + 1), 255), match ($ws->getSheetState()) {
            Worksheet::SHEETSTATE_HIDDEN => 'OCULTA', Worksheet::SHEETSTATE_VERYHIDDEN => 'MUY_OCULTA', default => 'VISIBLE'});
        $sheet->formulas = null;
        $sheet->formulasWithoutCache = null;
        $sheet->autofilter = $ws->getAutoFilter()->getRange() !== '' ? '_FilterDatabase' : null;
        $hidden = [];
        foreach ($ws->getRowDimensions() as $n => $rd) {
            if (!$rd->getVisible()) {
                $hidden[(int) $n] = true;
            }
        }
        $sheet->hiddenRows = count($hidden);
        foreach ($ws->getColumnDimensions() as $letter => $cd) {
            if (!$cd->getVisible()) {
                $sheet->hiddenCols[Coordinate::columnIndexFromString((string) $letter) - 1] = true;
            }
        }
        foreach (array_slice(array_values($ws->getMergeCells()), 0, 100_000) as $ref) {
            $b = Coordinates::rangeBoundaries((string) $ref);
            if ($b !== null) {
                $sheet->merges[] = [$b[1], $b[0] - 1, $b[3], $b[2] - 1];
            }
        }
        $byRow = [];
        $maxRow = 0;
        $maxCol = 0;
        foreach ($ws->getCellCollection()->getCoordinates() as $coord) {
            [$col, $row] = Coordinate::coordinateFromString($coord);
            $c = Coordinate::columnIndexFromString($col) - 1;
            $byRow[(int) $row][$c] = $coord;
            $maxRow = max($maxRow, (int) $row);
            $maxCol = max($maxCol, $c + 1);
        }
        ksort($byRow);
        $sheet->dimension = $maxRow . 'x' . $maxCol;
        $processed = 0;
        foreach ($byRow as $r => $cols) {
            ksort($cols);
            $cells = [];
            $this->elementsTotal += count($cols);
            if ($this->elementsTotal > 4 * $this->maxCells) {
                throw new AnalysisError('ESTRUCTURA_EXCESIVA', 'El libro declara demasiadas celdas.');
            }
            foreach ($cols as $c => $coord) {
                $cell = $this->cell($ws, $coord, $date1904);
                if ($cell !== null) {
                    $cells[$c] = $cell;
                }
            }
            if ($cells !== []) {
                $sheet->rows[] = new Row($r, isset($hidden[$r]), $cells);
                $sheet->cellsNonempty += count($cells);
                $this->cellsTotal += count($cells);
                if ($this->cellsTotal > $this->maxCells) {
                    throw new AnalysisError('ESTRUCTURA_EXCESIVA', 'El libro excede el máximo de celdas que se analizan.');
                }
            }
            if (++$processed % 2000 === 0) {
                ($this->tick)();
            }
        }
        return $sheet;
    }

    private function cell(Worksheet $ws, string $coord, bool $date1904): ?Cell
    {
        $c = $ws->getCell($coord);
        $type = $c->getDataType();
        $v = $c->getValue();
        if ($type === DataType::TYPE_FORMULA) {
            $v = $c->getOldCalculatedValue();
            $type = match (true) {
                $v === null => DataType::TYPE_NULL,
                is_bool($v) => DataType::TYPE_BOOL,
                is_int($v) || is_float($v) => DataType::TYPE_NUMERIC,
                is_string($v) && in_array($v, DataType::getErrorCodes(), true) => DataType::TYPE_ERROR,
                default => DataType::TYPE_STRING,
            };
        }
        if ($v === null || $type === DataType::TYPE_NULL) {
            return null;
        }
        if ($type === DataType::TYPE_ERROR) {
            return new Cell(new ErrorValue((string) $v));
        }
        if ($type === DataType::TYPE_BOOL) {
            return new Cell((bool) $v);
        }
        if (is_int($v) || is_float($v)) {
            $f = (float) $v;
            if (self::isDateFormat($c->getStyle()->getNumberFormat()->getFormatCode())) {
                $d = DateFormat::xlrdDate($f, $date1904);
                return $d !== null ? new Cell($d, null, true) : new Cell($f);
            }
            return new Cell(floor($f) === $f && abs($f) < 2 ** 53 ? (int) $f : $f);
        }
        if ($v instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $v = $v->getPlainText();
        }
        $s = (string) $v;
        return Py::strip($s) === '' ? null : new Cell($s);
    }

    public static function isDateFormat(?string $fmt): bool
    {
        if ($fmt === null) {
            return false;
        }
        $state = 0;
        $s = '';
        foreach (mb_str_split($fmt) as $ch) {
            if ($state === 0) {
                if ($ch === '"') {
                    $state = 1;
                } elseif (str_contains('\\_*', $ch)) {
                    $state = 2;
                } elseif (!in_array($ch, ['$', '-', '+', '/', '(', ')', ':', ' '], true)) {
                    $s .= $ch;
                }
            } elseif ($state === 1) {
                if ($ch === '"') {
                    $state = 0;
                }
            } else {
                $state = 0;
            }
        }
        $s = (string) preg_replace('/\[[^]]*\]/u', '', $s);
        if (in_array($s, self::NON_DATE, true)) {
            return false;
        }
        $date = $num = 0;
        foreach (mb_str_split($s) as $ch) {
            if (str_contains('ymdhsYMDHS', $ch)) {
                $date += 5;
            } elseif (str_contains('0#?', $ch)) {
                $num += 5;
            }
        }
        if ($date && !$num) {
            return true;
        }
        if ($num && !$date) {
            return false;
        }
        return $date > $num;
    }
}
