<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Fixtures;

final class IdentityFixtures
{
    public static function rfc(int $i): string
    {
        return sprintf('FICT%06dAB%d', $i, $i % 10);
    }

    public static function curp(int $i): string
    {
        return sprintf('FAXT%06dHMCBCD%s%d', $i, chr(65 + $i % 26), $i % 10);
    }

    /** @param array<string,mixed> $kw @return array<string,mixed> */
    public static function person(int $i, array $kw = []): array
    {
        return [...['num' => sprintf('9%04d', $i), 'nombre' => "FICTICIO$i APELLIDO$i NOMBRE$i", 'rfc' => self::rfc($i), 'curp' => self::curp($i),
                    'sueldo' => '10000.00', 'isr' => '1500.00', 'pension' => '0', 'area' => 'DIRECCION DE SEGURIDAD PUBLICA Y TRANSITO',
                    'cargo' => 'POLICIA'], ...$kw];
    }

    /** @param list<array<string,mixed>> $people */
    public static function tabulado(array $people, bool $hiddenRows = false, bool $deduccionesNegativas = false): string
    {
        $b = new Book('Tabla');
        $b->append(0, ['1 AL 15 FICTICIO']);
        $b->append(0, [null]);
        $b->append(0, ['Pza.', 'Núm.', 'Nombre', 'Depto', 'Area', 'Puesto', 'Cargo', 'R.F.C.', 'CURP', 'Percep.', 'Deduc.', 'TOTAL',
                       '001 SUELDO BASE', '070 ISR', '044 PENSION ALIMENTICIA']);
        $tot = array_fill(0, 6, '0');
        foreach ($people as $k => $p) {
            $per = (string) $p['sueldo'];
            $ded = bcadd((string) $p['isr'], (string) $p['pension'], 2);
            $net = bcsub($per, $ded, 2);
            $sg = $deduccionesNegativas ? '-1' : '1';
            $pension = bccomp((string) $p['pension'], '0', 2) !== 0 ? bcmul($sg, (string) $p['pension'], 2) : null;
            $vals = [$per, $ded, $net, (string) $p['sueldo'], bcmul($sg, (string) $p['isr'], 2), $pension];
            $b->append(0, ['P' . ($k + 1), $p['num'], $p['nombre'], '1201', $p['area'], '3310', $p['cargo'], $p['rfc'], $p['curp'],
                ...array_map(static fn ($v) => $v !== null ? (float) $v : null, $vals)]);
            foreach ($vals as $j => $v) {
                $tot[$j] = bcadd($tot[$j], $v ?? '0', 2);
            }
        }
        if ($hiddenRows && count($people) > 1) {
            $b->hideRow(0, 5);
        }
        $b->append(0, [...array_fill(0, 9, null), ...array_map('floatval', $tot)]);
        return $b->xlsx();
    }

    /** @param list<array{0:string,1:string,2:string,3:string,4:string,5:string}> $rows */
    public static function santander(array $rows, bool $pension = false): string
    {
        $b = new Book('Pago de Nomina');
        foreach ([[null], [null], ['MACRO PAGO DE NOMINA', null, 'CUENTA DE CARGO'], ['FECHA DE PAGO (DD/MM/AAAA)'], [null], [null]] as $r) {
            $b->append(0, $r);
        }
        $b->append(0, ['TIPO DE REGISTRO', 'NUMERO DE SECUENCIA', 'NUMERO EMPLEADO', 'APELLIDO PATERNO DEL EMPLEADO', 'APELLIDO MATERNO DEL EMPLEADO',
                       'NOMBRE DEL EMPLEADO', 'CUENTA', 'IMPORTE', 'ID_CONCEPTO']);
        $total = '0';
        foreach ($rows as $k => [$num, $pat, $mat, $nom, $cta, $imp]) {
            $b->append(0, ['2', $k + 1, $num, $pat, $mat, $nom, $cta, (float) $imp, $pension ? '02 PAGO DE PENSION' : '01 PAGO DE NOMINA']);
            $total = bcadd($total, $imp, 2);
        }
        $b->append(0, [...array_fill(0, 7, null), (float) $total]);
        return $b->xlsx();
    }

    /** @param list<array{0:?string,1:string,2:?string,3:string,4:string}> $rows */
    public static function bbva(array $rows): string
    {
        $b = new Book('BBVA');
        $b->append(0, ['GRUPO FICTICIO']);
        $b->append(0, ['Núm.', 'Nombre', 'R.F.C.', 'IMPORTE', 'CTA BBVA']);
        $total = '0';
        foreach ($rows as [$num, $nom, $r, $imp, $cta]) {
            $b->append(0, [$num, $nom, $r, (float) $imp, $cta]);
            $total = bcadd($total, $imp, 2);
        }
        $b->append(0, [null, null, null, (float) $total]);
        return $b->xlsx();
    }

    /** @param list<array{0:string,1:string,2:string}> $rows */
    public static function interbancaria(array $rows, bool $pension = false): string
    {
        $b = new Book('Inter sin Compro Fiscal FA');
        $b->set(0, 1, 1, 'OBLIGATORIO');
        $b->set(0, 1, 3, 'SANTANDER');
        foreach (['CUENTA DE CARGO', 'CUENTA DE ABONO', 'BANCO RECEPTOR', 'BENEFICIARIO', 'SUCURSAL', 'IMPORTE', 'PLAZA BANXICO', 'CONCEPTO'] as $c => $h) {
            $b->set(0, $c + 1, 7, $h);
        }
        $s = '0';
        $concepto = $pension ? 'PAGO DE PENSION ALIMENTICIA FICTICIA' : 'PAGO DE NOMINA QUINCENA FICTICIA';
        foreach ($rows as $i => [$clabe, $name, $imp]) {
            foreach (['65500000001', $clabe, 'BANCO', $name, '00000', (float) $imp, 1001, $concepto] as $c => $v) {
                $b->set(0, $c + 1, 8 + $i, $v);
            }
            $s = bcadd($s, $imp, 2);
        }
        $b->set(0, 6, 8 + count($rows), (float) $s);
        return $b->xls();
    }

    /**
     * @param list<array{0:string,1:string,2:float}> $perc
     * @param list<array{0:string,1:string,2:float}> $ded
     */
    public static function polizaGlobal(array $perc, array $ded, string $titulo = 'POLICIAS 1 AL 15 FICTICIO'): string
    {
        $b = new Book('Hoja1');
        AnalysisFixtures::globalPoliza($b, 0, $titulo, $perc, $ded, 3);
        return $b->xlsx();
    }

    public static function polizaEgreso(): string
    {
        $b = new Book('SANTANDER $ 1,000.00');
        $tr = AnalysisFixtures::egreso($b, 0, [['5111', '1', 'SUELDOS', 1000.0, null], ['1112', '31', 'BANCO SANTANDER', null, 1000.0]]);
        $s2 = $b->add('BBVA');
        $tr2 = AnalysisFixtures::egreso($b, $s2, [['5111', '1', 'SUELDOS', 250.0, null], ['1112', '30', 'BANCO BBVA', null, 250.0]]);
        $data = Book::withCache($b->xlsx(), 0, ["I$tr" => '1000', "J$tr" => '1000']);
        return Book::withCache($data, 1, ["I$tr2" => '250', "J$tr2" => '250']);
    }

    /** @param list<array{0:string,1:string}> $hojas */
    public static function polizaEgresoBancos(array $hojas): string
    {
        $b = new Book(mb_substr($hojas[0][0], 0, 31));
        $trs = [];
        foreach ($hojas as $i => [$nombre, $monto]) {
            $s = $i === 0 ? 0 : $b->add(mb_substr($nombre, 0, 31));
            $trs[] = AnalysisFixtures::egreso($b, $s, [['5111', '1', 'SUELDOS', (float) $monto, null], ['1112', '31', 'BANCO', null, (float) $monto]]);
        }
        $data = $b->xlsx();
        foreach ($hojas as $i => [, $monto]) {
            $data = Book::withCache($data, $i, ['I' . $trs[$i] => $monto, 'J' . $trs[$i] => $monto]);
        }
        return $data;
    }
}
