<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Engine;

use ConciliaRH\Engine\Support\Json;
use ConciliaRH\Tests\Fixtures\IdentityFixtures as FX;
use ConciliaRH\Tests\Support\EngineTestCase;

final class ReproducibilityTest extends EngineTestCase
{
    use NormalizationEnvironment;

    private const VOLATILE = '/(_at|_ms|_id)$|^(id_archivo|uuid|ruta_relativa|cuenta_cifrada|metricas|huella_entradas|created|updated)$/';
    private const STABLE = ['empleado_id', 'beneficiario_id', 'registro_id', 'poliza_id', 'importacion_id', 'conciliacion_id', 'registro_rrhh_id',
                            'origen_registro_id'];
    private const TABLES = ['empleados', 'empleado_historial', 'registros_rrhh', 'registros_rrhh_conceptos', 'registros_tesoreria', 'cuentas_bancarias',
                            'asociaciones_historial', 'incidencias_identidad', 'beneficiarios_pension', 'beneficiario_trabajadores',
                            'polizas_globales', 'polizas_globales_lineas', 'polizas_egreso_lineas', 'duplicados_empleados',
                            'conciliacion_empleados', 'conciliacion_pensiones', 'conciliacion_controles', 'conciliacion_incidencias',
                            'conciliacion_fuentes'];

    protected function tearDown(): void
    {
        if (CONCILIARH_DB_AVAILABLE) {
            self::cleanNormalization();
        }
        parent::tearDown();
    }

    private static function stripVolatile(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        if (array_is_list($v)) {
            return array_map(self::stripVolatile(...), $v);
        }
        $out = [];
        foreach ($v as $k => $x) {
            $k = (string) $k;
            if (in_array($k, self::STABLE, true) || preg_match(self::VOLATILE, $k) !== 1) {
                $out[$k] = self::stripVolatile($x);
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @return array<string,list<string>> */
    private function snapshot(): array
    {
        $out = [];
        foreach (self::TABLES as $t) {
            $out[$t] = array_map(static fn (array $r): string => Json::encode(self::stripVolatile(json_decode((string) $r['j'], true, 512, JSON_THROW_ON_ERROR))),
                $this->all("SELECT to_jsonb(t)::text AS j FROM $t t ORDER BY id"));
        }
        return $out;
    }

    private function conciliate(int $lote): void
    {
        $this->web()->fetchAll("SELECT * FROM fn_solicitar_conciliacion(?, ?, 'Reproducibilidad')", [$lote, $this->user]);
        $this->drain();
    }

    private function enqueueFullNormalization(int $archivo): void
    {
        self::owner()->execute("INSERT INTO procesos (tipo, parametros, max_intentos) VALUES ('NORMALIZAR_REGISTROS', CAST(? AS jsonb), 2)",
            [Json::encode(['archivo_id' => $archivo, 'completa' => true])]);
        $this->drain();
    }

    /** @return array<string,list<string>> */
    private function execution(bool $move): array
    {
        $o = self::owner();
        self::cleanNormalization();
        self::truncateQueue($o);
        $this->startEnvironment();
        $ps = array_map(static fn (int $i): array => FX::person($i), range(1, 40));
        $lote = $this->lote(1, 1);
        $s1 = $this->add(self::sant(array_slice($ps, 0, 25)), 'xlsx', 'TESORERIA_DISPERSION', $lote);
        $s2 = $this->add(self::sant(array_slice($ps, 25), [$ps[39]['num'] => sprintf('600%08d', (int) $ps[38]['num'])]), 'xlsx', 'TESORERIA_DISPERSION', $lote);
        $pensionAccounts = [];
        foreach (array_slice($ps, 0, 6) as $p) {
            $pensionAccounts[$p['num']] = sprintf('700%08d', (int) $p['num']);
        }
        $this->add(self::sant(array_slice($ps, 0, 6), $pensionAccounts, true), 'xlsx', 'TESORERIA_DISPERSION', $lote);
        $inter = [];
        foreach (range(30, 34) as $i) {
            $inter[] = [sprintf('0021800000000%05d', $i), $ps[$i]['nombre'], '5000.00'];
        }
        $this->add(FX::interbancaria($inter), 'xls', 'TESORERIA_DISPERSION', $lote);
        $g = $this->add(FX::polizaGlobal([['1', 'SUELDO BASE', 150000.0], ['3', 'COMPENSACION', 50000.0], ['5', 'DESPENSA', 1000.0]],
            [['70', 'ISR', 800.0], ['44', 'PENSION', 200.0], ['71', 'ISSEMYM', 300.0]]), 'xlsx', 'RRHH_POLIZA_GLOBAL', $lote);
        if ($move) {
            foreach ([1, 0] as $resto) {
                $o->execute('UPDATE registros_tesoreria SET fila_origen = fila_origen + 100000 WHERE id % 3 = ?', [$resto]);
                $o->execute('UPDATE registros_tesoreria SET fila_origen = fila_origen - 100000 WHERE fila_origen > 100000');
            }
            $o->execute('UPDATE staging_registros SET importe_estado = importe_estado WHERE id % 2 = 0');
            $o->execute('UPDATE importaciones_normalizadas SET estado_motivo = estado_motivo WHERE archivo_id = ?', [$s1]);
        }
        $this->enqueueFullNormalization($g);
        $db = '';
        if ($move) {
            $db = (string) $o->fetchValue('SELECT current_database()');
            $o->execute("ALTER DATABASE \"$db\" SET enable_indexscan = off");
            $o->execute("ALTER DATABASE \"$db\" SET enable_bitmapscan = off");
        }
        try {
            $this->add(self::tab($ps), 'xlsx', 'RRHH_TABULADO', $lote);
        } finally {
            if ($move) {
                $o->execute("ALTER DATABASE \"$db\" RESET enable_indexscan");
                $o->execute("ALTER DATABASE \"$db\" RESET enable_bitmapscan");
            }
        }
        self::assertGreaterThanOrEqual(40, $this->intValue("SELECT count(*) FROM asociaciones_historial WHERE accion = 'REASOCIACION'"));
        self::assertGreaterThan($s1, $s2);
        $shared = [];
        foreach ($this->all('SELECT numero_empleado, asociacion_estado FROM registros_tesoreria WHERE archivo_id = ? AND numero_empleado IN (?, ?)',
            $s2, $ps[38]['num'], $ps[39]['num']) as $r) {
            $shared[$r['numero_empleado']] = $r['asociacion_estado'];
        }
        ksort($shared);
        self::assertSame([$ps[38]['num'] => 'ASOCIADO_AUTOMATICO', $ps[39]['num'] => 'CONFLICTO'], $shared, Json::encode($shared));
        $this->conciliate($lote);
        $snap = $this->snapshot();
        self::cleanNormalization();
        return $snap;
    }

    /** @param list<string> $rows @return list<string> */
    private static function sortedWithoutId(array $rows): array
    {
        $out = array_map(static function (string $f): string {
            $row = json_decode($f, true, 512, JSON_THROW_ON_ERROR);
            unset($row['id']);
            return Json::encode($row);
        }, $rows);
        sort($out, SORT_STRING);
        return $out;
    }

    public function testSameInputTwiceSameRowsAndSameOrder(): void
    {
        $a = $this->execution(false);
        $b = $this->execution(true);
        foreach (self::TABLES as $t) {
            self::assertCount(count($a[$t]), $b[$t], $t);
            self::assertSame(self::sortedWithoutId($a[$t]), self::sortedWithoutId($b[$t]), "$t: el CONJUNTO de filas (valores) difiere entre corridas");
            $distintas = array_keys(array_filter(array_map(static fn (string $x, string $y): bool => $x !== $y, $a[$t], $b[$t])));
            self::assertSame([], $distintas, "$t: mismas filas en OTRO ORDEN de inserción (" . count($distintas) . ' posiciones)');
        }
        self::assertGreaterThanOrEqual(91, count($a['asociaciones_historial']));
        self::assertCount(45, $a['cuentas_bancarias']);
        self::assertCount(12, $a['polizas_globales_lineas']);
        self::assertCount(40, $a['conciliacion_empleados']);
    }

    public function testThirdRunIdenticalToTheFirst(): void
    {
        $a = $this->execution(false);
        $b = $this->execution(false);
        self::assertSame($a, $b);
    }
}
