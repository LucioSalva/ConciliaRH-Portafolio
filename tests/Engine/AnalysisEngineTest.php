<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Engine;

use ConciliaRH\Engine\Analysis\AnalysisResult;
use ConciliaRH\Engine\Analysis\Analyzer;
use ConciliaRH\Engine\Analysis\Control;
use ConciliaRH\Engine\Analysis\Values;
use ConciliaRH\Engine\Support\Dec;
use ConciliaRH\Engine\Workbook\AnalysisError;
use ConciliaRH\Engine\Workbook\OoxmlReader;
use ConciliaRH\Tests\Fixtures\AnalysisFixtures;
use ConciliaRH\Tests\Fixtures\Book;
use ConciliaRH\Tests\Fixtures\ExcelFixtures;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class AnalysisEngineTest extends TestCase
{
    private ?string $tmp = null;

    protected function tearDown(): void
    {
        if ($this->tmp !== null && is_dir($this->tmp)) {
            foreach ((array) glob($this->tmp . '/*') as $f) {
                @unlink((string) $f);
            }
            @rmdir($this->tmp);
        }
        $this->tmp = null;
    }

    private function tmpDir(): string
    {
        if ($this->tmp === null) {
            $this->tmp = sys_get_temp_dir() . '/crh-analysis-' . bin2hex(random_bytes(6));
            mkdir($this->tmp, 0775, true);
        }
        return $this->tmp;
    }

    private static function fx(string $name = ''): string
    {
        return ExcelFixtures::dir() . '/analisis' . ($name !== '' ? '/' . $name : '');
    }

    /** @param array{manualSheet?:array{0:int,1:string},manualProfile?:string,maxCells?:int,tick?:callable,hashKey?:string} $o */
    private static function runFixture(string $name, array $o = []): AnalysisResult
    {
        $ext = substr($name, (int) strrpos($name, '.') + 1);
        return self::analyze(self::fx($name), $ext, $ext === 'xlsm', $o);
    }

    /** @param array{manualSheet?:array{0:int,1:string},manualProfile?:string,maxCells?:int,tick?:callable,hashKey?:string} $o */
    private static function analyze(string $path, string $ext, bool $macros = false, array $o = []): AnalysisResult
    {
        $a = new Analyzer($o['maxCells'] ?? 1_000_000, 209_715_200, '0.01', 10, null, $o['tick'] ?? null, $o['hashKey'] ?? null);
        return $a->analyze($path, $ext, $macros, $o['manualSheet'] ?? null, $o['manualProfile'] ?? null);
    }

    private static function ctl(AnalysisResult $r, string $codigo, ?int $hoja = null): Control
    {
        foreach ($r->controls as $c) {
            if ($c->codigo === $codigo && ($hoja === null || $c->hoja === $hoja)) {
                return $c;
            }
        }
        self::fail("Control $codigo no encontrado");
    }

    /** @return list<string> */
    private static function codes(AnalysisResult $r): array
    {
        return array_values(array_unique(array_map(static fn ($w) => $w->codigo, $r->advertencias)));
    }

    private static function assertDec(string $expected, ?string $actual, string $message = ''): void
    {
        self::assertNotNull($actual, $message);
        self::assertTrue(Dec::eq($expected, $actual), $message !== '' ? $message : "Se esperaba $expected y se obtuvo $actual");
    }

    /** @param list<list<mixed>> $rows */
    private function wbWith(array $rows, string $name = 'x.xlsx'): string
    {
        $b = new Book('Sheet');
        foreach ($rows as $r) {
            $b->append(0, $r);
        }
        $p = $this->tmpDir() . '/' . $name;
        file_put_contents($p, $b->xlsx());
        return $p;
    }

    private function rawXlsx(string $sheetXml, string $name = 'raw.xlsx'): string
    {
        $p = $this->tmpDir() . '/' . $name;
        $z = new ZipArchive();
        $z->open($p, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="H" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $z->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $sheetXml . '</worksheet>');
        $z->close();
        return $p;
    }

    /** @return iterable<string,array{0:mixed,1:string}> */
    public static function realMoneyFormats(): iterable
    {
        $cases = [
            ['13,585,616.79', '13585616.79'], ['$13,585,616.79', '13585616.79'], ['13 585 616.79', '13585616.79'],
            ['13585616.79', '13585616.79'], ['(1,234.50)', '-1234.50'], ['-1234.5', '-1234.50'], ['1234.50-', '-1234.50'],
            ['  $ 2,000.00  ', '2000.00'], ['MXN 10', '10.00'], ['2000', '2000.00'], ['$ 1,000', '1000.00'], ['1.234,56', '1234.56'], ['1234,5', '1234.50'],
            [13706854.899999965, '13706854.90'], [14459978.190000001, '14459978.19'], [100, '100.00'],
        ];
        foreach ($cases as $i => [$raw, $expected]) {
            yield "$i:" . var_export($raw, true) => [$raw, $expected];
        }
    }

    #[DataProvider('realMoneyFormats')]
    public function testMoneyParserAcceptsRealFormats(mixed $raw, string $expected): void
    {
        $m = Values::parseMoney($raw);
        self::assertSame('OK', $m->status);
        self::assertSame($expected, $m->value);
    }

    /** @return iterable<string,array{0:string}> */
    public static function invalidMoney(): iterable
    {
        foreach (['abc', '12,34,5', '1,2345', '$', '1.2.3', '--5', '12a'] as $raw) {
            yield $raw => [$raw];
        }
    }

    #[DataProvider('invalidMoney')]
    public function testMoneyParserInvalidIsNeverZero(string $raw): void
    {
        $m = Values::parseMoney($raw);
        self::assertSame('INVALIDO', $m->status);
        self::assertNull($m->value);
    }

    public function testMoneyEmptyAndFloatNoise(): void
    {
        self::assertSame('VACIO', Values::parseMoney(null)->status);
        self::assertSame('VACIO', Values::parseMoney('  ')->status);
        self::assertSame('INVALIDO', Values::parseMoney(true)->status);
        self::assertDec('0.30', Values::parseMoney(0.1 + 0.2)->value);
    }

    public function testHeaderAndIdentifierNormalization(): void
    {
        self::assertSame('RFC', Values::normHeader(' R.F.C. '));
        self::assertSame('NUM', Values::normHeader('Núm.'));
        self::assertSame('ID CONCEPTO', Values::normHeader('ID_CONCEPTO'));
        self::assertSame('FTE FINANCIAM', Values::normHeader("Fte.\u{200B} Financiam.\n"));
        self::assertSame('00123', Values::parseIdentifier('00123')[0], 'Los ceros iniciales de un texto se conservan');
        self::assertSame('123', Values::parseIdentifier(123.0)[0]);
        self::assertTrue(Values::parseIdentifier((float) 2 ** 60)[1]);
    }

    public function testSingleSheetProfileTotalFormulaCached(): void
    {
        $r = self::runFixture('a01_una_hoja.xlsx');
        self::assertSame(['ANALIZADO', 'TESORERIA_BBVA', 'ALTA', 0], [$r->resultado, $r->perfil, $r->confianza, $r->hoja]);
        self::assertSame(6, $r->filasDetalle);
        self::assertSame('CUADRA', self::ctl($r, 'TOTAL_IMPORTE')->estado);
        self::assertSame('CUADRA', self::ctl($r, 'FORMULAS_SUMA_VERIFICADAS')->estado);
        self::assertSame(2, $r->sheets[0]->headerRow);
    }

    public function testAllSheetsInspectedAndBestSelected(): void
    {
        $r = self::runFixture('a02_varias_hojas.xlsx');
        self::assertCount(3, $r->sheets);
        self::assertSame(1, $r->hoja);
        $estados = [];
        foreach ($r->sheets as $s) {
            $estados[$s->name] = $s->estado;
        }
        self::assertSame('SELECCIONADA', $estados['Datos']);
        self::assertContains($estados['Notas'], ['DESCARTADA', 'VACIA']);
        foreach ($r->sheets as $s) {
            self::assertNotSame('', $s->motivo, 'Cada hoja explica su estado');
        }
    }

    public function testHiddenSheetCanBeTheSource(): void
    {
        $r = self::runFixture('a03_hoja_oculta.xlsx');
        self::assertSame(1, $r->hoja);
        self::assertSame('OCULTA', $r->sheets[1]->visibility);
        self::assertSame('DESCARTADA', $r->sheets[0]->estado, 'La plantilla visible sin registros no se elige');
    }

    public function testVeryHiddenEquivalentPrefersVisible(): void
    {
        $r = self::runFixture('a04_very_hidden.xlsx');
        self::assertSame('MUY_OCULTA', $r->sheets[1]->visibility);
        self::assertSame(0, $r->hoja, 'La visible gana: la muy oculta penaliza y, además, su contenido es equivalente');
        self::assertSame('CANDIDATA', $r->sheets[1]->estado);
        self::assertNotSame('', $r->sheets[1]->motivo);
    }

    public function testHiddenRowsAreDetectedAndWarned(): void
    {
        $r = self::runFixture('a05_filas_ocultas.xlsx');
        $s = $r->sheets[$r->hoja];
        self::assertSame(5, $s->filasOcultas);
        self::assertContains('VISTA_DERIVADA', self::codes($r));
        self::assertSame('ANALIZADO_CON_ADVERTENCIAS', $r->resultado);
        self::assertDec('5', self::ctl($r, 'FILAS_DETALLE_OCULTAS')->calculado);
        self::assertSame('TODAS_LAS_FILAS', self::ctl($r, 'TOTAL_NETO')->detalle['base'], 'SUM incluye las filas ocultas');
    }

    public function testAutofilterSubtotalMatchesVisibleRows(): void
    {
        $r = self::runFixture('a06_filtro.xlsx');
        $s = $r->sheets[$r->hoja];
        self::assertTrue($s->tieneFiltros);
        self::assertContains('VISTA_DERIVADA', self::codes($r));
        $neto = self::ctl($r, 'TOTAL_NETO');
        self::assertSame('CUADRA', $neto->estado);
        self::assertSame('FILAS_VISIBLES', $neto->detalle['base']);
        self::assertSame(20, $r->filasDetalle, 'El detalle incluye TODAS las filas; el filtro no elimina registros');
    }

    public function testFilteredViewHasSameFingerprintAsHiddenRowsVersion(): void
    {
        self::assertSame(self::runFixture('a05_filas_ocultas.xlsx')->huella, self::runFixture('a06_filtro.xlsx')->huella);
    }

    public function testHeaderInRow5(): void
    {
        $r = self::runFixture('a07_encabezado_fila5.xlsx');
        self::assertSame(5, $r->sheets[0]->headerRow);
        self::assertSame('TESORERIA_BBVA', $r->perfil);
        self::assertSame(self::runFixture('a01_una_hoja.xlsx')->huella, $r->huella, 'Títulos sobre el encabezado no entran a la huella');
    }

    public function testTwoLevelMergedHeader(): void
    {
        $r = self::runFixture('a08_encabezado_combinado.xlsx');
        $s = $r->sheets[0];
        self::assertSame([3, 2], [$s->headerRow, $s->headerRows]);
        self::assertSame('TESORERIA_PENSION_BBVA', $r->perfil);
        $originals = array_values(array_filter(array_map(static fn ($c) => $c->headerOriginal, $s->columns), static fn ($o) => $o !== null && $o !== ''));
        self::assertNotEmpty(array_filter($originals, static fn ($o) => str_starts_with((string) $o, 'DATOS DEL TRABAJADOR / ')));
        self::assertSame('SIN_TOTAL', self::ctl($r, 'TOTAL_IMPORTE')->estado);
    }

    public function testTotalRowWithoutTheWordTotal(): void
    {
        $r = self::runFixture('a09_total.xlsx');
        self::assertSame(1, $r->sheets[0]->filasTotal);
        self::assertSame(6, $r->filasDetalle);
        $c = self::ctl($r, 'TOTAL_IMPORTE');
        self::assertSame('CUADRA', $c->estado);
        self::assertNotNull($c->declarado);
        self::assertDec((string) $c->declarado, $c->calculado);
        self::assertDec('0.00', $c->diferencia);
    }

    public function testTwoDifferentTotals(): void
    {
        $c = self::ctl(self::runFixture('a10_dos_totales.xlsx'), 'TOTAL_IMPORTE');
        self::assertSame('MULTIPLES_TOTALES', $c->estado);
        self::assertCount(2, $c->detalle['valores']);
    }

    public function testWrongTotalDoesNotMatch(): void
    {
        $r = self::runFixture('a11_total_incorrecto.xlsx');
        $c = self::ctl($r, 'TOTAL_IMPORTE');
        self::assertSame('NO_CUADRA', $c->estado);
        self::assertDec('0.50', $c->diferencia);
        self::assertSame('ANALIZADO_CON_ADVERTENCIAS', $r->resultado);
    }

    public function testFormulaWithoutCachedValueIsNeverRecalculated(): void
    {
        $r = self::runFixture('a13_formula_sin_cache.xlsx');
        $c = self::ctl($r, 'TOTAL_IMPORTE');
        self::assertSame('TOTAL_INVALIDO', $c->estado);
        self::assertNull($c->declarado);
        self::assertContains('FORMULA_SIN_VALOR_CACHE', self::codes($r));
        self::assertSame(1, $r->sheets[0]->formulasSinCache);
    }

    public function testTotalRowsExcludedFromDetailButUsedAsControl(): void
    {
        $r = self::runFixture('a09_total.xlsx');
        self::assertCount(6, $r->staging);
        self::assertDec('1', self::ctl($r, 'CANTIDAD_FILAS_TOTAL')->calculado);
    }

    public function testWeirdMoneyValues(): void
    {
        $r = self::runFixture('a24_montos_raros.xlsx');
        $inv = self::ctl($r, 'IMPORTES_INVALIDOS');
        self::assertSame('NO_CUADRA', $inv->estado);
        self::assertDec('1', $inv->calculado, "Sólo 'abc' es inválido");
        self::assertDec('1', self::ctl($r, 'IMPORTES_NEGATIVOS')->calculado);
        $importes = array_values(array_filter(array_map(static fn ($s) => $s->importe, $r->staging), static fn ($v) => $v !== null));
        self::assertNotEmpty(array_filter($importes, static fn ($v) => Dec::eq($v, '13585616.79')));
        self::assertNotEmpty(array_filter($importes, static fn ($v) => Dec::eq($v, '-100.00')));
        self::assertNotEmpty(array_filter($r->staging, static fn ($s) => $s->importeEstado === 'INVALIDO' && $s->importe === null));
    }

    public function testSameContentDifferentOrderTotalFormatAndExtensionShareFingerprint(): void
    {
        $names = ['a09_total.xlsx', 'a14_orden_distinto.xlsx', 'a16_formato_distinto.xlsx', 'a22_santander.xlsm'];
        $h = [];
        foreach ($names as $n) {
            $h[$n] = self::runFixture($n)->huella;
        }
        self::assertCount(1, array_unique($h));
        self::assertNotContains(null, $h);
        $sha = array_unique(array_map(static fn ($n) => hash_file('sha256', self::fx($n)), $names));
        self::assertCount(4, $sha, 'Son binarios distintos');
    }

    public function testAuxiliaryColumnDoesNotChangeFingerprint(): void
    {
        $r = self::runFixture('a17_columnas_auxiliares.xlsx');
        self::assertContains('COLUMNA_SIN_ENCABEZADO_CON_DATOS', self::codes($r));
        self::assertSame(self::runFixture('a01_una_hoja.xlsx')->huella, $r->huella);
    }

    public function testRealDifferenceChangesFingerprint(): void
    {
        $src = $this->tmpDir() . '/x.xlsx';
        $wb = IOFactory::load(self::fx('a09_total.xlsx'));
        $ws = $wb->getActiveSheet();
        $ws->setCellValue('H8', (float) $ws->getCell('H8')->getValue() + 0.01);
        $w = new Xlsx($wb);
        $w->setPreCalculateFormulas(false);
        $w->save($src);
        self::assertNotSame(self::runFixture('a09_total.xlsx')->huella, self::analyze($src, 'xlsx')->huella);
    }

    public function testDeterminismSameInputSameOutput(): void
    {
        $a = self::runFixture('a25_tabulado_policia.xlsx');
        $b = self::runFixture('a25_tabulado_policia.xlsx');
        self::assertSame([$a->perfil, $a->hoja, $a->huella, $a->resultado], [$b->perfil, $b->hoja, $b->huella, $b->resultado]);
        $ctl = static fn (AnalysisResult $r) => array_map(static fn ($c) => [$c->codigo, $c->estado, $c->declarado, $c->calculado], $r->controls);
        self::assertSame($ctl($a), $ctl($b));
        self::assertSame(array_map(static fn ($s) => $s->hash, $a->staging), array_map(static fn ($s) => $s->hash, $b->staging));
    }

    public function testTabuladoVariantsByContentAndEmbeddedGlobal(): void
    {
        $pol = self::runFixture('a25_tabulado_policia.xlsx');
        $bom = self::runFixture('a26_tabulado_bomberos.xlsx');
        self::assertSame(['RRHH_TABULADO_POLICIA', 'RRHH_TABULADO_BOMBEROS'], [$pol->perfil, $bom->perfil]);
        self::assertSame('CONTENIDO', $pol->evidencia['variante']['fuente']);
        foreach ([$pol, $bom] as $r) {
            self::assertSame('CUADRA', self::ctl($r, 'NETO_VS_POLIZA_GLOBAL')->estado);
            self::assertSame('CUADRA', self::ctl($r, 'EMPLEADOS_VS_POLIZA_GLOBAL')->estado);
            self::assertSame('CUADRA', self::ctl($r, 'CONSISTENCIA_NETO_FILAS')->estado);
            self::assertSame(['SELECCIONADA', 'AUXILIAR'], array_map(static fn ($s) => $s->estado, $r->sheets), 'La póliza GLOBAL embebida es complementaria');
        }
    }

    public function testGlobalStandaloneVariantByTitleHasMediumConfidence(): void
    {
        $r = self::runFixture('a29_global_bomberos.xlsx');
        self::assertSame(['RRHH_POLIZA_GLOBAL_BOMBEROS', 'MEDIA'], [$r->perfil, $r->confianza]);
        $estados = [];
        foreach ($r->controls as $c) {
            $estados[$c->codigo] = $c->estado;
        }
        self::assertSame('CUADRA', $estados['NETO']);
        self::assertContains('VARIANTE_POR_TITULO', self::codes($r));
    }

    public function testPaymentVsPensionByConceptContent(): void
    {
        self::assertSame('TESORERIA_INTERBANCARIA', self::runFixture('a21_interbancaria.xls')->perfil);
        self::assertSame('TESORERIA_PENSION_INTERBANCARIA', self::runFixture('a21b_pension_interbancaria.xls')->perfil);
    }

    public function testXlsThreeSheetsTemplateAndCatalogDiscarded(): void
    {
        $r = self::runFixture('a21_interbancaria.xls');
        self::assertSame(0, $r->hoja);
        self::assertSame(['DESCARTADA', 'DESCARTADA'], array_slice(array_map(static fn ($s) => $s->estado, $r->sheets), 1));
        self::assertNull($r->sheets[0]->formulas);
        self::assertContains('FORMULAS_NO_DISPONIBLES_XLS', self::codes($r));
        self::assertSame('CUADRA', self::ctl($r, 'TOTAL_IMPORTE')->estado);
    }

    public function testXlsmIsReadAsData(): void
    {
        $r = self::runFixture('a22_santander.xlsm');
        self::assertSame('TESORERIA_SANTANDER', $r->perfil);
        self::assertContains('MACROS_PRESENTES', self::codes($r));
    }

    public function testMultiSheetEgresoPolicy(): void
    {
        $r = self::runFixture('a28_poliza_egreso.xlsx');
        self::assertSame('POLIZA_EGRESO_BANCARIA', $r->perfil);
        self::assertSame(['SELECCIONADA', 'INCLUIDA'], array_map(static fn ($s) => $s->estado, $r->sheets));
        $cuadre = array_values(array_map(static fn ($c) => $c->estado, array_filter($r->controls, static fn ($c) => $c->codigo === 'CUADRE_DEBE_HABER')));
        self::assertSame(['CUADRA', 'CUADRA'], $cuadre);
    }

    public function testAmbiguousVariantGoesToManualReview(): void
    {
        $r = self::runFixture('a18_perfil_ambiguo.xlsx');
        self::assertSame(['REVISION_MANUAL', null, null], [$r->resultado, $r->perfil, $r->huella]);
        self::assertContains('VARIANTE_NO_DETERMINADA', self::codes($r));
    }

    public function testAmbiguousSheetsAreNeverChosenArbitrarily(): void
    {
        $r = self::runFixture('a18b_hojas_ambiguas.xlsx');
        self::assertSame('REVISION_MANUAL', $r->resultado);
        self::assertNull($r->hoja);
        self::assertContains('AMBIGUEDAD_HOJAS', self::codes($r));
        foreach ($r->sheets as $s) {
            self::assertSame('CANDIDATA', $s->estado);
        }
    }

    public function testUnknownProfile(): void
    {
        $r = self::runFixture('a19_perfil_desconocido.xlsx');
        self::assertSame(['REVISION_MANUAL', null, 'NINGUNA'], [$r->resultado, $r->perfil, $r->confianza]);
        self::assertContains('PERFIL_DESCONOCIDO', self::codes($r));
    }

    public function testAnalystWorkbookWithMixedProfiles(): void
    {
        $r = self::runFixture('a27_libro_trabajo.xlsx');
        self::assertSame('REVISION_MANUAL', $r->resultado);
        self::assertContains('LIBRO_CON_PERFILES_DISTINTOS', self::codes($r));
    }

    public function testManualSheetAndProfileResolveReview(): void
    {
        $r = self::runFixture('a18b_hojas_ambiguas.xlsx', ['manualSheet' => [1, 'Version B']]);
        self::assertSame(['ANALIZADO', 1, 'MANUAL', 'TESORERIA_BBVA'], [$r->resultado, $r->hoja, $r->hojaOrigen, $r->perfil]);
        $r2 = self::runFixture('a18_perfil_ambiguo.xlsx', ['manualProfile' => 'TESORERIA_PENSION_SANTANDER']);
        self::assertSame(['ANALIZADO', 'TESORERIA_PENSION_SANTANDER', 'MANUAL', 'MANUAL'], [$r2->resultado, $r2->perfil, $r2->confianza, $r2->perfilOrigen]);
        self::assertNotNull($r2->huella);
        $bad = self::runFixture('a18b_hojas_ambiguas.xlsx', ['manualSheet' => [1, 'Otro nombre']]);
        self::assertSame('REVISION_MANUAL', $bad->resultado);
        self::assertContains('HOJA_MANUAL_NO_ENCONTRADA', self::codes($bad));
    }

    public function testExternalLinksDdeAndNamesAreReportedNotResolved(): void
    {
        $r = self::runFixture('a23_links_externos.xlsx');
        $codes = self::codes($r);
        foreach (['VINCULOS_EXTERNOS', 'FORMULAS_EXTERNAS', 'FORMULAS_DDE', 'NOMBRES_DEFINIDOS_EXTERNOS', 'RELACIONES_EXTERNAS'] as $code) {
            self::assertContains($code, $codes);
        }
        self::assertSame('ANALIZADO_CON_ADVERTENCIAS', $r->resultado);
    }

    private static function analysisErrorCode(callable $fn): ?string
    {
        try {
            $fn();
        } catch (AnalysisError $e) {
            return $e->errorCode;
        }
        return null;
    }

    public function testCorruptAndLimits(): void
    {
        self::assertSame('ARCHIVO_CORRUPTO', self::analysisErrorCode(static fn () => self::analyze(ExcelFixtures::dir() . '/corrupt.xlsx', 'xlsx')));
        self::assertSame('ESTRUCTURA_EXCESIVA', self::analysisErrorCode(static fn () => self::runFixture('a25_tabulado_policia.xlsx', ['maxCells' => 50])));
        self::assertSame('ARCHIVO_CORRUPTO', self::analysisErrorCode(static fn () => self::analyze(ExcelFixtures::dir() . '/corrupt.xls', 'xls')));
    }

    public function testOriginalIsNeverModified(): void
    {
        $p = self::fx('a22_santander.xlsm');
        clearstatcache();
        $before = [hash_file('sha256', $p), stat($p)['mtime'], stat($p)['size']];
        self::runFixture('a22_santander.xlsm');
        clearstatcache();
        self::assertSame($before, [hash_file('sha256', $p), stat($p)['mtime'], stat($p)['size']]);
    }

    public function testCooperativeTimeoutCheckIsCalled(): void
    {
        $calls = 0;
        self::runFixture('a25_tabulado_policia.xlsx', ['tick' => static function () use (&$calls): void {
            $calls++;
        }]);
        self::assertGreaterThan(0, $calls);
    }

    public function testStagingMasksAccountsAndKeepsTraceability(): void
    {
        $r = self::runFixture('a01_una_hoja.xlsx');
        $st = $r->staging[0];
        self::assertSame(3, $st->fila);
        self::assertStringStartsWith('****', (string) $st->datos['CUENTA']);
        self::assertSame(8, strlen((string) $st->datos['CUENTA']));
        self::assertNotNull($st->cuenta);
        self::assertTrue(ctype_digit((string) $st->cuenta), 'Los dígitos sólo viven en memoria (se persiste HMAC + últimos 4)');
        self::assertSame(64, strlen($st->hash));
    }

    /** @return iterable<string,array{0:mixed,1:string}> */
    public static function ambiguousMoney(): iterable
    {
        yield '(-100)' => ['(-100)', 'INVALIDO'];
        yield '1.234' => ['1.234', 'INVALIDO'];
        yield '1e13' => [1e13, 'INVALIDO'];
        yield '2,000,000,000,000' => ['2,000,000,000,000', 'INVALIDO'];
    }

    #[DataProvider('ambiguousMoney')]
    public function testMoneyAmbiguousAndOutOfRange(mixed $raw, string $status): void
    {
        self::assertSame($status, Values::parseMoney($raw)->status);
    }

    public function testMoneyNegativeZeroIsCanonical(): void
    {
        self::assertSame('0.00', Values::parseMoney(-0.0)->value);
        self::assertSame('0.00', Values::parseMoney('-0.004')->value);
        self::assertFalse(Values::parseMoney('-0.004')->negative);
    }

    public function testRepeatedUnknownHeadersDoNotCollideInFingerprint(): void
    {
        $head = ['Núm.', 'Nombre', 'R.F.C.', 'IMPORTE', 'CTA BBVA', 'Obs.', 'OBS'];
        $a = $this->wbWith([['G'], $head, ['1', 'P UNO', 'XAXX010101000', 10, '0100000001', 'A', 'Z']], 'a.xlsx');
        $b = $this->wbWith([['G'], $head, ['1', 'P UNO', 'XAXX010101000', 10, '0100000001', 'B', 'Z']], 'b.xlsx');
        $ra = self::analyze($a, 'xlsx');
        $rb = self::analyze($b, 'xlsx');
        self::assertNotSame($ra->huella, $rb->huella, 'La primera de dos columnas homónimas también es contenido');
        self::assertContains('COLUMNA_DUPLICADA', self::codes($ra));
    }

    public function testPolicyLineWithInvalidAmountIsKeptAndCounted(): void
    {
        $rows = [['PE'], [null], [null, 'CUENTA', 'SCTA', 'SSCTA', 'SSSCTA', 'SSSSCTA', 'REF', 'CONCEPTO', 'DEBE', 'HABER'],
                 [null, '5111', '1', '1', null, null, null, 'SUELDOS', 100.0, null],
                 [null, '5112', '1', '1', null, null, null, 'OTRO', 'abc', null],
                 [null, '1112', '31', '1', null, null, null, 'BANCO', null, 100.0]];
        $r = self::analyze($this->wbWith($rows), 'xlsx');
        self::assertSame('POLIZA_EGRESO_BANCARIA', $r->perfil);
        self::assertSame(3, $r->filasDetalle);
        self::assertDec('1', self::ctl($r, 'IMPORTES_INVALIDOS')->calculado, 'Un importe inválido nunca desaparece en silencio');
    }

    public function testSelectionConsidersAllSheetsWithinMargin(): void
    {
        $b = new Book('Original');
        AnalysisFixtures::bbva($b, 0, AnalysisFixtures::records(6), total: null);
        $f = $b->add('Filtrada');
        AnalysisFixtures::bbva($b, $f, AnalysisFixtures::records(6), total: null);
        foreach ([4, 5] as $rr) {
            $b->hideRow($f, $rr);
        }
        $o = $b->add('Otra');
        AnalysisFixtures::bbva($b, $o, AnalysisFixtures::records(6, 55), total: null);
        $p = $this->tmpDir() . '/tres.xlsx';
        file_put_contents($p, $b->xlsx());
        $r = self::analyze($p, 'xlsx');
        self::assertSame('REVISION_MANUAL', $r->resultado, 'La tercera hoja cercana con otro contenido obliga a revisión');
        self::assertContains('AMBIGUEDAD_HOJAS', self::codes($r));
    }

    public function testSubtotalCountIsNotATotal(): void
    {
        $rows = [['G'], ['Núm.', 'Nombre', 'R.F.C.', 'IMPORTE', 'CTA BBVA'],
                 ['1', 'P UNO', 'XAXX010101000', 10, '0100000001'], ['2', 'P DOS', 'XAXX010101000', 20, '0100000002'],
                 [null, null, null, '=SUBTOTAL(3,D3:D4)']];
        $r = self::analyze($this->wbWith($rows), 'xlsx');
        self::assertSame(0, $r->sheets[0]->filasTotal);
        self::assertSame('SIN_TOTAL', self::ctl($r, 'TOTAL_IMPORTE')->estado);
    }

    public function testPersistedHashesAreKeyed(): void
    {
        $r = self::runFixture('a01_una_hoja.xlsx', ['hashKey' => 'llave-de-prueba']);
        $st = $r->staging[0];
        $plain = hash('sha256', 'r:{}');
        self::assertNotSame($plain, $st->hash);
        self::assertNotSame(self::runFixture('a01_una_hoja.xlsx')->staging[0]->hash, $st->hash);
        self::assertSame(self::runFixture('a01_una_hoja.xlsx')->huella, $r->huella, 'La huella del documento no depende de la llave');
    }

    public function testDeterminismAcrossProcesses(): void
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $code = 'require ' . var_export($autoload, true) . ';'
            . '$r = (new ConciliaRH\Engine\Analysis\Analyzer())->analyze(' . var_export(self::fx('a25_tabulado_policia.xlsx'), true) . ', "xlsx");'
            . 'echo json_encode([$r->huella, $r->perfil, $r->hoja, array_map(fn ($c) => $c->estado, $r->controls), array_slice(array_map(fn ($s) => $s->hash, $r->staging), 0, 3)]);';
        $outs = [];
        foreach (['1', '2', '12345'] as $seed) {
            $out = [];
            $rc = 0;
            exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('mt_srand(' . $seed . ');' . $code) . ' 2>&1', $out, $rc);
            self::assertSame(0, $rc, implode("\n", $out));
            $outs[] = implode("\n", $out);
        }
        self::assertCount(1, array_unique($outs));
        self::assertNotSame('', $outs[0]);
    }

    public function testCellsWithoutReferenceAreLinearNotQuadratic(): void
    {
        $cells = str_repeat('<c><v>1</v></c>', 60_000);
        $p = $this->rawXlsx("<sheetData><row>$cells</row></sheetData>");
        $t0 = hrtime(true);
        self::analyze($p, 'xlsx');
        self::assertLessThan(10.0, (hrtime(true) - $t0) / 1e9, '60 000 celdas sin atributo r no deben tomar tiempo cuadrático');
    }

    public function testTickIsCalledEvenWithoutRowNumbers(): void
    {
        $cells = str_repeat('<c/>', 50_000);
        $p = $this->rawXlsx("<sheetData><row>$cells</row></sheetData>");
        $ticks = 0;
        self::analyze($p, 'xlsx', false, ['tick' => static function () use (&$ticks): void {
            $ticks++;
        }]);
        self::assertGreaterThanOrEqual(2, $ticks, 'El timeout se revisa por número de eventos XML, no por filas');
    }

    public function testEmptyCellsCountAgainstLimits(): void
    {
        $cells = str_repeat('<c/>', 50_000);
        $p = $this->rawXlsx("<sheetData><row>$cells</row></sheetData>");
        self::assertSame('ESTRUCTURA_EXCESIVA', self::analysisErrorCode(static fn () => self::analyze($p, 'xlsx', false, ['maxCells' => 10_000])));
    }

    public function testMergeFloodIsCappedAndMemoryBounded(): void
    {
        $merges = '';
        for ($i = 1; $i <= 150_000; $i++) {
            $merges .= '<mergeCell ref="A' . $i . ':B' . $i . '"/>';
        }
        $p = $this->rawXlsx('<sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>x</t></is></c></row></sheetData><mergeCells>' . $merges . '</mergeCells>');
        $wb = (new OoxmlReader($p, 1000, 10 ** 9, static function (): void {
        }))->read();
        self::assertCount(100_000, $wb->sheets[0]->merges);
    }

    public function testOverlongFormulaIsRejected(): void
    {
        $f = str_repeat('SUM(A1:A2)+', 1000);
        $p = $this->rawXlsx('<sheetData><row r="1"><c r="A1"><f>' . $f . '1</f><v>1</v></c></row></sheetData>');
        self::assertSame('ESTRUCTURA_EXCESIVA', self::analysisErrorCode(static fn () => self::analyze($p, 'xlsx')));
    }

    public function testInlineStringWithoutReferenceIsRead(): void
    {
        $p = $this->rawXlsx('<sheetData><row><c t="inlineStr"><is><t>HOLA</t></is></c><c t="inlineStr"><is><t>MUNDO</t></is></c></row></sheetData>');
        $wb = (new OoxmlReader($p, 1000, 10 ** 9, static function (): void {
        }))->read();
        self::assertSame(['HOLA', 'MUNDO'], array_values(array_map(static fn ($c) => $c->value, $wb->sheets[0]->rows[0]->cells)));
    }

    public function testPersonalLookingHeaderIsMasked(): void
    {
        $rows = [['G'], ['Núm.', 'Nombre', 'R.F.C.', 'IMPORTE', 'CTA BBVA', 'XAXX010101000'],
                 ['1', 'P UNO', 'XAXX010101000', 10, '0100000001', 'x'], ['2', 'P DOS', 'XAXX010101000', 20, '0100000002', 'y']];
        $r = self::analyze($this->wbWith($rows), 'xlsx');
        $originals = array_map(static fn ($c) => $c->headerOriginal, $r->sheets[0]->columns);
        self::assertNotContains('XAXX010101000', $originals);
        self::assertContains('[dato enmascarado]', $originals);
    }

    public function testUnmappedAccountLikeValuesAreMaskedInStaging(): void
    {
        $rows = [['G'], ['Núm.', 'Nombre', 'R.F.C.', 'IMPORTE', 'CTA BBVA', 'OTRA CTA'],
                 ['1', 'P UNO', 'XAXX010101000', 10, '0100000001', 'CTA 0123-4567-8901']];
        $r = self::analyze($this->wbWith($rows), 'xlsx');
        self::assertSame('****8901', $r->staging[0]->datos['X:OTRA CTA']);
    }

    public function testSharedFormulasAreTranslatedPerCell(): void
    {
        $xml = '<sheetData>'
            . '<row r="1"><c r="A1" t="inlineStr"><is><t>A</t></is></c><c r="B1" t="inlineStr"><is><t>B</t></is></c><c r="C1" t="inlineStr"><is><t>C</t></is></c></row>'
            . '<row r="2"><c r="B2"><v>1</v></c><c r="C2"><v>10</v></c></row>'
            . '<row r="3"><c r="B3"><v>2</v></c><c r="C3"><v>20</v></c></row>'
            . '<row r="4"><c r="B4"><f t="shared" ref="B4:C4" si="0">SUM(B2:B3)</f><v>3</v></c><c r="C4"><f t="shared" si="0"/><v>30</v></c></row>'
            . '</sheetData>';
        $wb = (new OoxmlReader($this->rawXlsx($xml), 1000, 10 ** 9, static function (): void {
        }))->read();
        $row4 = array_map(static fn ($cell) => $cell->formula, $wb->sheets[0]->rows[3]->cells);
        self::assertSame([1 => 'SUM(B2:B3)', 2 => 'SUM(C2:C3)'], $row4);
    }
}
