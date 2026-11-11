<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Normalization;

use ConciliaRH\Engine\Identity\Records;
use ConciliaRH\Engine\Support\BatchInsert;
use ConciliaRH\Engine\Support\Dec;
use ConciliaRH\Engine\Support\Py;

final class PolicyImporter
{
    public function __construct(private readonly ImportContext $c)
    {
    }

    /** @return array<string,mixed> */
    public function global(string $grupo): array
    {
        $c = $this->c;
        $db = $c->db;
        $f = $c->file;
        $bySheet = [];
        foreach ($c->staging as $s) {
            $bySheet[(int) $s['hoja_indice']][] = $s;
        }
        $codesTipo = [];
        $desc = [];
        foreach ($c->staging as $s) {
            $d = $s['datos'];
            $code = Records::conceptCode($d['CODIGO'] ?? null);
            if ($code !== null) {
                $codesTipo[$code] ??= ($d['BLOQUE'] ?? null) === 'PERCEPCIONES' ? 'PERCEPCION' : 'DEDUCCION';
                if (!array_key_exists($code, $desc)) {
                    $dv = $d['DESCRIPCION'] ?? null;
                    $desc[$code] = ($dv === null || $dv === '' || $dv === false) ? null : Py::sub(Py::str($dv), 200);
                }
            }
        }
        $codes = array_map('strval', array_keys($codesTipo));
        sort($codes, SORT_STRING);
        $catalog = $codesTipo !== [] ? RrhhImporter::upsertConcepts($db, $codes, $desc, $codesTipo, ['fuente' => 'POLIZA_GLOBAL', 'archivo_id' => (int) $f['id']]) : [];
        ksort($bySheet);
        $lineas = 0;
        foreach ($bySheet as $hoja => $items) {
            $tot = ['PERCEPCIONES' => '0', 'DEDUCCIONES' => '0'];
            $rows = [];
            foreach ($items as $s) {
                $d = $s['datos'];
                $raw = $d['IMPORTE'] ?? null;
                [$imp, $st] = Records::money($raw === '!INVALIDO' ? null : $raw);
                if ($raw === '!INVALIDO') {
                    $st = 'INVALIDO';
                }
                $bloque = isset($tot[$d['BLOQUE'] ?? '']) ? $d['BLOQUE'] : null;
                if ($bloque === null) {
                    continue;
                }
                if ($st === 'OK') {
                    $tot[$bloque] = Dec::add($tot[$bloque], (string) $imp);
                }
                $code = Records::conceptCode($d['CODIGO'] ?? null) ?? (($d['CODIGO'] ?? null) !== null ? Py::sub(Py::str($d['CODIGO']), 20) : null);
                $cat = $catalog[(string) ($code ?? '')] ?? null;
                $dv = $d['DESCRIPCION'] ?? null;
                $rows[] = [(int) $s['fila_origen'], $bloque, $code, $cat !== null ? (int) $cat['id'] : null,
                    ($dv === null || $dv === '' || $dv === false) ? null : Py::str($dv), $imp, $st];
            }
            $pid = (int) $db->fetchValue(
                'INSERT INTO polizas_globales (importacion_id, archivo_id, periodo_id, lote_id, grupo, hoja_indice, hoja_nombre, total_percepciones,
                        total_deducciones, lineas) VALUES (:i, :a, :p, :l, :g, :h, :hn, :tp, :td, :n) RETURNING id',
                ['i' => $c->importacionId, 'a' => (int) $f['id'], 'p' => (int) $f['periodo_id'], 'l' => (int) $f['lote_id'], 'g' => $grupo, 'h' => $hoja,
                 'hn' => Py::sub((string) $items[0]['hoja_nombre'], 255), 'tp' => $tot['PERCEPCIONES'], 'td' => $tot['DEDUCCIONES'], 'n' => count($rows)]);
            BatchInsert::run($db, 'polizas_globales_lineas', ['poliza_id', 'fila_origen', 'bloque', 'codigo', 'concepto_id', 'descripcion', 'importe',
                'importe_estado'], array_map(static fn ($r) => [$pid, ...$r], $rows));
            $lineas += count($rows);
        }
        return ['registros' => $lineas, 'hojas' => count($bySheet), 'conceptos_codigos' => count($codesTipo),
                'conceptos_tipo_discrepante' => RrhhImporter::discrepant($codesTipo, $catalog)];
    }

    /** @return array<string,mixed> */
    public function egreso(): array
    {
        $c = $this->c;
        $f = $c->file;
        $rows = [];
        $bancos = [];
        foreach ($c->staging as $s) {
            $d = $s['datos'];
            [$debe, $sd] = Records::money($d['DEBE'] ?? null);
            [$haber, $sh] = Records::money($d['HABER'] ?? null);
            $st = in_array('INVALIDO', [$sd, $sh], true) ? 'INVALIDO' : (in_array('OK', [$sd, $sh], true) ? 'OK' : 'VACIO');
            $banco = Records::bancoDeHoja((string) $s['hoja_nombre']);
            $bancos[$banco] = ($bancos[$banco] ?? 0) + 1;
            $t = static fn (string $k): ?string => ($d[$k] ?? null) !== null ? Py::sub(Py::str($d[$k]), 200) : null;
            $rows[] = [$c->importacionId, (int) $f['id'], (int) $f['periodo_id'], (int) $f['lote_id'], (int) $s['hoja_indice'],
                Py::sub((string) $s['hoja_nombre'], 255), $banco, (int) $s['fila_origen'], $t('CUENTA'), $t('SCTA'), $t('SSCTA'), $t('SSSCTA'),
                $t('SSSSCTA'), $t('REF'), $t('CONCEPTO'), $debe, $haber, $st];
        }
        BatchInsert::run($c->db, 'polizas_egreso_lineas', ['importacion_id', 'archivo_id', 'periodo_id', 'lote_id', 'hoja_indice', 'hoja_nombre', 'banco',
            'fila_origen', 'cuenta', 'subcuenta', 'subsubcuenta', 'subcuenta_3', 'subcuenta_4', 'referencia', 'concepto', 'debe', 'haber',
            'importe_estado'], $rows);
        return ['registros' => count($rows), 'lineas_por_banco' => (object) $bancos];
    }
}
