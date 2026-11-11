<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Identity;

use ConciliaRH\Engine\Support\Dec;
use ConciliaRH\Engine\Support\Py;

final class Records
{
    public const TOLERANCIA_CONCEPTOS = '0.01';
    public const BANCO_POR_FAMILIA = ['TESORERIA_SANTANDER' => 'SANTANDER', 'TESORERIA_BBVA' => 'BBVA', 'TESORERIA_PENSION_BBVA' => 'BBVA',
                                      'TESORERIA_INTERBANCARIA' => 'INTERBANCARIA'];

    /** @return array{0:?string,1:string} */
    public static function money(mixed $v): array
    {
        if ($v === null || $v === '') {
            return [null, 'VACIO'];
        }
        $s = Py::str($v);
        if (preg_match('/^-?\d+(\.\d+)?$/Du', $s) === 1) {
            if (Dec::ge(Dec::abs($s), '1000000000000')) {
                return [null, 'INVALIDO'];
            }
            return [Dec::quantizeHalfEven($s, 2), 'OK'];
        }
        return [null, 'INVALIDO'];
    }

    public static function dateIso(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/u', Py::str($v), $m) !== 1) {
            return null;
        }
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if (!($y >= 1900 && $y <= 2100 && $mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    public static function txt(mixed $v, int $limit = 200): ?string
    {
        $s = PersonIds::cleanText($v);
        return $s !== null ? Py::sub($s, $limit) : null;
    }

    public static function identity(mixed $numero = null, mixed $nombre = null, mixed $rfc = null, mixed $curp = null): Ident
    {
        $i = new Ident();
        $i->numeroOriginal = self::txt($numero, 60);
        [$i->numero, $i->numeroValido] = PersonIds::employeeNumber($numero);
        $i->nombreOriginal = self::txt($nombre, 200);
        $i->nombreKey = PersonIds::nameKey($nombre);
        if ($i->nombreKey !== null) {
            $i->nombreKey = Py::sub($i->nombreKey, 200);
        }
        $i->rfcOriginal = self::txt($rfc, 40);
        [$i->rfc, $i->rfcValido] = PersonIds::rfc($rfc);
        $i->curpOriginal = self::txt($curp, 40);
        [$i->curp, $i->curpValida] = PersonIds::curp($curp);
        if ($i->rfc !== null && Py::len($i->rfc) > 20) {
            [$i->rfc, $i->rfcValido] = [Py::sub($i->rfc, 20), false];
        }
        if ($i->curp !== null && Py::len($i->curp) > 25) {
            [$i->curp, $i->curpValida] = [Py::sub($i->curp, 25), false];
        }
        if ($i->numero !== null && Py::len($i->numero) > 20) {
            [$i->numero, $i->numeroValido] = [Py::sub($i->numero, 20), false];
        }
        return $i;
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    public static function rrhhRow(array $d): array
    {
        $ident = self::identity($d['NUM_EMPLEADO'] ?? null, $d['NOMBRE'] ?? null, $d['RFC'] ?? null, $d['CURP'] ?? null);
        [$p, $ps] = self::money($d['PERCEPCIONES'] ?? null);
        [$q, $qs] = self::money($d['DEDUCCIONES'] ?? null);
        [$n, $ns] = self::money($d['NETO'] ?? null);
        $snap = [
            'issemym' => self::txt($d['ISSEMYM'] ?? null, 40), 'folio' => self::txt($d['FOLIO'] ?? null, 40), 'plaza' => self::txt($d['PLAZA'] ?? null, 40),
            'departamento' => self::txt($d['DEPTO'] ?? null, 120), 'area' => self::txt($d['AREA'] ?? null, 200), 'puesto' => self::txt($d['PUESTO'] ?? null, 120),
            'cargo' => self::txt($d['CARGO'] ?? null, 200), 'fecha_ingreso' => self::dateIso($d['FECHA_INGRESO'] ?? null),
            'fecha_baja' => self::dateIso($d['FECHA_BAJA'] ?? null), 'fecha_ingreso_original' => self::txt($d['FECHA_INGRESO'] ?? null, 40),
            'fecha_baja_original' => self::txt($d['FECHA_BAJA'] ?? null, 40), 'fuente_financiamiento' => self::txt($d['FUENTE'] ?? null, 120),
            'clave_programatica' => self::txt($d['CLAVE_PROGRAMATICA'] ?? null, 120), 'banco' => self::txt($d['BANCO'] ?? null, 60),
            'tipo_pago' => self::txt($d['TIPO_PAGO'] ?? null, 60), 'nivel' => self::txt($d['NIVEL'] ?? null, 40), 'horas' => self::txt($d['HORAS'] ?? null, 40),
        ];
        $conceptos = [];
        foreach ($d as $k => $v) {
            $k = (string) $k;
            if (str_starts_with($k, 'C:') && preg_match('/^\d{3}$/D', substr($k, 2)) === 1) {
                [$imp, $st] = self::money($v);
                if ($st === 'VACIO' || ($st === 'OK' && Dec::isZero((string) $imp))) {
                    continue;
                }
                $conceptos[substr($k, 2)] = [$imp, $st];
            }
        }
        $valid = in_array($ps, ['OK', 'VACIO'], true) && in_array($qs, ['OK', 'VACIO'], true) && in_array($ns, ['OK', 'VACIO'], true);
        return ['ident' => $ident, 'snapshot' => $snap, 'percepciones' => $p, 'deducciones' => $q, 'neto' => $n, 'importes_validos' => $valid,
                'conceptos' => $conceptos];
    }

    /**
     * @param list<string> $codes
     * @param list<array<string,mixed>> $rows
     * @return array{0:array<string,string>,1:array<string,mixed>}
     */
    public static function inferConceptTypes(array $codes, array $rows): array
    {
        $n = count($codes);
        $usable = array_values(array_filter($rows, static fn ($r) => $r['percepciones'] !== null && $r['deducciones'] !== null && $r['importes_validos']));
        if ($n === 0 || $usable === []) {
            return [array_fill_keys($codes, 'DESCONOCIDO'), ['filas' => 0]];
        }
        $score = array_fill(0, $n + 1, 0);
        $signs = [];
        $tol = self::TOLERANCIA_CONCEPTOS;
        foreach ($usable as $r) {
            $prefix = ['0'];
            foreach ($codes as $c) {
                $v = $r['conceptos'][$c][0] ?? '0';
                $prefix[] = Dec::add($prefix[count($prefix) - 1], $v ?? '0');
            }
            $total = $prefix[$n];
            for ($k = 0; $k <= $n; $k++) {
                if (Dec::gt(Dec::abs(Dec::sub($prefix[$k], $r['percepciones'])), $tol)) {
                    continue;
                }
                $rest = Dec::sub($total, $prefix[$k]);
                if (Dec::le(Dec::abs(Dec::sub($rest, $r['deducciones'])), $tol)) {
                    $score[$k]++;
                    $sign = Dec::isZero($r['deducciones']) ? 'CERO' : 'POSITIVO';
                    $signs[$sign] = ($signs[$sign] ?? 0) + 1;
                } elseif (Dec::le(Dec::abs(Dec::add($rest, $r['deducciones'])), $tol)) {
                    $score[$k]++;
                    $signs['NEGATIVO'] = ($signs['NEGATIVO'] ?? 0) + 1;
                }
            }
        }
        $best = max($score);
        $ev = ['filas' => count($usable), 'filas_cuadran' => $best];
        if ($best < 0.9 * count($usable)) {
            return [array_fill_keys($codes, 'DESCONOCIDO'), [...$ev, 'resultado' => 'SIN_CORTE']];
        }
        $ks = array_keys(array_filter($score, static fn ($s) => $s === $best));
        [$lo, $hi] = [min($ks), max($ks)];
        $out = [];
        foreach ($codes as $i => $c) {
            $out[$c] = $i < $lo ? 'PERCEPCION' : ($i >= $hi ? 'DEDUCCION' : 'DESCONOCIDO');
        }
        $sign = 'CERO';
        $bestCount = null;
        foreach ($signs as $k => $cnt) {
            if ($k === 'CERO') {
                continue;
            }
            if ($bestCount === null || $cnt > $bestCount) {
                [$sign, $bestCount] = [$k, $cnt];
            }
        }
        return [$out, [...$ev, 'resultado' => 'CORTE', 'corte' => [$lo, $hi], 'signo_deducciones' => $sign]];
    }

    public static function conceptCode(mixed $v): ?string
    {
        $s = PersonIds::cleanText($v);
        if ($s !== null && preg_match('/^\d{1,3}$/Du', $s) === 1) {
            return str_pad($s, 3, '0', STR_PAD_LEFT);
        }
        return null;
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    public static function tesoreriaRow(string $banco, array $d): array
    {
        $pat = $mat = $nom = null;
        if ($banco === 'SANTANDER') {
            [$pat, $mat, $nom] = [self::txt($d['APELLIDO_PATERNO'] ?? null), self::txt($d['APELLIDO_MATERNO'] ?? null), self::txt($d['NOMBRE'] ?? null)];
            $full = implode(' ', array_filter([$pat, $mat, $nom], static fn ($x) => $x !== null && $x !== ''));
            $ident = self::identity($d['NUM_EMPLEADO'] ?? null, $full !== '' ? $full : null);
        } elseif ($banco === 'BBVA') {
            $ident = self::identity($d['NUM_EMPLEADO'] ?? null, $d['NOMBRE'] ?? null, $d['RFC'] ?? null);
        } else {
            $ident = self::identity(null, $d['BENEFICIARIO'] ?? null);
        }
        return ['ident' => $ident, 'apellido_paterno' => $pat, 'apellido_materno' => $mat, 'nombres' => $nom,
                'banco_receptor' => self::txt($d['BANCO_RECEPTOR'] ?? null, 60), 'concepto' => self::txt($d['CONCEPTO'] ?? null, 200),
                'referencia' => self::txt($d['REFERENCIA'] ?? null, 60)];
    }

    public static function bancoDeHoja(?string $nombre): string
    {
        $k = PersonIds::nameKey($nombre) ?? '';
        if (str_contains($k, 'SANTANDER')) {
            return 'SANTANDER';
        }
        if (str_contains($k, 'BBVA') || str_contains($k, 'BANCOMER')) {
            return 'BBVA';
        }
        if (str_contains($k, 'INTER')) {
            return 'INTERBANCARIA';
        }
        return 'OTRO';
    }
}
