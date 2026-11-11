<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Analysis;

use ConciliaRH\Engine\Support\Py;
use ConciliaRH\Engine\Workbook\AnalysisError;
use ConciliaRH\Engine\Workbook\Coordinates;
use ConciliaRH\Engine\Workbook\OoxmlReader;
use ConciliaRH\Engine\Workbook\Sheet;
use ConciliaRH\Engine\Workbook\Workbook;
use ConciliaRH\Engine\Workbook\XlsReader;

final class Analyzer
{
    private const STRONG = 1.0;
    private const PARTIAL = 0.75;

    /** @var callable(float,string):void */
    private $progress;
    /** @var callable():void */
    private $tick;

    /**
     * @param callable(float,string):void|null $progress
     * @param callable():void|null $tick
     */
    public function __construct(
        private readonly int $maxCells = 1_000_000,
        private readonly int $maxXml = 209_715_200,
        private readonly string $tolerance = '0.01',
        private readonly int $margin = 10,
        ?callable $progress = null,
        ?callable $tick = null,
        private readonly ?string $hashKey = null,
    ) {
        $this->progress = $progress ?? static function (float $p, string $m): void {
        };
        $this->tick = $tick ?? static function (): void {
        };
    }

    public function readWorkbook(string $path, string $extension, bool $hasMacros): Workbook
    {
        return match ($extension) {
            'xls' => (new XlsReader($path, $this->maxCells, $this->tick))->read($hasMacros),
            'xlsx', 'xlsm' => (new OoxmlReader($path, $this->maxCells, $this->maxXml, $this->tick))->read(),
            default => throw new AnalysisError('FORMATO_NO_COMPATIBLE', 'Formato no compatible con el análisis.'),
        };
    }

    /** @param array{0:int,1:string}|null $manualSheet */
    public function analyze(string $path, string $extension, bool $hasMacros = false, ?array $manualSheet = null,
                            ?string $manualProfile = null): AnalysisResult
    {
        $step = $this->progress;
        $t0 = hrtime(true);
        $timings = [];
        $mark = static function (string $name, int $since) use (&$timings): int {
            $now = hrtime(true);
            $timings[$name] = intdiv($now - $since, 1_000_000);
            return $now;
        };
        $step(5, 'Abriendo el libro en sólo lectura');
        $wb = $this->readWorkbook($path, $extension, $hasMacros);
        $t = $mark('lectura_ms', $t0);
        $warnings = self::workbookWarnings($wb);
        $forced = $manualProfile !== null && $manualProfile !== '' ? Profiles::byCode($manualProfile) : null;
        $manualRev = [];
        if ($manualProfile !== null && $manualProfile !== '' && $forced === null) {
            $manualRev[] = new Advertencia('PERFIL_MANUAL_DESCONOCIDO', 'REVISION', 'El perfil elegido manualmente no existe en las reglas vigentes.');
        }
        $step(15, 'Inspeccionando todas las hojas');
        $sheets = [];
        foreach ($wb->sheets as $s) {
            ($this->tick)();
            $sheets[] = ($forced !== null && $manualSheet !== null && $s->index !== $manualSheet[0])
                ? $this->inspectSheet($s, null) : $this->inspectSheet($s, $forced);
        }
        foreach ($wb->sheets as $i => $s) {
            $res = $sheets[$i];
            if ($res->global !== null) {
                foreach ($s->rows as $r) {
                    if ($r->index < $res->global->headerRow) {
                        foreach ($r->cells as $c) {
                            if (Values::isText($c->value)) {
                                $res->titles[] = Values::normKey($c->value);
                            }
                        }
                    }
                }
            }
            foreach (self::sheetWarnings($s, $res) as $w) {
                $res->advertencias[] = $w;
            }
        }
        $t = $mark('hojas_ms', $t);
        $step(30, 'Encabezados detectados');
        $evidencia = ['candidatas' => []];
        $resultadoRev = $manualRev;
        $selected = [];
        $hojaOrigen = 'AUTOMATICO';
        $candidates = array_values(array_filter($sheets, static fn (SheetResult $r) => $r->estado === 'CANDIDATA'));
        $ordered = $candidates;
        usort($ordered, static fn ($a, $b) => [-$a->puntaje, $a->index] <=> [-$b->puntaje, $b->index]);
        $evidencia['candidatas'] = array_map(static fn ($r) => ['hoja' => $r->index, 'familia' => $r->familia, 'puntaje' => $r->puntaje], $ordered);
        if ($manualSheet !== null) {
            [$idx, $name] = $manualSheet;
            $target = null;
            foreach ($sheets as $r) {
                if ($r->index === $idx && $r->name === $name) {
                    $target = $r;
                    break;
                }
            }
            $hojaOrigen = 'MANUAL';
            if ($target === null) {
                $resultadoRev[] = new Advertencia('HOJA_MANUAL_NO_ENCONTRADA', 'REVISION', 'La hoja elegida manualmente no existe en el libro.');
            } elseif ($target->familia === null) {
                $resultadoRev[] = new Advertencia('HOJA_MANUAL_SIN_PERFIL', 'REVISION',
                    'La hoja elegida no coincide con ningún perfil; elija también el perfil.', $idx);
                $selected = [$target];
            } else {
                $selected = [$target];
                $target->motivo = 'Seleccionada manualmente por un usuario autorizado.';
            }
        } else {
            [$selected, $rev] = $this->select($candidates);
            array_push($resultadoRev, ...$rev);
        }
        $t = $mark('seleccion_ms', $t);
        $step(45, 'Detectando el perfil documental');
        $perfil = null;
        $confianza = 'NINGUNA';
        $perfilOrigen = 'NINGUNO';
        $principal = $selected[0] ?? null;
        $families = Profiles::families();
        if ($principal !== null && $principal->familia !== null) {
            if ($forced !== null) {
                [$perfil, $confianza, $perfilOrigen] = [$forced, 'MANUAL', 'MANUAL'];
                if ($principal->reqCov < self::STRONG) {
                    $warnings[] = new Advertencia('COBERTURA_PARCIAL', 'ADVERTENCIA', 'El perfil elegido no reconoce todas las columnas requeridas.',
                        $principal->index);
                }
            } elseif ($principal->reqCov < self::STRONG) {
                $resultadoRev[] = new Advertencia('PERFIL_PARCIAL', 'REVISION',
                    'Los encabezados coinciden sólo parcialmente con un perfil; confirme el perfil.', $principal->index);
                $confianza = 'BAJA';
            } else {
                $decision = $this->decideVariant($principal->familia, $principal);
                $evidencia['variante'] = ['valor' => $decision['value'], 'fuente' => $decision['source'], 'conteos' => (object) $decision['evidence']];
                if ($decision['value'] === null && $decision['source'] !== 'UNICA') {
                    $codes = implode(', ', array_map(static fn ($p) => $p->codigo, $families[$principal->familia]));
                    $resultadoRev[] = new Advertencia('VARIANTE_NO_DETERMINADA', 'REVISION',
                        "El contenido no permite distinguir la variante del perfil ($codes).", $principal->index);
                    $confianza = 'BAJA';
                } else {
                    $perfil = Profiles::profileFor($principal->familia, $decision['value']);
                    [$confianza, $perfilOrigen] = [$decision['confidence'], 'AUTOMATICO'];
                    if ($decision['source'] === 'TITULO') {
                        $warnings[] = new Advertencia('VARIANTE_POR_TITULO', 'INFO', 'La variante se infirió del título del libro (señal secundaria).',
                            $principal->index);
                    }
                }
            }
        } elseif ($forced !== null && $principal !== null) {
            [$perfil, $confianza, $perfilOrigen] = [$forced, 'MANUAL', 'MANUAL'];
        } elseif ($resultadoRev === []) {
            $resultadoRev[] = new Advertencia('PERFIL_DESCONOCIDO', 'REVISION', 'Ninguna hoja coincide con un perfil conocido.');
        }
        foreach ($sheets as $r) {
            $r->metadata['familia'] = $r->familia;
            if ($perfil !== null && in_array($r, $selected, true)) {
                $r->perfil = $perfil->codigo;
            } elseif ($r->familia !== null && count($families[$r->familia]) === 1) {
                $r->perfil = Profiles::profileFor($r->familia, null)?->codigo;
            } else {
                $r->perfil = null;
            }
        }
        $t = $mark('perfil_ms', $t);
        foreach ($selected as $i => $r) {
            if ($i === 0) {
                $r->estado = 'SELECCIONADA';
                $r->seleccionada = true;
            } else {
                $r->estado = 'INCLUIDA';
                $r->motivo = 'Hoja del mismo documento (perfil multi-hoja).';
            }
        }
        $complementary = [];
        if ($perfil !== null) {
            foreach ($sheets as $r) {
                if (!in_array($r, $selected, true) && $r->familia !== null && in_array($r->familia, $perfil->complementary, true)) {
                    $r->estado = 'AUXILIAR';
                    $r->motivo = 'Hoja complementaria del mismo libro (p. ej. póliza GLOBAL embebida).';
                    $complementary[] = $r;
                }
            }
        }
        $step(60, 'Calculando controles');
        $controls = [];
        foreach ($selected as $r) {
            if ($r->global !== null) {
                array_push($controls, ...Controls::global($r->global, $this->tolerance, $r->index));
            } elseif ($r->mapping !== null) {
                $m = $r->mapping;
                if ($perfil !== null && $m->profile->codigo !== $perfil->codigo && $m->profile->familia === $perfil->familia) {
                    $m = $m->withProfile($perfil);
                }
                $r->mapping = $m;
                array_push($controls, ...Controls::tabular($m, $r->rows, $this->tolerance, $r->index, $wb->sheets[$r->index]));
            }
        }
        foreach ($complementary as $c) {
            if ($c->global !== null && $principal !== null && $principal->mapping !== null) {
                array_push($controls, ...Controls::global($c->global, $this->tolerance, $c->index));
                $acol = $principal->mapping->colOf($principal->mapping->profile->amount ?? '');
                $detail = array_values(array_filter($principal->rows, static fn ($x) => $x->kind === 'DETALLE'));
                $amounts = [];
                foreach ($detail as $x) {
                    if ($acol !== null && isset($x->cells[$acol])) {
                        $amounts[] = [$x->hidden, Values::parseMoney($x->cells[$acol]->value)];
                    }
                }
                $total = Controls::dsum(array_column($amounts, 1));
                $visible = Controls::dsum(array_column(array_filter($amounts, static fn ($a) => !$a[0]), 1));
                $visibleCount = count(array_filter($detail, static fn ($x) => !$x->hidden));
                array_push($controls, ...Controls::cross($total, count($detail), $c->global, $this->tolerance, $c->index, $visible, $visibleCount));
            }
        }
        foreach ($controls as $c) {
            if (in_array($c->estado, ['NO_CUADRA', 'MULTIPLES_TOTALES', 'TOTAL_INVALIDO'], true) && $c->codigo !== 'CANTIDAD_EMPLEADOS_DECLARADOS') {
                $warnings[] = new Advertencia('CONTROL_' . $c->estado, 'ADVERTENCIA', "Control {$c->codigo}: {$c->mensaje}", $c->hoja);
            }
            foreach ($c->detalle['totales_invalidos'] ?? [] as $inv) {
                if (($inv['motivo'] ?? null) === 'FORMULA_SIN_VALOR_CACHE') {
                    $warnings[] = new Advertencia('FORMULA_SIN_VALOR_CACHE', 'ADVERTENCIA',
                        "Control {$c->codigo}: la fila TOTAL es una fórmula sin valor cacheado; no se recalcula.", $c->hoja);
                    break;
                }
            }
        }
        $t = $mark('controles_ms', $t);
        $step(80, 'Generando la huella lógica de contenido');
        $staging = [];
        $fingerprint = null;
        if ($perfil !== null && $resultadoRev === [] && $selected !== []) {
            $blocks = [];
            foreach ($selected as $r) {
                $records = $this->records($perfil, $r, $staging);
                $blocks[] = [$perfil->multiSheet ? $r->name : null, $records];
            }
            $fingerprint = Fingerprint::huella($perfil->codigo, $blocks, $perfil->orderSignificant);
        }
        $t = $mark('huella_ms', $t);
        foreach ($selected as $r) {
            $hiddenDetail = false;
            foreach ($r->rows as $x) {
                $hiddenDetail = $hiddenDetail || ($x->kind === 'DETALLE' && $x->hidden);
            }
            if ($r->tieneFiltros || $hiddenDetail) {
                $warnings[] = new Advertencia('VISTA_DERIVADA', 'ADVERTENCIA',
                    'La hoja seleccionada tiene filtro o filas ocultas: es una vista derivada; el detalle incluye TODAS las filas y el control indica qué total declara.',
                    $r->index);
            }
        }
        $all = [...$warnings, ...$resultadoRev];
        foreach ($sheets as $r) {
            array_push($all, ...$r->advertencias);
        }
        $selectedWarnings = $warnings;
        foreach ($selected as $r) {
            array_push($selectedWarnings, ...$r->advertencias);
        }
        if ($resultadoRev !== []) {
            $resultado = 'REVISION_MANUAL';
        } elseif (array_filter($selectedWarnings, static fn ($w) => $w->severidad === 'ADVERTENCIA') !== []) {
            $resultado = 'ANALIZADO_CON_ADVERTENCIAS';
        } else {
            $resultado = 'ANALIZADO';
        }
        $headers = [];
        if ($principal !== null && $principal->header !== null) {
            foreach ($principal->header->columns as [, $n]) {
                $headers[$n] = true;
            }
            $headers = array_map('strval', array_keys($headers));
            sort($headers, SORT_STRING);
        }
        $cells = $rows = 0;
        foreach ($wb->sheets as $s) {
            $cells += $s->cellsNonempty;
            $rows += count($s->rows);
        }
        $metricas = [...$timings, 'total_ms' => intdiv(hrtime(true) - $t0, 1_000_000), 'hojas' => count($wb->sheets), 'celdas' => $cells,
                     'filas' => $rows, 'rss_max_kb' => intdiv(memory_get_peak_usage(true), 1024)];
        $step(95, 'Análisis calculado');
        $filas = 0;
        foreach ($selected as $r) {
            $filas += $r->filasDetalle;
        }
        return new AnalysisResult($resultado, $perfil?->codigo, $perfil?->tipoDocumento, $confianza, $perfilOrigen, $principal?->puntaje,
            $principal?->index, $principal !== null ? $hojaOrigen : 'NINGUNA', $sheets, $controls, $staging, $fingerprint, self::dedupe($all),
            $evidencia, $metricas, $headers, $filas, $fingerprint !== null ? Fingerprint::VERSION : null);
    }

    private function inspectSheet(Sheet $sheet, ?Profile $forced): SheetResult
    {
        $res = self::base($sheet);
        if ($sheet->rows === []) {
            $res->estado = 'VACIA';
            $res->motivo = 'La hoja no tiene celdas con datos.';
            return $res;
        }
        $header = HeaderDetector::detect($sheet);
        $res->header = $header;
        if ($header !== null) {
            [$res->headerRow, $res->headerRows] = [$header->rowIndex, $header->rows];
        }
        $g = ($forced === null || $forced->extractor === 'poliza_global') ? SheetScanner::matchGlobal($sheet) : null;
        $scores = [];
        $best = null;
        if ($header !== null && ($forced === null || $forced->extractor === 'tabular')) {
            $reps = [];
            if ($forced !== null) {
                $reps[$forced->familia] = $forced;
            } else {
                foreach (Profiles::familyReps() as $f => $p) {
                    if ($p->extractor === 'tabular') {
                        $reps[$f] = $p;
                    }
                }
            }
            ksort($reps, SORT_STRING);
            foreach ($reps as $fam => $prof) {
                $m = SheetScanner::matchTabular($header, $prof);
                $scores[] = [$m->score, (string) $fam];
                if ($m->reqCov >= self::PARTIAL && ($best === null || [$m->score, $m->reqCov] > [$best[0], $best[1]->reqCov])) {
                    $best = [$m->score, $m];
                }
            }
        }
        if ($g !== null && $g->lines !== []) {
            $scores[] = [$g->score, 'RRHH_POLIZA_GLOBAL'];
        }
        usort($scores, static fn ($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        $res->metadata['perfiles_puntaje'] = array_map(static fn ($s) => ['familia' => $s[1], 'puntaje' => $s[0]], array_slice($scores, 0, 3));
        $useGlobal = $g !== null && $g->lines !== [] && ($best === null || $g->score >= $best[0]);
        $breakdown = [];
        if ($useGlobal) {
            $res->global = $g;
            $res->familia = 'RRHH_POLIZA_GLOBAL';
            $res->headerRow = $g->headerRow;
            $res->headerRows = 1;
            $res->reqCov = 1.0;
            $res->filasDetalle = count($g->lines);
            $res->filasTotal = count($g->footer);
            $breakdown[] = ['perfil', (int) Py::round($g->score * 0.6, 0)];
            $breakdown[] = ['registros', 15];
            if (isset($g->footer['NETO'])) {
                $breakdown[] = ['neto_declarado', 10];
            }
        } elseif ($best !== null) {
            $mapping = $best[1];
            $rows = SheetScanner::classifyRows($sheet, $header, $mapping);
            [$res->mapping, $res->rows, $res->reqCov] = [$mapping, $rows, $mapping->reqCov];
            $res->familia = $mapping->profile->familia;
            $detail = array_values(array_filter($rows, static fn ($r) => $r->kind === 'DETALLE'));
            $totals = array_values(array_filter($rows, static fn ($r) => $r->kind === 'TOTAL'));
            [$res->filasDetalle, $res->filasTotal] = [count($detail), count($totals)];
            $res->metadata['filas_auxiliares'] = count(array_filter($rows, static fn ($r) => $r->kind === 'AUXILIAR'));
            $res->metadata['cobertura_requeridos'] = Py::round($mapping->reqCov, 2);
            $res->metadata['columnas_sin_reconocer'] = count($mapping->otherCols);
            $res->columns = SheetScanner::columnStats($header, $mapping, $rows);
            $breakdown[] = ['perfil', (int) Py::round($mapping->score * 0.6, 0)];
            if ($detail !== []) {
                $breakdown[] = ['registros', 15];
                $idCols = [];
                foreach ($mapping->cols as $c => $f) {
                    if (in_array($f, $mapping->profile->identifiers, true)) {
                        $idCols[] = $c;
                    }
                }
                if ($idCols !== []) {
                    $fill = 0.0;
                    foreach ($detail as $r) {
                        $fill += count(array_filter($idCols, static fn ($c) => isset($r->cells[$c]))) / count($idCols);
                    }
                    $fill /= count($detail);
                    $breakdown[] = ['identificadores', $fill >= 0.9 ? 10 : ($fill >= 0.5 ? 5 : 0)];
                }
                $acol = $mapping->profile->amount !== null ? $mapping->colOf($mapping->profile->amount) : null;
                if ($acol !== null) {
                    $okc = count(array_filter($detail, static fn ($r) => isset($r->cells[$acol]) && Values::parseMoney($r->cells[$acol]->value)->ok()));
                    $breakdown[] = ['importes_numericos', $okc / count($detail) >= 0.95 ? 10 : 0];
                }
            } else {
                $breakdown[] = ['sin_registros', $totals !== [] ? -30 : -15];
            }
            if (array_filter($detail, static fn ($r) => $r->hidden) !== []) {
                $breakdown[] = ['filas_ocultas', -15];
            }
            if ($header !== null && $header->rowIndex > 20) {
                $breakdown[] = ['encabezado_desplazado', -5];
            }
            if ($mapping->duplicateCols !== [] || count(array_unique($mapping->otherCols)) < count($mapping->otherCols)) {
                $res->advertencias[] = new Advertencia('COLUMNA_DUPLICADA', 'ADVERTENCIA', 'Hay columnas con el mismo encabezado; se usa la primera.',
                    $sheet->index);
            }
            $aux = array_filter($res->columns, static fn ($c) => $c->auxiliar);
            if ($aux !== []) {
                $res->advertencias[] = new Advertencia('COLUMNA_SIN_ENCABEZADO_CON_DATOS', 'INFO',
                    count($aux) . ' columna(s) con datos sin encabezado (auxiliares; no entran a la huella).', $sheet->index);
            }
        } else {
            $res->estado = 'DESCARTADA';
            $res->motivo = 'La hoja no coincide con ningún perfil conocido.';
            return $res;
        }
        if ($res->tieneFiltros) {
            $breakdown[] = ['filtro', -10];
        }
        if ($sheet->visibility !== 'VISIBLE') {
            $breakdown[] = ['hoja_oculta', -10];
        }
        $res->puntaje = max(0, min(100, array_sum(array_column($breakdown, 1))));
        $res->metadata['puntaje_detalle'] = $breakdown;
        if ($res->filasDetalle === 0) {
            $res->estado = 'DESCARTADA';
            $res->motivo = 'Coincide con un perfil pero no tiene registros de detalle (plantilla o sólo totales).';
        } else {
            $res->estado = 'CANDIDATA';
            $res->motivo = 'Coincide con el perfil y contiene registros.';
        }
        return $res;
    }

    private static function base(Sheet $sheet): SheetResult
    {
        $cols = [];
        foreach ($sheet->rows as $r) {
            foreach (array_keys($r->cells) as $c) {
                $cols[$c] = true;
            }
        }
        $range = null;
        if ($sheet->rows !== []) {
            $keys = array_keys($cols);
            $range = Coordinates::letter(min($keys)) . $sheet->rows[0]->index . ':' . Coordinates::letter(max($keys)) . $sheet->rows[count($sheet->rows) - 1]->index;
        }
        return new SheetResult($sheet->index, $sheet->name, $sheet->visibility, count($sheet->rows), count($cols), $range, $sheet->dimension,
            $sheet->cellsNonempty, $sheet->autofilter !== null || $sheet->filterMode, $sheet->hiddenRows, $sheet->hiddenCols !== [],
            $sheet->formulas, $sheet->formulasWithoutCache, count($sheet->merges));
    }

    /** @return array{value:?string,confidence:string,evidence:array<string,int>,source:string} */
    private function decideVariant(string $fam, SheetResult $sheet): array
    {
        $profs = Profiles::families()[$fam];
        if (count($profs) === 1) {
            return ['value' => $profs[0]->variantValue, 'confidence' => 'ALTA', 'evidence' => [], 'source' => 'UNICA'];
        }
        $rule = $profs[0]->variant;
        if ($rule === 'PAGO_CONCEPTO') {
            $col = $sheet->mapping?->colOf('CONCEPTO');
            $values = [];
            foreach ($sheet->rows as $r) {
                if ($r->kind === 'DETALLE' && $col !== null && isset($r->cells[$col])) {
                    $values[] = Values::normKey($r->cells[$col]->value);
                }
            }
            [$value, $counts] = Profiles::decideByWords($values, ['PENSION' => Profiles::PENSION_WORDS, 'NOMINA' => Profiles::NOMINA_WORDS]);
            return ['value' => $value, 'confidence' => $value !== null ? 'ALTA' : 'NINGUNA', 'evidence' => $counts, 'source' => 'CONTENIDO'];
        }
        $options = ['POLICIA' => Profiles::POLICIA_WORDS, 'BOMBEROS' => Profiles::BOMBEROS_WORDS];
        $titles = $sheet->header?->titleText ?? [];
        if ($rule === 'GRUPO') {
            $m = $sheet->mapping;
            $cols = $m !== null ? [$m->colOf('AREA'), $m->colOf('CARGO'), $m->colOf('DEPTO')] : [];
            $values = [];
            $detail = array_values(array_filter($sheet->rows, static fn ($r) => $r->kind === 'DETALLE'));
            foreach ($detail as $r) {
                $parts = [];
                foreach ($cols as $c) {
                    if ($c !== null && isset($r->cells[$c])) {
                        $parts[] = Values::normKey($r->cells[$c]->value);
                    }
                }
                $txt = implode(' ', $parts);
                if ($txt !== '') {
                    $values[] = $txt;
                }
            }
            [$value, $counts] = Profiles::decideByWords($values, $options);
            if ($value !== null && array_sum($counts) >= 0.5 * max(1, count($detail))) {
                return ['value' => $value, 'confidence' => 'ALTA', 'evidence' => $counts, 'source' => 'CONTENIDO'];
            }
            [$tvalue, $tcounts] = Profiles::decideByWords($titles, $options, 1.0);
            if ($tvalue !== null) {
                return ['value' => $tvalue, 'confidence' => 'MEDIA', 'evidence' => $tcounts, 'source' => 'TITULO'];
            }
            return ['value' => null, 'confidence' => 'NINGUNA', 'evidence' => $counts, 'source' => 'CONTENIDO'];
        }
        $gTitles = $sheet->titles !== [] ? $sheet->titles : $titles;
        [$value, $counts] = Profiles::decideByWords($gTitles, $options, 1.0);
        return ['value' => $value, 'confidence' => $value !== null ? 'MEDIA' : 'NINGUNA', 'evidence' => $counts, 'source' => 'TITULO'];
    }

    /**
     * @param list<SheetResult> $candidates
     * @return array{0:list<SheetResult>,1:list<Advertencia>}
     */
    private function select(array $candidates): array
    {
        if ($candidates === []) {
            return [[], []];
        }
        $fams = [];
        foreach ($candidates as $c) {
            if ($c->familia !== null) {
                $fams[$c->familia] = true;
            }
        }
        $fams = array_map('strval', array_keys($fams));
        sort($fams, SORT_STRING);
        $reps = Profiles::familyReps();
        $main = [];
        foreach ($fams as $f) {
            $isComp = false;
            foreach ($fams as $o) {
                if ($o !== $f && in_array($f, $reps[$o]->complementary, true)) {
                    $isComp = true;
                }
            }
            if (!$isComp) {
                $main[] = $f;
            }
        }
        if (count($main) > 1) {
            return [[], [new Advertencia('LIBRO_CON_PERFILES_DISTINTOS', 'REVISION',
                'El libro contiene hojas de perfiles distintos (p. ej. un libro de trabajo con copias de dispersión y tabulado); elija la hoja a analizar.')]];
        }
        $fam = $main[0];
        $pool = array_values(array_filter($candidates, static fn ($c) => $c->familia === $fam));
        usort($pool, static fn ($a, $b) => [-$a->puntaje, $a->index] <=> [-$b->puntaje, $b->index]);
        if ($reps[$fam]->multiSheet) {
            foreach (array_slice($pool, 1) as $c) {
                $c->motivo = 'Hoja del mismo documento (perfil multi-hoja).';
            }
            $ordered = $pool;
            usort($ordered, static fn ($a, $b) => $a->index <=> $b->index);
            $ordered[0]->motivo = 'Primera hoja de las ' . count($pool) . ' que forman el documento multi-hoja.';
            return [$ordered, []];
        }
        $top = $pool[0];
        if (count($pool) === 1) {
            $top->motivo = "Única hoja candidata (puntaje {$top->puntaje}).";
            return [[$top], []];
        }
        $close = array_values(array_filter($pool, fn ($c) => $top->puntaje - $c->puntaje < $this->margin));
        foreach ($pool as $c) {
            if (!in_array($c, $close, true)) {
                $c->motivo = "Puntaje menor que la hoja seleccionada ({$c->puntaje} vs {$top->puntaje}).";
            }
        }
        if (count($close) === 1) {
            $top->motivo = "Mayor puntaje ({$top->puntaje}) con margen suficiente frente a las demás hojas.";
            return [[$top], []];
        }
        $sets = [];
        foreach ($close as $c) {
            $sets[$c->index] = self::rowSet($c);
        }
        $pick = null;
        $pickKey = null;
        foreach ($close as $c) {
            $key = [count($sets[$c->index]), !($c->tieneFiltros || $c->filasOcultas > 0), -$c->index];
            if ($pick === null || $key > $pickKey) {
                [$pick, $pickKey] = [$c, $key];
            }
        }
        $base = $sets[$pick->index];
        $ok = $base !== [];
        foreach ($close as $c) {
            if ($c === $pick) {
                continue;
            }
            $other = $sets[$c->index];
            if ($other == $base) {
                $c->motivo = 'Contenido equivalente a la hoja seleccionada.';
            } elseif ($other !== [] && count($other) < count($base) && array_diff_key($other, $base) === [] && ($c->tieneFiltros || $c->filasOcultas > 0)) {
                $c->motivo = 'Vista filtrada (subconjunto) de la hoja seleccionada.';
            } else {
                $ok = false;
            }
        }
        if ($ok) {
            $pick->motivo = 'Fuente completa entre ' . count($close) . " hojas equivalentes o filtradas («{$pick->name}»).";
            return [[$pick], []];
        }
        $names = implode(', ', array_map(static fn ($c) => "«{$c->name}»", $close));
        foreach ($close as $c) {
            $c->motivo = "Puntaje equivalente ({$c->puntaje}) con contenido distinto: requiere revisión.";
        }
        return [[], [new Advertencia('AMBIGUEDAD_HOJAS', 'REVISION', "Las hojas $names tienen puntaje equivalente y contenido distinto.")]];
    }

    /** @return array<string,true> */
    private static function rowSet(SheetResult $r): array
    {
        if ($r->mapping === null) {
            return [];
        }
        $out = [];
        foreach ($r->rows as $x) {
            if ($x->kind === 'DETALLE') {
                $out[Fingerprint::rowHash(Fingerprint::canonicalRow($r->mapping->profile, $r->mapping, $x))] = true;
            }
        }
        return $out;
    }

    /**
     * @param list<StagingRow> $staging
     * @return list<array<string,mixed>>
     */
    private function records(Profile $perfil, SheetResult $r, array &$staging): array
    {
        $records = [];
        $key = $this->hashKey;
        if ($r->global !== null) {
            foreach ($r->global->lines as $ln) {
                $rec = Fingerprint::canonicalGlobalLine($ln);
                $records[] = $rec;
                $staging[] = new StagingRow($r->index, $ln['row'], false, $ln['amount']->value, $ln['amount']->status, $rec, null,
                    Fingerprint::rowHash($rec, $key), Fingerprint::keyHash($rec, $perfil->keyFields, $key));
            }
            return $records;
        }
        $m = $r->mapping;
        $acol = $perfil->amount !== null ? $m->colOf($perfil->amount) : null;
        $principalAcct = null;
        foreach ($perfil->fields as $f) {
            if ($f->kind === 'clabe') {
                $principalAcct = $f->canon;
                break;
            }
        }
        if ($principalAcct === null) {
            foreach ($perfil->fields as $f) {
                if ($f->kind === 'account' && in_array($f->canon, $perfil->identifiers, true)) {
                    $principalAcct = $f->canon;
                    break;
                }
            }
        }
        $acctCol = $principalAcct !== null ? $m->colOf($principalAcct) : null;
        foreach ($r->rows as $x) {
            if ($x->kind !== 'DETALLE') {
                continue;
            }
            $rec = Fingerprint::canonicalRow($perfil, $m, $x);
            $records[] = $rec;
            $datos = $rec;
            foreach ($m->cols as $col => $canon) {
                $fld = $perfil->field($canon);
                if ($fld === null) {
                    continue;
                }
                if (!$fld->huella) {
                    $datos[$canon] = isset($x->cells[$col]) ? Values::identifier($x->cells[$col]->value) : null;
                }
                if (in_array($fld->kind, ['account', 'clabe'], true) && self::truthy($datos[$canon] ?? null)) {
                    $d = Values::digitsOnly(Py::str($datos[$canon])) ?? '';
                    $datos[$canon] = $d !== '' ? '****' . substr($d, -4) : '****';
                }
            }
            foreach ($datos as $k => $v) {
                if (str_starts_with((string) $k, 'X:') && is_string($v)) {
                    $d = Values::digitsOnly($v) ?? '';
                    if (strlen($d) >= 10 && strlen($d) <= 20 && mb_strlen($v) - strlen($d) <= 8) {
                        $datos[$k] = '****' . substr($d, -4);
                    }
                }
            }
            $amt = $acol !== null && isset($x->cells[$acol]) ? Values::parseMoney($x->cells[$acol]->value) : null;
            $acct = $acctCol !== null && isset($x->cells[$acctCol]) ? Values::digitsOnly(Values::identifier($x->cells[$acctCol]->value)) : null;
            $staging[] = new StagingRow($r->index, $x->index, $x->hidden, $amt?->value, $amt?->status ?? 'NO_APLICA', $datos, $acct,
                Fingerprint::rowHash($rec, $key), Fingerprint::keyHash($rec, $perfil->keyFields, $key));
        }
        return $records;
    }

    private static function truthy(mixed $v): bool
    {
        return !($v === null || $v === '' || $v === false || $v === 0 || $v === 0.0 || $v === []);
    }

    /** @param list<Advertencia> $ws @return list<Advertencia> */
    private static function dedupe(array $ws): array
    {
        $seen = [];
        $out = [];
        foreach ($ws as $w) {
            $k = $w->codigo . "\x1F" . ($w->hoja ?? 'N') . "\x1F" . $w->mensaje;
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $out[] = $w;
            }
        }
        return $out;
    }

    /** @return list<Advertencia> */
    private static function workbookWarnings(Workbook $wb): array
    {
        $out = [];
        if ($wb->hasMacros) {
            $out[] = new Advertencia('MACROS_PRESENTES', 'INFO', 'El libro contiene macros VBA: se leyó como datos; nunca se ejecutan.');
        }
        if ($wb->externalLinks) {
            $out[] = new Advertencia('VINCULOS_EXTERNOS', 'ADVERTENCIA',
                "El libro tiene {$wb->externalLinks} vínculo(s) a libros externos; no se resuelven ni se accede a red.");
        }
        if ($wb->ddeLinks) {
            $out[] = new Advertencia('VINCULOS_DDE', 'ADVERTENCIA', 'El libro declara vínculos DDE/OLE; no se ejecutan.');
        }
        if ($wb->suspiciousNames) {
            $out[] = new Advertencia('NOMBRES_DEFINIDOS_EXTERNOS', 'ADVERTENCIA', "{$wb->suspiciousNames} nombre(s) definido(s) apuntan a recursos externos.");
        }
        $ext = $wb->externalRelationships;
        unset($ext['hyperlink']);
        if ($ext !== []) {
            ksort($ext, SORT_STRING);
            $parts = [];
            foreach ($ext as $k => $v) {
                $parts[] = "$k ($v)";
            }
            $out[] = new Advertencia('RELACIONES_EXTERNAS', 'ADVERTENCIA', 'Relaciones a recursos externos: ' . implode(', ', $parts) . '.');
        }
        if (($wb->externalRelationships['hyperlink'] ?? 0) > 0) {
            $out[] = new Advertencia('HIPERVINCULOS_EXTERNOS', 'INFO', "{$wb->externalRelationships['hyperlink']} hipervínculo(s) externo(s); no se siguen.");
        }
        if ($wb->format === 'OLE2') {
            $out[] = new Advertencia('FORMULAS_NO_DISPONIBLES_XLS', 'INFO', 'El formato .xls no expone el texto de las fórmulas; se usan los valores almacenados.');
        }
        return $out;
    }

    /** @return list<Advertencia> */
    private static function sheetWarnings(Sheet $s, SheetResult $r): array
    {
        $out = [];
        if ($s->externalFormulas) {
            $out[] = new Advertencia('FORMULAS_EXTERNAS', 'ADVERTENCIA', "{$s->externalFormulas} fórmula(s) referencian libros externos.", $s->index);
        }
        if ($s->ddeFormulas) {
            $out[] = new Advertencia('FORMULAS_DDE', 'ADVERTENCIA', "{$s->ddeFormulas} fórmula(s) con patrón DDE; no se ejecutan.", $s->index);
        }
        if ($s->formulasWithoutCache) {
            $out[] = new Advertencia('FORMULAS_SIN_CACHE', 'INFO', "{$s->formulasWithoutCache} fórmula(s) sin valor cacheado.", $s->index);
        }
        if ($s->visibility !== 'VISIBLE' && $r->estado === 'CANDIDATA') {
            $out[] = new Advertencia('HOJA_OCULTA_CANDIDATA', 'INFO', "La hoja «{$s->name}» está " . mb_strtolower($s->visibility) . '.', $s->index);
        }
        return $out;
    }
}
