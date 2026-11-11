<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Fixtures;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class AnalysisFixtures
{
    public const RFC = 'XAXX010101000';
    public const CURP = 'XEXX010101HNEXXXA4';

    public static function name(int $i): string
    {
        return sprintf('PERSONA FICTICIA %03d', $i % 40 + 1);
    }

    /** @return list<array{0:string,1:string,2:float}> */
    public static function records(int $n, int $seed = 7): array
    {
        mt_srand($seed, MT_RAND_MT19937);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $u = mt_rand() / mt_getrandmax();
            $out[] = [sprintf('%05d', 1000 + $i), self::name($i), Book::pyRound(1000 + 19000 * $u, 2)];
        }
        mt_srand();
        return $out;
    }

    /** @param list<array{0:string,1:string,2:float}> $rows */
    public static function bbva(Book $b, int $s, array $rows, string $title = 'GRUPO FICTICIO', ?string $total = 'formula', int $start = 1): int
    {
        $b->set($s, 1, $start, $title);
        $b->append($s, ['Núm.', 'Nombre', 'R.F.C.', 'IMPORTE', 'CTA BBVA']);
        foreach ($rows as $i => [$num, $name, $amount]) {
            $b->append($s, [$num, $name, self::RFC, $amount, sprintf('01%08d', $i)]);
        }
        $last = $b->maxRow($s);
        if ($total === 'formula') {
            $b->append($s, [null, null, null, '=SUM(D' . ($start + 2) . ":D$last)"]);
        } elseif ($total !== null) {
            $b->append($s, [null, null, null, $total]);
        }
        return $last + 1;
    }

    /**
     * @param list<array{0:string,1:string,2:float}> $rows
     * @param string|callable(int):string $concepto
     */
    public static function santander(Book $b, int $s, array $rows, string|callable $concepto = '01 PAGO DE NOMINA', float|string|null $total = null): void
    {
        foreach ([[null], [null], ['MACRO PAGO DE NOMINA', null, 'CUENTA DE CARGO'], ['FECHA DE PAGO (DD/MM/AAAA)'], [null], [null]] as $r) {
            $b->append($s, $r);
        }
        $b->append($s, ['TIPO DE REGISTRO', 'NUMERO DE SECUENCIA', 'NUMERO EMPLEADO', 'APELLIDO PATERNO DEL EMPLEADO',
                        'APELLIDO MATERNO DEL EMPLEADO', 'NOMBRE DEL EMPLEADO', 'CUENTA', 'IMPORTE', 'ID_CONCEPTO']);
        foreach ($rows as $k => [$num, $name, $amount]) {
            $i = $k + 1;
            $b->append($s, ['2', $i, $num, 'PATERNO', 'MATERNO', $name, sprintf('600%08d', (int) $num), $amount, is_callable($concepto) ? $concepto($i) : $concepto]);
        }
        if ($total !== null) {
            $b->append($s, [null, null, null, null, null, null, null, $total]);
        }
    }

    /**
     * @param list<array{0:string,1:string,2:float}> $rows
     * @return array{0:int,1:int}
     */
    public static function tabulado(Book $b, int $s, array $rows, string $area = 'DIRECCION DE SEGURIDAD PUBLICA Y TRANSITO', string $cargo = 'POLICIA',
                                    string $title = '16 AL 31-ENE-2030 (FICTICIO)'): array
    {
        $b->append($s, [$title]);
        $b->append($s, [null]);
        $b->append($s, ['Pza.', 'Núm.', 'Nombre', 'Depto', 'Area', 'Puesto', 'Cargo', 'R.F.C.', 'CURP', 'Percep.', 'Deduc.', 'TOTAL',
                        '001 SUELDO BASE', '070 ISR']);
        foreach ($rows as $k => [$num, $name, $amount]) {
            $per = Book::pyRound($amount * 1.25, 2);
            $ded = Book::pyRound($amount - $per, 2);
            $b->append($s, ['P' . ($k + 1), $num, $name, '1201', $area, '3310', $cargo, self::RFC, self::CURP, $per, $ded, $amount, $per, $ded]);
        }
        [$first, $last] = [4, $b->maxRow($s)];
        $b->append($s, [...array_fill(0, 9, null), "=SUM(J$first:J$last)", "=SUM(K$first:K$last)", "=SUM(L$first:L$last)", "=SUM(M$first:M$last)",
                        "=SUM(N$first:N$last)"]);
        return [$first, $last];
    }

    /**
     * @param list<array{0:string,1:string,2:float}> $perc
     * @param list<array{0:string,1:string,2:float}> $ded
     */
    public static function globalPoliza(Book $b, int $s, string $title, array $perc, array $ded, int $empleados): void
    {
        foreach (['H. AYUNTAMIENTO FICTICIO', 'DIRECCION DE ADMINISTRACION', 'SUBDIRECCION DE RECURSOS HUMANOS', 'NOMINA GENERAL', 'POLIZA CONTABLE', $title] as $t) {
            $b->append($s, [$t]);
        }
        $b->append($s, [null]);
        $b->append($s, ['PERCEPCIONES', null, null, null, 'DEDUCCIONES']);
        $b->sheet($s)->mergeCells('A8:C8');
        $b->sheet($s)->mergeCells('E8:G8');
        for ($i = 0; $i < max(count($perc), count($ded)); $i++) {
            $row = array_fill(0, 7, null);
            if ($i < count($perc)) {
                [$row[0], $row[1], $row[2]] = $perc[$i];
            }
            if ($i < count($ded)) {
                [$row[4], $row[5], $row[6]] = $ded[$i];
            }
            $b->append($s, $row);
        }
        $tp = Book::pyRound(array_sum(array_column($perc, 2)), 2);
        $td = Book::pyRound(array_sum(array_column($ded, 2)), 2);
        $b->append($s, [null]);
        $b->append($s, [null, 'TOTAL PERCEPCIONES:', $tp, null, null, 'TOTAL DEDUCCIONES:', $td]);
        $b->append($s, [null]);
        $b->append($s, [null, 'Cantidad de Empleados:', $empleados, null, null, 'NETO:', Book::pyRound($tp - $td, 2)]);
    }

    /** @param list<array{0:string,1:string,2:string,3:?float,4:?float}> $lines */
    public static function egreso(Book $b, int $s, array $lines): int
    {
        $b->append($s, ['PE', 'QUINCENA FICTICIA', 'GRUPO FICTICIO']);
        $b->append($s, [null]);
        $b->append($s, [null, 'BANCO FICTICIO $ 0.00', null, null, null, null, null, 'POLIZA PE-']);
        $b->append($s, [null, 'CUENTA', 'SCTA', 'SSCTA', 'SSSCTA', 'SSSSCTA', 'REF', 'CONCEPTO', 'DEBE', 'HABER']);
        foreach ($lines as [$cta, $scta, $concepto, $debe, $haber]) {
            $b->append($s, [null, $cta, $scta, '1', null, null, null, $concepto, $debe, $haber]);
        }
        [$first, $last] = [5, $b->maxRow($s)];
        $b->append($s, [...array_fill(0, 7, null), 'SUMAS IGUALES', "=SUM(I$first:I$last)", "=SUM(J$first:J$last)"]);
        return $last + 1;
    }

    /** @param list<array{0:string,1:string,2:float}> $rows */
    public static function xlsInterbancaria(array $rows, string $concepto = 'PAGO DE NOMINA QUINCENA FICTICIA', bool $total = true): string
    {
        $b = new Book('Inter sin Compro Fiscal FA');
        $b->set(0, 1, 1, 'OBLIGATORIO');
        $b->set(0, 1, 3, 'SANTANDER');
        $hdr = ['CUENTA DE CARGO', 'CUENTA DE ABONO', 'BANCO RECEPTOR', 'BENEFICIARIO', 'SUCURSAL', 'IMPORTE', 'PLAZA BANXICO', 'CONCEPTO'];
        foreach ($hdr as $c => $h) {
            $b->set(0, $c + 1, 7, $h);
        }
        $sum = 0.0;
        foreach ($rows as $i => [, $name, $amount]) {
            foreach (['65500000001', sprintf('0021800000000%05d', $i), 'BANCO', $name, '00000', $amount, 1001, $concepto] as $c => $v) {
                $b->set(0, $c + 1, 8 + $i, $v);
            }
            $sum += $amount;
        }
        if ($total) {
            $b->set(0, 6, 8 + count($rows), Book::pyRound($sum, 2));
        }
        $tpl = $b->add('Interb sin Compro Fiscal');
        foreach ($hdr as $c => $h) {
            $b->set($tpl, $c + 1, 7, $h);
        }
        $cat = $b->add('Anexo- Catalogo de Bancos');
        foreach (['NOMBRE DE INSTITUCION', 'NO. INST', 'CLAVE'] as $c => $h) {
            $b->set($cat, $c + 1, 6, $h);
        }
        for ($i = 0; $i < 20; $i++) {
            $b->set($cat, 1, 7 + $i, "BANCO FICTICIO $i");
            $b->set($cat, 2, 7 + $i, $i);
        }
        return $b->xls();
    }

    /**
     * @param list<array{0:string,1:string,2:float}> $rows
     * @param array<int,true> $hidden
     */
    public static function cacheTabulado(string $data, array $rows, int $first, int $last, array $hidden = []): string
    {
        $per = array_map(static fn ($r) => Book::pyRound($r[2] * 1.25, 2), $rows);
        $ded = [];
        foreach ($rows as $k => $r) {
            $ded[] = Book::pyRound($r[2] - $per[$k], 2);
        }
        $net = array_column($rows, 2);
        $idx = $hidden !== [] ? array_values(array_filter(array_keys($rows), static fn ($i) => !isset($hidden[$i]))) : array_keys($rows);
        $sum = static function (array $values) use ($idx): string {
            $t = 0.0;
            foreach ($idx as $i) {
                $t += $values[$i];
            }
            return \ConciliaRH\Engine\Support\Py::reprFloat(Book::pyRound($t, 2));
        };
        $tr = $last + 1;
        $cached = [];
        foreach ([['J', $per], ['K', $ded], ['L', $net], ['M', $per], ['N', $ded]] as [$col, $values]) {
            $cached["$col$tr"] = $sum($values);
        }
        return Book::withCache($data, 0, $cached);
    }

    /** @return array<string,string> */
    public static function generate(string $dest): array
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0775, true);
        }
        $base = self::records(6);
        $totalBase = Book::pyRound(array_sum(array_column($base, 2)), 2);
        $tb = \ConciliaRH\Engine\Support\Py::reprFloat($totalBase);
        $files = [];

        $b = new Book('Hoja1');
        $tr = self::bbva($b, 0, $base);
        $files['a01_una_hoja.xlsx'] = Book::withCache($b->xlsx(), 0, ["D$tr" => $tb]);

        $b = new Book('Notas');
        $b->append(0, ['Documento de prueba']);
        $b->append(0, ['Sin datos tabulares']);
        $d = $b->add('Datos');
        $tr = self::bbva($b, $d, $base);
        $r = $b->add('Resumen');
        $b->append($r, ['Total', $totalBase]);
        $files['a02_varias_hojas.xlsx'] = Book::withCache($b->xlsx(), 1, ["D$tr" => $tb]);

        $b = new Book('Plantilla');
        $b->append(0, ['Núm.', 'Nombre', 'R.F.C.', 'IMPORTE', 'CTA BBVA']);
        $h = $b->add('Datos ocultos');
        $tr = self::bbva($b, $h, $base);
        $b->sheet($h)->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $files['a03_hoja_oculta.xlsx'] = Book::withCache($b->xlsx(), 1, ["D$tr" => $tb]);

        $b = new Book('Visible');
        $tr = self::bbva($b, 0, $base);
        $vh = $b->add('Copia');
        self::bbva($b, $vh, $base);
        $b->sheet($vh)->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);
        $files['a04_very_hidden.xlsx'] = Book::withCache(Book::withCache($b->xlsx(), 0, ["D$tr" => $tb]), 1, ["D$tr" => $tb]);

        $rows20 = self::records(20, 11);
        $b = new Book('Tabla');
        [$first, $last] = self::tabulado($b, 0, $rows20);
        for ($x = $first + 5; $x < $first + 10; $x++) {
            $b->hideRow(0, $x);
        }
        $files['a05_filas_ocultas.xlsx'] = self::cacheTabulado($b->xlsx(), $rows20, $first, $last);

        $b = new Book('Tabla');
        [$first, $last] = self::tabulado($b, 0, $rows20);
        $b->sheet(0)->setAutoFilter("A3:N$last");
        $hidden = array_fill_keys(array_diff(range(0, 19), [2, 7, 13]), true);
        foreach (array_keys($hidden) as $i) {
            $b->hideRow(0, $first + $i);
        }
        foreach (['J', 'K', 'L', 'M', 'N'] as $col) {
            $b->set(0, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($col), $last + 1, "=SUBTOTAL(9,$col$first:$col$last)");
        }
        $files['a06_filtro.xlsx'] = self::cacheTabulado(Book::withHiddenRows($b->xlsx(), 0, array_map(static fn (int $i) => $first + $i, array_keys($hidden))),
            $rows20, $first, $last, $hidden);

        $b = new Book('Hoja1');
        foreach (['TITULO FICTICIO', 'SUBTITULO', 'PERIODO FICTICIO'] as $t) {
            $b->append(0, [$t]);
        }
        $tr = self::bbva($b, 0, $base, 'GRUPO', 'formula', 4);
        $files['a07_encabezado_fila5.xlsx'] = Book::withCache($b->xlsx(), 0, ["D$tr" => $tb]);

        $b = new Book('Hoja1');
        $b->append(0, ['PENSIONES FICTICIAS']);
        $b->append(0, ['DATOS DEL TRABAJADOR', null, 'PAGO', null]);
        $b->sheet(0)->mergeCells('A2:B2');
        $b->sheet(0)->mergeCells('C2:D2');
        $b->append(0, ['Núm.', 'Nombre', 'TOTAL', 'Cuenta']);
        foreach ($base as $i => [$num, $name, $amount]) {
            $b->append(0, [$num, $name, $amount, sprintf('01%08d', $i)]);
        }
        $files['a08_encabezado_combinado.xlsx'] = $b->xlsx();

        $b = new Book('Pago de Nomina');
        self::santander($b, 0, $base, total: $totalBase);
        $files['a09_total.xlsx'] = $b->xlsx();

        $b = new Book('Pago de Nomina');
        self::santander($b, 0, $base, total: $totalBase);
        $b->append(0, [...array_fill(0, 7, null), Book::pyRound($totalBase + 1000, 2)]);
        $files['a10_dos_totales.xlsx'] = $b->xlsx();

        $b = new Book('Pago de Nomina');
        self::santander($b, 0, $base, total: Book::pyRound($totalBase + 0.5, 2));
        $files['a11_total_incorrecto.xlsx'] = $b->xlsx();

        $files['a12_total_formula.xlsx'] = $files['a01_una_hoja.xlsx'];

        $b = new Book('Hoja1');
        self::bbva($b, 0, $base);
        $files['a13_formula_sin_cache.xlsx'] = $b->xlsx();

        $shuffled = $base;
        mt_srand(3, MT_RAND_MT19937);
        shuffle($shuffled);
        mt_srand();
        $b = new Book('Pago de Nomina');
        self::santander($b, 0, $shuffled);
        $files['a14_orden_distinto.xlsx'] = $b->xlsx();

        $b = new Book('Nomina');
        self::santander($b, 0, $base, total: $totalBase);
        $b->sheet(0)->getColumnDimension('F')->setWidth(40);
        $b->sheet(0)->getStyle('F8')->getFont()->setBold(true);
        $files['a16_formato_distinto.xlsx'] = $b->xlsx();

        $b = new Book('Hoja1');
        $tr = self::bbva($b, 0, $base);
        for ($i = 0; $i < count($base); $i++) {
            $b->set(0, 7, 3 + $i, 'revisado');
        }
        $files['a17_columnas_auxiliares.xlsx'] = Book::withCache($b->xlsx(), 0, ["D$tr" => $tb]);

        $b = new Book('Pago de Nomina');
        self::santander($b, 0, $base, static fn (int $i) => $i % 2 ? '01 PAGO DE NOMINA' : '06 PAGO DE PENSION');
        $files['a18_perfil_ambiguo.xlsx'] = $b->xlsx();

        $b = new Book('Version A');
        self::bbva($b, 0, $base, total: null);
        $b2 = $b->add('Version B');
        self::bbva($b, $b2, self::records(6, 99), total: null);
        $files['a18b_hojas_ambiguas.xlsx'] = $b->xlsx();

        $b = new Book('Inventario');
        $b->append(0, ['PRODUCTO', 'PRECIO', 'EXISTENCIA']);
        for ($i = 0; $i < 5; $i++) {
            $b->append(0, ["ARTICULO $i", 10.5 * $i, $i]);
        }
        $files['a19_perfil_desconocido.xlsx'] = $b->xlsx();

        $files['a21_interbancaria.xls'] = self::xlsInterbancaria($base);
        $files['a21b_pension_interbancaria.xls'] = self::xlsInterbancaria($base, 'PAGO DE PENSION QUINCENA FICTICIA', false);

        $b = new Book('Pago de Nomina');
        self::santander($b, 0, $base, total: $totalBase);
        $files['a22_santander.xlsm'] = Book::toXlsm($b->xlsx());

        $files['a23_links_externos.xlsx'] = self::externalLinks($base, $tb);

        $b = new Book('Hoja1');
        $b->append(0, ['GRUPO']);
        $b->append(0, ['Núm.', 'Nombre', 'R.F.C.', 'IMPORTE', 'CTA BBVA']);
        foreach (['$1,234.50', '1 234.50', '(100.00)', 'abc', '13,585,616.79', '  $ 2,000.00  '] as $i => $amount) {
            $b->append(0, [(string) (2000 + $i), self::name($i), self::RFC, $amount, sprintf('01%08d', $i)]);
        }
        $files['a24_montos_raros.xlsx'] = $b->xlsx();

        $rows8 = self::records(8, 21);
        foreach ([['a25_tabulado_policia.xlsx', 'DIRECCION DE SEGURIDAD PUBLICA Y TRANSITO', 'POLICIA', 'POLICIAS'],
                  ['a26_tabulado_bomberos.xlsx', 'DIRECCION DE PROTECCION CIVIL Y BOMBEROS', 'BOMBERO "A"', 'BOMBEROS']] as [$name, $area, $cargo, $grupo]) {
            $b = new Book('Tabla');
            [$first, $last] = self::tabulado($b, 0, $rows8, $area, $cargo);
            $g = $b->add('Hoja1');
            $per = Book::pyRound(array_sum(array_map(static fn ($r) => Book::pyRound($r[2] * 1.25, 2), $rows8)), 2);
            $ded = Book::pyRound(array_sum(array_map(static fn ($r) => Book::pyRound($r[2] - Book::pyRound($r[2] * 1.25, 2), 2), $rows8)), 2);
            self::globalPoliza($b, $g, "$grupo 16 AL 31-ENE-2030 FICTICIO", [['1', 'SUELDO BASE', $per]], [['70', 'ISR', -$ded]], count($rows8));
            $files[$name] = self::cacheTabulado($b->xlsx(), $rows8, $first, $last);
        }

        $b = new Book('DISPERSADA');
        self::santander($b, 0, $base);
        $t = $b->add('TABULADA');
        self::tabulado($b, $t, $base);
        $files['a27_libro_trabajo.xlsx'] = $b->xlsx();

        $b = new Book('BANCO A $ 1,000.00');
        $tr = self::egreso($b, 0, [['5111', '1', 'SUELDOS', 1000.0, null], ['1112', '31', 'BANCO A', null, 1000.0]]);
        $s2 = $b->add('RETENCIONES');
        $tr2 = self::egreso($b, $s2, [['2117', '1', 'ISR', 250.0, null], ['1112', '30', 'TRASPASO', null, 250.0]]);
        $data = Book::withCache($b->xlsx(), 0, ["I$tr" => '1000', "J$tr" => '1000']);
        $files['a28_poliza_egreso.xlsx'] = Book::withCache($data, 1, ["I$tr2" => '250', "J$tr2" => '250']);

        $b = new Book('Hoja1');
        self::globalPoliza($b, 0, 'BOMBEROS 1 AL 15-ENE-2030 FICTICIO', [['1', 'SUELDO BASE', 5000.0], ['3', 'COMPENSACION', 1000.0]],
            [['70', 'ISR', 800.0], ['15', 'ISSEMYM', 200.0]], 3);
        $files['a29_global_bomberos.xlsx'] = $b->xlsx();

        $out = [];
        foreach ($files as $name => $data) {
            file_put_contents("$dest/$name", $data);
            $out[$name] = "$dest/$name";
        }
        return $out;
    }

    /** @param list<array{0:string,1:string,2:float}> $base */
    private static function externalLinks(array $base, string $tb): string
    {
        $b = new Book('Hoja1');
        $tr = self::bbva($b, 0, $base);
        $b->set(0, 7, 3, "=[1]Hoja1!A1");
        $b->set(0, 7, 4, "=cmd|' /c calc'!A0");
        $data = Book::withCache($b->xlsx(), 0, ["D$tr" => $tb, 'G3' => '0', 'G4' => '0']);
        $wb = Book::read($data, 'xl/workbook.xml');
        $rels = Book::read($data, 'xl/_rels/workbook.xml.rels');
        $ct = Book::read($data, '[Content_Types].xml');
        if (!str_contains($wb, '<definedNames>')) {
            $wb = str_replace('</workbook>', '<definedNames><definedName name="externo">[1]Hoja1!$A$1</definedName></definedNames></workbook>', $wb);
        }
        $rels = str_replace('</Relationships>', '<Relationship Id="rIdExt1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/externalLink" '
            . 'Target="externalLinks/externalLink1.xml"/></Relationships>', $rels);
        $ct = str_replace('</Types>', '<Override PartName="/xl/externalLinks/externalLink1.xml" '
            . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.externalLink+xml"/></Types>', $ct);
        $ext = '<?xml version="1.0" encoding="UTF-8"?><externalLink xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<externalBook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="rId1"/></externalLink>';
        $extRels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/externalLinkPath" '
            . 'Target="file:///C:/ficticio/otro.xlsx" TargetMode="External"/></Relationships>';
        return Book::rewriteZip($data, ['xl/workbook.xml' => $wb, 'xl/_rels/workbook.xml.rels' => $rels, '[Content_Types].xml' => $ct],
            ['xl/externalLinks/externalLink1.xml' => $ext, 'xl/externalLinks/_rels/externalLink1.xml.rels' => $extRels]);
    }
}
