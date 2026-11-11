<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Reports;

use ConciliaRH\Core\View;
use ConciliaRH\Domain\Conciliation\Exporter;
use Dompdf\Dompdf;
use Dompdf\Options as PdfOptions;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;

/**
 * Renderizado de reportes a XLSX (OpenSpout, streaming a archivo temporal), CSV (UTF-8 con BOM, coma, RFC 4180, CRLF) y
 * PDF (dompdf, local, SIN recursos remotos ni sistema de archivos). Todo archivo se genera fuera de public/ y se borra
 * al terminar de transmitirse.
 *
 * Inyección de fórmulas: el texto que empieza con = + - @ TAB CR se neutraliza (Exporter::text) y en XLSX se crean
 * SIEMPRE celdas StringCell/NumericCell explícitas: Cell::fromValue() de OpenSpout convierte "=..." en FÓRMULA.
 */
final class ReportRenderer
{
    public function __construct(private readonly View $view, private readonly string $workDir, private readonly string $basePath)
    {
    }

    /** @param array<string,mixed> $data */
    public function xlsx(array $data, string $usuario, string $fecha): string
    {
        $path = $this->tmp('xlsx');
        $opt = new XlsxOptions();
        $opt->setTempFolder($this->workDir);
        $w = new XlsxWriter($opt);
        $w->openToFile($path);
        $title = (new Style())->setFontBold()->setFontSize(14)->setFontColor('691C32');
        $sub = (new Style())->setFontColor('4A4A4A');
        $head = (new Style())->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('691C32')->setShouldWrapText();
        $tot = (new Style())->setFontBold()->setBackgroundColor('ECD798');
        $money = (new Style())->setFormat('#,##0.00');
        $moneyTot = (new Style())->setFormat('#,##0.00')->setFontBold()->setBackgroundColor('ECD798');
        $first = true;
        $names = [];
        foreach ($data['secciones'] as $s) {
            $sheet = $first ? $w->getCurrentSheet() : $w->addNewSheetAndMakeItCurrent();
            $first = false;
            $sheet->setName($this->sheetName($s['titulo'], $names));
            foreach ($this->headerLines($data, $usuario, $fecha) as $i => $line) {
                $w->addRow(new Row([new Cell\StringCell(Exporter::text($line), $i === 1 ? $title : $sub)]));
            }
            $w->addRow(new Row([new Cell\StringCell(Exporter::text($s['titulo']), $title)]));
            if ($s['nota']) {
                $w->addRow(new Row([new Cell\StringCell(Exporter::text($s['nota']), $sub)]));
            }
            $headerRow = count($this->headerLines($data, $usuario, $fecha)) + 1 + ($s['nota'] ? 1 : 0) + 1;
            $w->addRow(new Row(array_map(static fn ($c) => new Cell\StringCell(Exporter::text($c[0]), $head), $s['columnas'])));
            foreach ($s['columnas'] as $i => [$label, $type]) {
                $sheet->setColumnWidth(match ($type) { 'money' => 16.0, 'int', 'pct' => 11.0, default => in_array($label, ['Nombre', 'Descripción', 'Qué cambió', 'Nombre en el archivo'], true) ? 42.0 : 24.0 }, $i + 1);
            }
            $sheet->setSheetView((new SheetView())->setFreezeRow($headerRow + 1));
            foreach ($s['filas'] as $f) {
                $w->addRow(new Row($this->cells($f, $s['columnas'], null, $money)));
            }
            if ($s['totales'] !== null) {
                $w->addRow(new Row($this->cells($s['totales'], $s['columnas'], $tot, $moneyTot)));
            }
        }
        $w->close();
        return $path;
    }

    /** @param array<string,mixed> $data */
    public function csv(array $data, string $usuario, string $fecha): string
    {
        $path = $this->tmp('csv');
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new RuntimeException('No se pudo generar el CSV.');
        }
        fwrite($fh, "\xEF\xBB\xBF");
        foreach ($this->headerLines($data, $usuario, $fecha) as $line) {
            fputcsv($fh, [Exporter::text($line)], ',', '"', '', "\r\n");
        }
        foreach ($data['secciones'] as $s) {
            fputcsv($fh, [], ',', '"', '', "\r\n");
            fputcsv($fh, [Exporter::text($s['titulo'])], ',', '"', '', "\r\n");
            fputcsv($fh, array_map(static fn ($c) => Exporter::text($c[0]), $s['columnas']), ',', '"', '', "\r\n");
            foreach ([...$s['filas'], ...($s['totales'] !== null ? [$s['totales']] : [])] as $f) {
                $out = [];
                foreach ($s['columnas'] as $i => [, $type]) {
                    $v = $f[$i] ?? null;
                    $out[] = in_array($type, ['money', 'int'], true) && self::isNumber($v) ? (string) $v : Exporter::text($v === null ? '' : (string) $v);
                }
                fputcsv($fh, $out, ',', '"', '', "\r\n");
            }
        }
        fclose($fh);
        return $path;
    }

    /** @param array<string,mixed> $data */
    public function pdf(array $data, string $usuario, string $fecha): string
    {
        $opt = self::pdfOptions($this->workDir);
        $html = $this->view->render('reports/pdf', ['r' => $data, 'usuario' => $usuario, 'fecha' => $fecha, 'logo' => $this->logo(),
                                                    'sello' => ReportService::seal($data['conciliacion'])]);
        $pdf = new Dompdf($opt);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $canvas->page_text(36, 580, 'ConciliaRH · reporte ' . $data['tipo'] . ' · conciliación #' . $data['conciliacion']['id'] . ' v'
            . $data['conciliacion']['numero'] . ' · huella ' . substr($data['huella'], 0, 16) . '… · página {PAGE_NUM} de {PAGE_COUNT}', null, 7, [0.4, 0.4, 0.4]);
        $path = $this->tmp('pdf');
        file_put_contents($path, $pdf->output());
        return $path;
    }

    /** Opciones endurecidas de dompdf (sin red, sin file://, sin PHP ni JS; chroot a un directorio de trabajo vacío). */
    public static function pdfOptions(string $workDir): PdfOptions
    {
        $opt = new PdfOptions();
        $opt->setIsRemoteEnabled(false);                 // sin http(s)://, ftp://
        $opt->setIsPhpEnabled(false);
        $opt->setIsJavascriptEnabled(false);
        $opt->setIsHtml5ParserEnabled(true);
        $opt->setAllowedProtocols(['data://' => ['rules' => []]]);   // ni file:// ni red: sólo el logo embebido
        $opt->setChroot([$workDir]);
        $opt->setTempDir($workDir);
        $opt->setFontCache($workDir);
        $opt->setDefaultFont('DejaVu Sans');
        $opt->setDefaultPaperSize('letter');
        $opt->setDefaultPaperOrientation('landscape');
        return $opt;
    }

    /** @return list<string> */
    public function headerLines(array $data, string $usuario, string $fecha): array
    {
        $c = $data['conciliacion'];
        return [
            'Tesorería Municipal · ConciliaRH — Sistema Integral de Conciliación de Nómina y Pagos',
            $data['titulo'],
            sprintf('Periodo: %s · Lote %03d%s · Ejecución v%d (#%d) · Estado: %s', $c['periodo'], $c['lote'], $c['lote_oficial'] ? ' (oficial)' : ' (LOTE NO DECLARADO OFICIAL)',
                $c['numero'], $c['id'], status_label((string) $c['estado'])),
            sprintf('Generado el %s por %s · Motor v%d · Tolerancia $%s%s', $fecha, $usuario, $c['motor_version'], fmt_decimal($c['tolerancia']),
                $data['sensible'] ? ' · INCLUYE RFC/CURP (uso restringido)' : ''),
            'Huella del reporte (SHA-256 del contenido): ' . $data['huella'],
        ];
    }

    public static function filename(array $data, string $ext): string
    {
        // Sólo caracteres controlados por el sistema: sin entrada del usuario (sin inyección de encabezados ni rutas)
        return sprintf('conciliarh-%s-c%d-v%d-%s.%s', preg_replace('/[^a-z_]/', '', $data['tipo']), $data['conciliacion']['id'],
            $data['conciliacion']['numero'], date('Ymd-His'), $ext);
    }

    /** @return list<Cell> */
    private function cells(array $f, array $cols, ?Style $text, Style $money): array
    {
        $out = [];
        foreach ($cols as $i => [, $type]) {
            $v = $f[$i] ?? null;
            if (in_array($type, ['money', 'int'], true) && self::isNumber($v)) {
                $out[] = new Cell\NumericCell($type === 'int' ? (int) $v : (float) $v, $type === 'money' ? $money : $text);
            } elseif ($v === null || $v === '') {
                $out[] = new Cell\EmptyCell(null, $text);
            } else {
                $out[] = new Cell\StringCell(Exporter::text((string) $v), $text);
            }
        }
        return $out;
    }

    private static function isNumber(mixed $v): bool
    {
        return is_int($v) || (is_string($v) && preg_match('/^-?\d{1,15}(\.\d{1,2})?$/', $v) === 1);
    }

    /** @param array<string,bool> $used */
    private function sheetName(string $t, array &$used): string
    {
        $n = mb_substr(trim((string) preg_replace('/[\[\]\*\?\/\\\\:]/u', ' ', $t)), 0, 28) ?: 'Hoja';
        $base = $n;
        for ($i = 2; isset($used[mb_strtolower($n)]); $i++) {
            $n = mb_substr($base, 0, 26) . ' ' . $i;
        }
        $used[mb_strtolower($n)] = true;
        return $n;
    }

    private function logo(): string
    {
        $f = $this->basePath . '/public/assets/img/brand/isotipo.svg';
        return is_file($f) ? 'data:image/svg+xml;base64,' . base64_encode((string) file_get_contents($f)) : '';
    }

    /** Ruta que el controlador conoce ANTES de renderizar (para borrar un parcial si algo falla). */
    public function reserve(string $ext): string
    {
        return $this->reserved = $this->tmp($ext);
    }

    /** Borra archivos de reportes con más de una hora (p. ej. tras una caída del proceso). */
    public function sweep(): void
    {
        foreach (glob($this->workDir . '/reporte-*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 3600) {
                @unlink($f);
            }
        }
    }

    private ?string $reserved = null;

    private function tmp(string $ext): string
    {
        if ($this->reserved !== null && str_ends_with($this->reserved, '.' . $ext)) {
            [$p, $this->reserved] = [$this->reserved, null];
            return $p;
        }
        if (!is_dir($this->workDir) && !@mkdir($this->workDir, 0770, true) && !is_dir($this->workDir)) {
            throw new RuntimeException('No existe el directorio de trabajo de reportes.');
        }
        return $this->workDir . '/reporte-' . bin2hex(random_bytes(12)) . '.' . $ext;
    }
}
