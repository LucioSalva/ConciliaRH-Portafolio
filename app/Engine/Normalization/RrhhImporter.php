<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Normalization;

use ConciliaRH\Core\Database;
use ConciliaRH\Engine\Identity\Evidence;
use ConciliaRH\Engine\Identity\Ident;
use ConciliaRH\Engine\Identity\Matcher;
use ConciliaRH\Engine\Identity\MatchResult;
use ConciliaRH\Engine\Identity\Person;
use ConciliaRH\Engine\Identity\Records;
use ConciliaRH\Engine\Jobs\WorkerAudit;
use ConciliaRH\Engine\Support\BatchInsert;
use ConciliaRH\Engine\Support\Json;
use ConciliaRH\Engine\Support\Py;

final class RrhhImporter
{
    private const CAMPOS_MAESTRO = ['nombre_completo', 'nombre_normalizado', 'rfc', 'curp', 'issemym', 'grupo', 'area', 'departamento', 'puesto',
                                    'cargo', 'fecha_ingreso', 'fecha_baja'];

    public function __construct(private readonly ImportContext $c)
    {
    }

    /** @return array<string,mixed> */
    public function run(bool $canUpdate, string $grupo): array
    {
        $c = $this->c;
        $db = $c->db;
        $f = $c->file;
        $rows = array_map(static fn ($s) => Records::rrhhRow($s['datos']), $c->staging);
        [$codes, $desc] = $this->conceptMeta((int) $f['analisis_vigente_id']);
        $extra = [];
        foreach ($rows as $r) {
            foreach (array_keys($r['conceptos']) as $k) {
                if (!in_array((string) $k, $codes, true)) {
                    $extra[(string) $k] = true;
                }
            }
        }
        $extra = array_map('strval', array_keys($extra));
        sort($extra, SORT_STRING);
        [$tipos, $evConc] = Records::inferConceptTypes($codes, $rows);
        $catalog = self::upsertConcepts($db, [...$codes, ...$extra], $desc, $tipos,
            ['fuente' => 'CUADRE_TABULADO', 'archivo_id' => (int) $f['id'], ...$evConc]);
        $ids = Sequence::ids($db, 'registros_rrhh', count($rows));
        $pool = new Sequence($db, 'empleados');
        $fecha = (string) $f['fecha_fin'];
        $newEmps = [];
        $updates = [];
        $seenNum = [];
        $altas = 0;
        $regRows = [];
        $concRows = [];
        foreach ($c->staging as $i => $s) {
            if ($i % 500 === 0) {
                $c->job->check();
            }
            $r = $rows[$i];
            $rid = $ids[$i];
            /** @var Ident $idn */
            $idn = $r['ident'];
            [$prevD, $rech] = $c->popCarry((string) $s['hash_registro'], $s, $idn);
            $ev = Evidence::of($idn->numero, $idn->numeroValido, $idn->rfc, $idn->rfcValido, $idn->curp, $idn->curpValida, $idn->nombreKey, null, false, $rech);
            if ($idn->numeroValido) {
                $seenNum[$idn->numero] = ($seenNum[$idn->numero] ?? 0) + 1;
                if ($seenNum[$idn->numero] === 2) {
                    $c->incs[] = new IdentityIncident('NUMERO_DUPLICADO_EN_ARCHIVO', 'ALTA', ['hoja' => (int) $s['hoja_indice']], 'RRHH', $rid);
                }
            }
            $manualPrev = $prevD !== null && in_array($prevD['asociacion_estado'], ['ASOCIADO_MANUAL', 'NO_IDENTIFICADO_CONFIRMADO'], true);
            $m = Matcher::matchRrhh($ev, $c->idx);
            if ($m === null) {
                if ($canUpdate && $idn->nombreKey !== null && !$manualPrev) {
                    $empId = $pool->take();
                    $sn = $r['snapshot'];
                    $newEmps[] = [$empId, $idn->numero, $idn->nombreOriginal ?? $idn->nombreKey, $idn->nombreKey, $idn->rfcValido ? $idn->rfc : null,
                        $idn->curpValida ? $idn->curp : null, $sn['issemym'], $grupo, $sn['area'], $sn['departamento'], $sn['puesto'], $sn['cargo'],
                        $sn['fecha_ingreso'], $sn['fecha_baja'], $sn['fecha_baja'] === null, $c->importacionId, $rid, (int) $f['periodo_id'],
                        (int) $f['periodo_id'], $fecha];
                    $c->idx->add(new Person($empId, (string) $idn->numero, $idn->rfcValido ? $idn->rfc : null, $idn->rfcValido,
                        $idn->curpValida ? $idn->curp : null, $idn->curpValida, $idn->nombreKey));
                    $c->emps[$empId] = ['id' => $empId, 'ultimo_periodo_fecha' => $fecha, 'nombre_completo' => $idn->nombreOriginal, '_nuevo' => true];
                    $m = new MatchResult('ASOCIADO_AUTOMATICO', $empId, 'ALTA_RRHH', 'ALTA', ['fuertes' => [], 'alta' => true], Matcher::quality($ev));
                    $altas++;
                } else {
                    $m = Matcher::match($ev, $c->idx);
                    if ($canUpdate && $idn->nombreKey === null) {
                        $m->incidencias[] = ['ALTA_SIN_NOMBRE', 'ALTA', []];
                    }
                }
            }
            [$m, $manual] = $c->applyCarry($prevD, $m);
            if ($m->estado === 'ASOCIADO_AUTOMATICO' && $canUpdate && $m->metodo !== 'ALTA_RRHH' && empty($c->emps[$m->empleadoId]['_nuevo'])) {
                $this->collectUpdate((int) $m->empleadoId, $idn, $r, $grupo, $updates, $rid);
            }
            array_push($c->incs, ...IdentityIncident::fromMatch($m, 'RRHH', $rid));
            if (!$r['importes_validos']) {
                $c->incs[] = new IdentityIncident('IMPORTE_INVALIDO', 'ADVERTENCIA', [], 'RRHH', $rid);
            }
            $c->histAuto('RRHH', $rid, $m, $manual);
            $sn = $r['snapshot'];
            $regRows[] = [$rid, $c->importacionId, (int) $f['id'], (int) $f['analisis_vigente_id'], (int) $f['periodo_id'], (int) $f['lote_id'],
                (int) $f['perfil_id'], $grupo, (int) $s['hoja_id'], (int) $s['hoja_indice'], Py::sub((string) $s['hoja_nombre'], 255), (int) $s['fila_origen'],
                (bool) $s['oculta'], $s['hash_registro'], $idn->numeroOriginal, $idn->numero, $idn->numeroValido, $idn->nombreOriginal, $idn->nombreKey,
                $idn->rfcOriginal, $idn->rfc, $idn->rfcValido, $idn->curpOriginal, $idn->curp, $idn->curpValida, $sn['issemym'], $sn['folio'],
                $sn['plaza'], $sn['departamento'], $sn['area'], $sn['puesto'], $sn['cargo'], $sn['fecha_ingreso'], $sn['fecha_baja'],
                $sn['fecha_ingreso_original'], $sn['fecha_baja_original'], $sn['fuente_financiamiento'], $sn['clave_programatica'], $sn['banco'],
                $sn['tipo_pago'], $sn['nivel'], $sn['horas'], $r['percepciones'], $r['deducciones'], $r['neto'], $r['importes_validos'],
                $m->empleadoId, $m->estado, $m->metodo, $m->confianza, Json::obj($m->evidencia), $rech,
                $manual !== null ? $manual['asociado_por'] : null, $manual !== null ? $manual['asociado_at'] : null];
            $conc = $r['conceptos'];
            ksort($conc, SORT_STRING);
            foreach ($conc as $code => [$imp, $st]) {
                $cat = $catalog[(string) $code] ?? null;
                if ($cat !== null) {
                    $concRows[] = [$rid, (int) $cat['id'], (string) $code, $desc[(string) $code] ?? null, $imp, $st];
                }
            }
        }
        BatchInsert::run($db, 'empleados', ['id', 'numero_empleado', 'nombre_completo', 'nombre_normalizado', 'rfc', 'curp', 'issemym', 'grupo', 'area',
            'departamento', 'puesto', 'cargo', 'fecha_ingreso', 'fecha_baja', 'activo', 'origen_importacion_id', 'origen_registro_id', 'primer_periodo_id',
            'ultimo_periodo_id', 'ultimo_periodo_fecha'], $newEmps, ['fecha_ingreso' => 'date', 'fecha_baja' => 'date', 'ultimo_periodo_fecha' => 'date']);
        BatchInsert::run($db, 'registros_rrhh', ['id', 'importacion_id', 'archivo_id', 'analisis_id', 'periodo_id', 'lote_id', 'perfil_id', 'grupo', 'hoja_id',
            'hoja_indice', 'hoja_nombre', 'fila_origen', 'oculta', 'hash_registro', 'numero_original', 'numero_empleado', 'numero_valido', 'nombre_original',
            'nombre_normalizado', 'rfc_original', 'rfc', 'rfc_valido', 'curp_original', 'curp', 'curp_valida', 'issemym', 'folio', 'plaza', 'departamento',
            'area', 'puesto', 'cargo', 'fecha_ingreso', 'fecha_baja', 'fecha_ingreso_original', 'fecha_baja_original', 'fuente_financiamiento',
            'clave_programatica', 'banco', 'tipo_pago', 'nivel', 'horas', 'percepciones', 'deducciones', 'neto', 'importes_validos', 'empleado_id',
            'asociacion_estado', 'asociacion_metodo', 'asociacion_confianza', 'asociacion_evidencia', 'rechazados', 'asociado_por', 'asociado_at'],
            $regRows, ['fecha_ingreso' => 'date', 'fecha_baja' => 'date', 'asociacion_evidencia' => 'jsonb', 'rechazados' => 'bigint[]',
                       'asociado_at' => 'timestamptz']);
        BatchInsert::run($db, 'registros_rrhh_conceptos', ['registro_id', 'concepto_id', 'codigo', 'descripcion_original', 'importe', 'importe_estado'],
            $concRows);
        if ($newEmps !== []) {
            BatchInsert::run($db, 'empleado_historial', ['empleado_id', 'campo', 'valor_anterior', 'valor_nuevo', 'fuente', 'importacion_id',
                'registro_rrhh_id', 'periodo_id', 'proceso_id'],
                array_map(fn ($e) => [$e[0], 'alta', null, $e[1], 'ALTA_RRHH', $c->importacionId, $e[16], (int) $f['periodo_id'], $c->jobId()], $newEmps));
            foreach ($newEmps as $e) {
                WorkerAudit::record($db, 'empleados.alta_automatica', 'empleados', $e[0], null,
                    ['numero_empleado' => $e[1], 'grupo' => $e[7], 'fuente' => 'RRHH_TABULADO'],
                    ['importacion_id' => $c->importacionId, 'registro_rrhh_id' => $e[16], 'proceso_id' => $c->jobId()]);
            }
        }
        $cambios = $this->applyUpdates($updates);
        $this->masterDuplicates();
        return ['registros' => count($regRows), 'altas' => $altas, 'maestro_actualizados' => $cambios, 'conceptos' => count($concRows),
                'conceptos_tipo_inferido' => Json::obj($evConc), 'conceptos_codigos' => count($codes) + count($extra),
                'conceptos_tipo_discrepante' => self::discrepant($tipos, $catalog)];
    }

    /** @return array{0:list<string>,1:array<string,?string>} */
    private function conceptMeta(int $analisisId): array
    {
        $codes = [];
        $desc = [];
        foreach ($this->c->db->fetchAll(
            "SELECT c.campo_canonico, c.encabezado_original FROM archivo_columnas c JOIN archivo_hojas h ON h.id = c.hoja_id
              WHERE h.analisis_id = :a AND h.seleccionada AND c.campo_canonico LIKE 'CONCEPTO\\_%' ORDER BY c.indice", ['a' => $analisisId]) as $r) {
            $code = substr((string) $r['campo_canonico'], strlen('CONCEPTO_'));
            if (!array_key_exists($code, $desc)) {
                $codes[] = $code;
                $text = Py::strip((string) ($r['encabezado_original'] ?? ''));
                $d = Py::sub(mb_substr($text, 0, 3) === $code ? Py::strip(mb_substr($text, 3)) : $text, 200);
                $desc[$code] = $d !== '' ? $d : null;
            }
        }
        return [$codes, $desc];
    }

    /**
     * @param list<string> $codes
     * @param array<string,?string> $desc
     * @param array<string,string> $tipos
     * @param array<string,mixed> $evidencia
     * @return array<string,array<string,mixed>>
     */
    public static function upsertConcepts(Database $db, array $codes, array $desc, array $tipos, array $evidencia): array
    {
        foreach ($codes as $code) {
            $db->execute(
                "INSERT INTO conceptos_nomina (codigo, descripcion, tipo, clasificacion, evidencia) VALUES (:c, :d, :t, 'INFERIDA', CAST(:e AS jsonb))
                 ON CONFLICT (codigo) DO UPDATE SET tipo = EXCLUDED.tipo, evidencia = EXCLUDED.evidencia
                  WHERE conceptos_nomina.clasificacion = 'INFERIDA' AND conceptos_nomina.tipo = 'DESCONOCIDO' AND EXCLUDED.tipo <> 'DESCONOCIDO'",
                ['c' => $code, 'd' => $desc[$code] ?? null, 't' => $tipos[$code] ?? 'DESCONOCIDO', 'e' => Json::encode(Json::obj($evidencia))]);
        }
        $out = [];
        foreach ($db->fetchAll('SELECT id, codigo, tipo, es_pension FROM conceptos_nomina WHERE codigo = ANY(CAST(:c AS text[]))',
            ['c' => BatchInsert::arrayLiteral($codes)]) as $r) {
            $out[(string) $r['codigo']] = $r;
        }
        return $out;
    }

    /** @param array<string,string> $inferred @param array<string,array<string,mixed>> $catalog */
    public static function discrepant(array $inferred, array $catalog): int
    {
        $n = 0;
        foreach ($inferred as $code => $t) {
            if ($t !== 'DESCONOCIDO' && isset($catalog[(string) $code]) && !in_array($catalog[(string) $code]['tipo'], [$t, 'DESCONOCIDO'], true)) {
                $n++;
            }
        }
        return $n;
    }

    /** @param array<string,mixed> $r @param array<int,array<string,mixed>> $updates */
    private function collectUpdate(int $empId, Ident $idn, array $r, string $grupo, array &$updates, int $rid): void
    {
        $e = $this->c->emps[$empId];
        if ((string) $this->c->file['fecha_fin'] < (string) $e['ultimo_periodo_fecha']) {
            return;
        }
        $sn = $r['snapshot'];
        $new = ['nombre_completo' => $idn->nombreOriginal, 'nombre_normalizado' => $idn->nombreKey, 'grupo' => $grupo, 'issemym' => $sn['issemym'],
                'area' => $sn['area'], 'departamento' => $sn['departamento'], 'puesto' => $sn['puesto'], 'cargo' => $sn['cargo'],
                'fecha_ingreso' => $sn['fecha_ingreso'], 'fecha_baja' => $sn['fecha_baja']];
        if ($idn->rfcValido) {
            $new['rfc'] = $idn->rfc;
        }
        if ($idn->curpValida) {
            $new['curp'] = $idn->curp;
        }
        $updates[$empId] ??= ['_rid' => $rid];
        $updates[$empId]['_rid'] = $rid;
        foreach ($new as $k => $v) {
            if ($v === null && $k !== 'fecha_baja') {
                continue;
            }
            $updates[$empId][$k] = $v;
        }
        $updates[$empId]['_activo'] = $sn['fecha_baja'] === null;
    }

    /** @param array<int,array<string,mixed>> $updates */
    private function applyUpdates(array $updates): int
    {
        $c = $this->c;
        $db = $c->db;
        $f = $c->file;
        if ($updates === []) {
            return 0;
        }
        $ids = array_keys($updates);
        sort($ids);
        $fresh = [];
        foreach ($db->fetchAll(
            'SELECT id, nombre_completo, nombre_normalizado, rfc, curp, issemym, grupo, area, departamento, puesto, cargo,
                    fecha_ingreso::text AS fecha_ingreso, fecha_baja::text AS fecha_baja, activo, ultimo_periodo_fecha::text AS ultimo_periodo_fecha
               FROM empleados WHERE id = ANY(CAST(:ids AS bigint[])) ORDER BY id FOR UPDATE', ['ids' => BatchInsert::arrayLiteral($ids)]) as $e) {
            $fresh[(int) $e['id']] = $e;
        }
        $manual = [];
        foreach ($db->fetchAll("SELECT DISTINCT empleado_id, campo FROM empleado_historial WHERE fuente = 'MANUAL' AND empleado_id = ANY(CAST(:ids AS bigint[]))",
            ['ids' => BatchInsert::arrayLiteral($ids)]) as $r) {
            $manual[$r['empleado_id'] . '|' . $r['campo']] = true;
        }
        $changed = 0;
        $hist = [];
        foreach ($ids as $empId) {
            $upd = $updates[$empId];
            $e = $fresh[$empId];
            $rid = $upd['_rid'];
            $activo = $upd['_activo'];
            unset($upd['_rid'], $upd['_activo']);
            if ((string) $f['fecha_fin'] < (string) $e['ultimo_periodo_fecha']) {
                continue;
            }
            $sets = [];
            foreach (self::CAMPOS_MAESTRO as $k) {
                if (!array_key_exists($k, $upd)) {
                    continue;
                }
                $old = $e[$k] ?? null;
                if (self::orNone($old) === self::orNone($upd[$k])) {
                    continue;
                }
                if (isset($manual[$empId . '|' . $k])) {
                    $c->incs[] = new IdentityIncident('DATOS_RRHH_CONTRADICTORIOS', 'ADVERTENCIA', ['campo' => $k, 'empleado' => $empId], 'RRHH', $rid, $empId);
                    continue;
                }
                if (in_array($k, ['rfc', 'curp'], true) && $upd[$k] !== null && $upd[$k] !== '') {
                    $table = $k === 'rfc' ? $c->idx->byRfc : $c->idx->byCurp;
                    $others = $table[$upd[$k]] ?? [];
                    unset($others[$empId]);
                    if ($others !== []) {
                        $c->incs[] = new IdentityIncident('ACTUALIZACION_IDENTIFICADOR_DUPLICADO', 'ALTA', ['campo' => $k], 'RRHH', $rid, $empId);
                        continue;
                    }
                }
                $sets[$k] = $upd[$k];
                if ($k !== 'nombre_normalizado') {
                    $hist[] = [$empId, $k, $old === null ? null : Py::sub(Py::str($old), 300), $upd[$k] === null ? null : Py::sub(Py::str($upd[$k]), 300),
                        'RRHH_TABULADO', $c->importacionId, $rid, (int) $f['periodo_id'], $c->jobId()];
                }
            }
            if ((bool) $e['activo'] !== $activo) {
                if (isset($manual[$empId . '|activo'])) {
                    $c->incs[] = new IdentityIncident('DATOS_RRHH_CONTRADICTORIOS', 'ADVERTENCIA', ['campo' => 'activo', 'empleado' => $empId], 'RRHH', $rid, $empId);
                } else {
                    $sets['activo'] = $activo;
                    $hist[] = [$empId, 'activo', $e['activo'] ? 'true' : 'false', $activo ? 'true' : 'false', 'RRHH_TABULADO', $c->importacionId, $rid,
                        (int) $f['periodo_id'], $c->jobId()];
                }
            }
            $sets['ultimo_periodo_id'] = (int) $f['periodo_id'];
            $sets['ultimo_periodo_fecha'] = (string) $f['fecha_fin'];
            if (count($sets) > 2) {
                $changed++;
            }
            $assign = [];
            $params = ['id' => $empId];
            foreach ($sets as $k => $v) {
                $cast = in_array($k, ['fecha_ingreso', 'fecha_baja', 'ultimo_periodo_fecha'], true) ? 'CAST(:' . $k . ' AS date)' : ':' . $k;
                $assign[] = "$k = $cast";
                $params[$k] = $v;
            }
            $db->execute('UPDATE empleados SET ' . implode(', ', $assign) . ' WHERE id = :id', $params);
            if (array_intersect(['nombre_normalizado', 'rfc', 'curp'], array_keys($sets)) !== []) {
                $p = $c->idx->people[$empId];
                $rfc = array_key_exists('rfc', $sets) ? $sets['rfc'] : $p->rfc;
                $curp = array_key_exists('curp', $sets) ? $sets['curp'] : $p->curp;
                $c->idx->add(new Person($empId, $p->numero, $rfc, ($rfc !== null && $rfc !== '') && ($p->rfcValido || array_key_exists('rfc', $sets)),
                    $curp, ($curp !== null && $curp !== '') && ($p->curpValida || array_key_exists('curp', $sets)),
                    array_key_exists('nombre_normalizado', $sets) ? $sets['nombre_normalizado'] : $p->nameKey));
                if (isset($c->idx->canon[$empId])) {
                    $c->idx->setAlias($empId, $c->idx->canon[$empId]);
                }
            }
        }
        BatchInsert::run($db, 'empleado_historial', ['empleado_id', 'campo', 'valor_anterior', 'valor_nuevo', 'fuente', 'importacion_id',
            'registro_rrhh_id', 'periodo_id', 'proceso_id'], $hist);
        return $changed;
    }

    private static function orNone(mixed $v): mixed
    {
        return ($v === null || $v === '' || $v === false || $v === 0) ? null : $v;
    }

    private function masterDuplicates(): void
    {
        foreach (['rfc' => 'rfc_valido', 'curp' => 'curp_valida'] as $campo => $flag) {
            foreach ($this->c->db->fetchAll("SELECT array_agg(id ORDER BY id) AS ids FROM empleados WHERE $flag GROUP BY $campo HAVING count(*) > 1 ORDER BY min(id)") as $r) {
                $ids = \ConciliaRH\Engine\Identity\Beneficiaries::intArray($r['ids']);
                $this->c->incs[] = new IdentityIncident(strtoupper($campo) . '_DUPLICADO_EN_MAESTRO', 'ALTA', ['empleados' => $ids], null, null, $ids[0]);
            }
        }
    }
}
