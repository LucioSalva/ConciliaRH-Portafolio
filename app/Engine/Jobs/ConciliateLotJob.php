<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Jobs;

use ConciliaRH\Engine\Support\DbError;
use ConciliaRH\Core\Database;
use ConciliaRH\Engine\Conciliation\Engine;
use ConciliaRH\Engine\Conciliation\InputFingerprint;
use ConciliaRH\Engine\Conciliation\PolicyLevels;
use ConciliaRH\Engine\Conciliation\Result;
use ConciliaRH\Engine\Identity\Beneficiaries;
use ConciliaRH\Engine\Support\BatchInsert;
use ConciliaRH\Engine\Support\Dec;
use ConciliaRH\Engine\Support\Json;
use ConciliaRH\Engine\Support\Py;
use ConciliaRH\Engine\Worker\JobContext;
use ConciliaRH\Engine\Worker\JobFailure;
use ConciliaRH\Engine\Worker\JobHandler;
use PDOException;

final class ConciliateLotJob implements JobHandler
{
    private const EN_PROCESO = ['RECIBIDO', 'VERIFICANDO', 'VERIFICADO', 'ANALIZANDO'];

    public function run(JobContext $ctx): array
    {
        try {
            return $this->execute($ctx);
        } catch (PDOException $e) {
            $state = (string) ($e->errorInfo[0] ?? '');
            if (str_starts_with($state, '23') || str_starts_with($state, '22') || str_starts_with($state, '42')) {
                throw JobFailure::permanent('Los resultados contradicen una regla de integridad; la conciliación no se guardó.', 'CONCILIACION_INCONSISTENTE',
                    DbError::detail($e));
            }
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    private function execute(JobContext $ctx): array
    {
        $cid = (int) $ctx->params()['conciliacion_id'];
        $t0 = hrtime(true);
        $ctx->progress(5, 'CONCILIANDO', 'Leyendo entradas del lote');
        return $ctx->db->transaction(function (Database $db) use ($ctx, $cid, $t0): array {
            $db->execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $c = $db->fetchOne('SELECT * FROM conciliaciones WHERE id = :id FOR UPDATE', ['id' => $cid]);
            if ($c === null) {
                throw JobFailure::permanent('La conciliación no existe.', 'CONCILIACION_INEXISTENTE');
            }
            if ($c['estado'] !== 'PENDIENTE' || (int) $c['proceso_id'] !== $ctx->job->id) {
                return ['conciliacion_id' => $cid, 'omitido' => "La conciliación está en estado {$c['estado']}."];
            }
            $lote = (int) $c['lote_id'];
            $ocupados = (int) $db->fetchValue('SELECT count(*) FROM archivos_importados a WHERE a.lote_id = :l AND a.estado = ANY(CAST(:e AS text[]))',
                ['l' => $lote, 'e' => BatchInsert::arrayLiteral(self::EN_PROCESO)]);
            $normalizando = (int) $db->fetchValue(
                "SELECT count(*) FROM procesos p JOIN archivos_importados a ON a.id = (p.parametros->>'archivo_id')::bigint
                  WHERE p.tipo = 'NORMALIZAR_REGISTROS' AND p.estado IN ('PENDIENTE', 'EN_EJECUCION') AND a.lote_id = :l", ['l' => $lote]);
            if ($ocupados || $normalizando) {
                throw JobFailure::retriable('El lote tiene archivos en proceso; la conciliación se reintentará cuando terminen.', 'LOTE_EN_PROCESO');
            }
            [$inp, $queries] = $this->load($db, $c);
            $ctx->check();
            $huella = InputFingerprint::of($inp);
            $tLoad = hrtime(true);
            $ctx->check();
            $res = Engine::execute($inp);
            $tCalc = hrtime(true);
            $igual = $db->fetchValue(
                "SELECT id FROM conciliaciones WHERE lote_id = :l AND id <> :id AND huella_entradas = :h AND motor_version = :v
                    AND estado IN ('COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS', 'REVISION_MANUAL', 'CERRADA') ORDER BY numero DESC LIMIT 1",
                ['l' => $lote, 'id' => $cid, 'h' => $huella, 'v' => Engine::VERSION]);
            $ctx->check();
            $db->execute("UPDATE conciliaciones SET estado = 'EJECUTANDO', iniciada_at = now() WHERE id = :id", ['id' => $cid]);
            $this->persist($db, $cid, $res);
            $tSave = hrtime(true);
            $ms = static fn (int $a, int $b): int => intdiv($b - $a, 1_000_000);
            $metricas = ['lectura_ms' => $ms($t0, $tLoad), 'calculo_ms' => $ms($tLoad, $tCalc), 'guardado_ms' => $ms($tCalc, $tSave),
                         'total_ms' => $ms($t0, $tSave), 'rss_max_kb' => intdiv(memory_get_peak_usage(true), 1024), 'consultas_lectura' => $queries,
                         'registros_rrhh' => count($inp['rrhh']), 'registros_tesoreria' => count($inp['tesoreria']),
                         'registros_por_segundo' => (int) ((count($inp['rrhh']) + count($inp['tesoreria'])) / max(($tCalc - $t0) / 1e9, 0.001)),
                         'resultados' => count($res->empleados) + count($res->pensiones), 'fuentes' => count($res->fuentes)];
            $db->execute(
                'UPDATE conciliaciones SET estado = :e, motor_version = :v, huella_entradas = :h, igual_a_id = :ig, totales = CAST(:t AS jsonb),
                        resumen = CAST(:r AS jsonb), metricas = CAST(:m AS jsonb), terminada_at = now() WHERE id = :id',
                ['e' => $res->estado, 'v' => $res->motorVersion, 'h' => $huella, 'ig' => $igual !== null ? (int) $igual : null,
                 't' => Json::encode(Json::obj($res->totales)), 'r' => Json::encode(Json::obj($res->resumen)), 'm' => Json::encode(Json::obj($metricas)), 'id' => $cid]);
            $resumen = $res->resumen;
            unset($resumen['controles']);
            WorkerAudit::record($db, 'conciliaciones.ejecutar', 'conciliaciones', $cid, null, ['estado' => $res->estado, 'motor_version' => $res->motorVersion],
                ['proceso_id' => $ctx->job->id, 'lote_id' => $lote, 'numero' => (int) $c['numero'], 'huella' => substr($huella, 0, 16) . '…',
                 'igual_a' => $igual !== null ? (int) $igual : null, 'resumen' => Json::obj($resumen), 'incidencias' => count($res->incidencias)]);
            $ctx->progress(100, 'FINALIZANDO', 'Conciliación terminada');
            return ['conciliacion_id' => $cid, 'estado' => $res->estado, 'huella' => $huella, 'metricas' => Json::obj($metricas),
                    '_estado' => $res->estado === 'COMPLETADA' ? 'COMPLETADO' : 'COMPLETADO_CON_INCIDENCIAS'];
        });
    }

    /**
     * @param array<string,mixed> $c
     * @return array{0:array<string,mixed>,1:int}
     */
    private function load(Database $db, array $c): array
    {
        $q = 0;
        $fetch = static function (string $sql, array $params = []) use ($db, &$q): array {
            $q++;
            return $db->fetchAll($sql, $params);
        };
        $lote = (int) $c['lote_id'];
        $cb = $fetch("SELECT valor FROM configuracion WHERE clave = 'conciliacion.cuenta_bancos'");
        $cuentaBancos = (string) ($cb[0]['valor'] ?? '1112');
        $archivosRows = $fetch(
            "SELECT a.id, a.estado, a.clasificacion_duplicidad, a.archivo_relacionado_id, a.poblacion,
                    (r.lote_id IS NOT DISTINCT FROM a.lote_id AND a.archivo_relacionado_id IS NOT NULL) AS rel_mismo_lote,
                    n.id AS importacion_id, n.tipo_registro, n.grupo, n.metricas->>'banco' AS banco, n.metricas->>'categoria' AS categoria,
                    n.metricas->'conceptos_tipo_inferido'->>'signo_deducciones' AS signo,
                    coalesce(an.advertencias @> '[{\"codigo\": \"VISTA_DERIVADA\"}]'::jsonb, false) AS derivado,
                    (SELECT sum(ca.valor_declarado) FROM controles_archivo ca JOIN archivo_hojas h ON h.id = ca.hoja_id
                      WHERE ca.analisis_id = n.analisis_id AND ca.codigo = 'TOTAL_IMPORTE'
                        AND h.indice IN (SELECT DISTINCT t.hoja_indice FROM registros_tesoreria t WHERE t.importacion_id = n.id)) AS total_declarado
               FROM archivos_importados a
               LEFT JOIN archivos_importados r ON r.id = a.archivo_relacionado_id
               LEFT JOIN importaciones_normalizadas n ON n.archivo_id = a.id AND n.estado = 'VIGENTE'
               LEFT JOIN analisis_archivo an ON an.id = n.analisis_id
              WHERE a.lote_id = :l ORDER BY a.id", ['l' => $lote]);
        $archivos = [];
        $signo = [];
        $impIds = [];
        foreach ($archivosRows as $a) {
            $archivos[] = ['archivo_id' => (int) $a['id'], 'importacion_id' => $a['importacion_id'] !== null ? (int) $a['importacion_id'] : null,
                           'tipo_registro' => $a['tipo_registro'], 'estado_archivo' => $a['estado'], 'clasificacion' => $a['clasificacion_duplicidad'],
                           'relacionado_id' => $a['archivo_relacionado_id'] !== null ? (int) $a['archivo_relacionado_id'] : null,
                           'relacionado_mismo_lote' => (bool) $a['rel_mismo_lote'], 'derivado' => (bool) $a['derivado'], 'grupo' => $a['grupo'],
                           'banco' => $a['banco'], 'categoria' => $a['categoria'], 'total_declarado' => $a['total_declarado'],
                           'poblacion' => $a['poblacion']];
            $signo[(int) $a['id']] = $a['signo'];
            if ($a['importacion_id'] !== null && $a['estado'] !== 'ANULADO') {
                $impIds[(int) $a['importacion_id']] = true;
            }
        }
        $imps = BatchInsert::arrayLiteral(array_keys($impIds));
        $rrhhRows = $fetch(
            'SELECT r.id, r.archivo_id, r.empleado_id, r.asociacion_estado, r.grupo, r.numero_empleado, r.nombre_normalizado, r.area, r.percepciones,
                    r.deducciones, r.neto, r.importes_validos, r.fecha_baja::text AS fecha_baja, r.hoja_nombre, r.fila_origen
               FROM registros_rrhh r WHERE r.importacion_id = ANY(CAST(:i AS bigint[])) ORDER BY r.id', ['i' => $imps]);
        $pens = [];
        foreach ($fetch(
            'SELECT c.registro_id, c.codigo, c.importe, r.archivo_id FROM registros_rrhh_conceptos c
               JOIN registros_rrhh r ON r.id = c.registro_id JOIN conceptos_nomina k ON k.id = c.concepto_id
              WHERE r.importacion_id = ANY(CAST(:i AS bigint[])) AND k.es_pension AND c.importe_estado = \'OK\' ORDER BY c.id', ['i' => $imps]) as $p) {
            $s = $signo[(int) $p['archivo_id']] ?? null;
            $imp = (string) $p['importe'];
            $retenido = $s === 'NEGATIVO' ? Dec::neg($imp) : ($s === 'POSITIVO' ? $imp : Dec::abs($imp));
            $pens[(int) $p['registro_id']][] = ['codigo' => (string) $p['codigo'], 'importe_original' => $imp, 'retenido' => $retenido];
        }
        $canon = [];
        foreach ($fetch('SELECT id, fusionado_en_id FROM empleados WHERE fusionado_en_id IS NOT NULL') as $e) {
            $canon[(int) $e['id']] = (int) $e['fusionado_en_id'];
        }
        $cn = static fn (?int $e): ?int => $e === null ? null : ($canon[$e] ?? $e);
        $rrhh = [];
        foreach ($rrhhRows as $r) {
            $rrhh[] = ['id' => (int) $r['id'], 'archivo_id' => (int) $r['archivo_id'], 'empleado_id' => $cn($r['empleado_id'] !== null ? (int) $r['empleado_id'] : null),
                       'asociacion' => $r['asociacion_estado'], 'grupo' => $r['grupo'], 'numero' => $r['numero_empleado'], 'nombre_key' => $r['nombre_normalizado'],
                       'area' => $r['area'], 'percepciones' => $r['percepciones'], 'deducciones' => $r['deducciones'], 'neto' => $r['neto'],
                       'importes_validos' => (bool) $r['importes_validos'], 'fecha_baja' => $r['fecha_baja'], 'hoja' => $r['hoja_nombre'],
                       'fila' => (int) $r['fila_origen'], 'pensiones' => $pens[(int) $r['id']] ?? []];
        }
        $tes = [];
        foreach ($fetch(
            "SELECT t.id, t.archivo_id, t.empleado_id, t.asociacion_estado, t.asociacion_evidencia->'candidatos' AS candidatos, t.categoria, t.banco, t.importe,
                    t.importe_estado, t.cuenta_hmac, t.referencia, t.nombre_normalizado, t.hoja_nombre, t.fila_origen, (t.asociacion_metodo = 'MANUAL') AS manual
               FROM registros_tesoreria t WHERE t.importacion_id = ANY(CAST(:i AS bigint[])) ORDER BY t.id", ['i' => $imps]) as $t) {
            $cands = [];
            if (in_array($t['asociacion_estado'], ['SUGERENCIA', 'AMBIGUO', 'CONFLICTO'], true)) {
                foreach ((array) (Json::decode($t['candidatos']) ?? []) as $x) {
                    if ((is_int($x) || is_string($x)) && preg_match('/^\d+$/D', (string) $x) === 1) {
                        $cands[(int) $cn((int) $x)] = true;
                    }
                }
                $cands = array_keys($cands);
                sort($cands);
            }
            $tes[] = ['id' => (int) $t['id'], 'archivo_id' => (int) $t['archivo_id'], 'empleado_id' => $cn($t['empleado_id'] !== null ? (int) $t['empleado_id'] : null),
                      'asociacion' => $t['asociacion_estado'], 'candidatos' => $cands, 'categoria' => $t['categoria'], 'banco' => $t['banco'],
                      'importe' => $t['importe'], 'importe_estado' => $t['importe_estado'], 'cuenta_hmac' => $t['cuenta_hmac'], 'referencia' => $t['referencia'],
                      'nombre_key' => $t['nombre_normalizado'], 'hoja' => $t['hoja_nombre'], 'fila' => (int) $t['fila_origen'], 'manual' => (bool) $t['manual']];
        }
        $globales = [];
        foreach ($fetch(
            "SELECT p.archivo_id, p.grupo, p.total_percepciones, p.total_deducciones,
                    (SELECT ca.valor_declarado FROM controles_archivo ca JOIN archivo_hojas h ON h.id = ca.hoja_id
                      WHERE ca.analisis_id = n.analisis_id AND ca.codigo = 'NETO' AND h.indice = p.hoja_indice ORDER BY ca.id LIMIT 1) AS neto,
                    (SELECT ca.valor_declarado FROM controles_archivo ca JOIN archivo_hojas h ON h.id = ca.hoja_id
                      WHERE ca.analisis_id = n.analisis_id AND ca.codigo = 'CANTIDAD_EMPLEADOS_DECLARADOS' AND h.indice = p.hoja_indice
                      ORDER BY ca.id LIMIT 1) AS empleados
               FROM polizas_globales p JOIN importaciones_normalizadas n ON n.id = p.importacion_id
              WHERE p.importacion_id = ANY(CAST(:i AS bigint[])) ORDER BY p.archivo_id, p.hoja_indice", ['i' => $imps]) as $g) {
            $globales[] = ['archivo_id' => (int) $g['archivo_id'], 'grupo' => $g['grupo'], 'percepciones' => $g['total_percepciones'] ?? '0',
                           'deducciones' => $g['total_deducciones'] ?? '0', 'neto_declarado' => $g['neto'],
                           'empleados_declarados' => $g['empleados'] !== null ? Dec::toInt((string) $g['empleados']) : null];
        }
        $egreso = [];
        foreach ($fetch(
            "SELECT l.archivo_id, l.hoja_indice, min(l.hoja_nombre) AS hoja_nombre,
                    sum(l.haber) FILTER (WHERE l.cuenta = :c AND l.importe_estado <> 'INVALIDO') AS monto_bancos
               FROM polizas_egreso_lineas l WHERE l.importacion_id = ANY(CAST(:i AS bigint[])) GROUP BY l.archivo_id, l.hoja_indice
              ORDER BY l.archivo_id, l.hoja_indice", ['c' => $cuentaBancos, 'i' => $imps]) as $h) {
            $egreso[] = ['archivo_id' => (int) $h['archivo_id'], 'hoja_indice' => (int) $h['hoja_indice'], 'hoja_nombre' => $h['hoja_nombre'],
                         'monto_bancos' => $h['monto_bancos'], 'monto_nombre' => PolicyLevels::amountFromName($h['hoja_nombre'])];
        }
        $empIds = [];
        foreach ($rrhh as $r) {
            if ($r['empleado_id']) {
                $empIds[$r['empleado_id']] = true;
            }
        }
        foreach ($tes as $t) {
            if ($t['empleado_id']) {
                $empIds[$t['empleado_id']] = true;
            }
            foreach ($t['candidatos'] as $x) {
                $empIds[$x] = true;
            }
        }
        $empleados = [];
        foreach ($fetch('SELECT id, numero_empleado, grupo, nombre_normalizado FROM empleados WHERE id = ANY(CAST(:i AS bigint[]))',
            ['i' => BatchInsert::arrayLiteral(array_keys($empIds))]) as $e) {
            $empleados[(int) $e['id']] = ['id' => (int) $e['id'], 'numero' => $e['numero_empleado'], 'grupo' => $e['grupo'], 'nombre_key' => $e['nombre_normalizado']];
        }
        $rel = [];
        foreach ($fetch("SELECT archivo_egreso_id, hoja_indice, archivo_dispersion_id FROM relaciones_poliza_dispersion WHERE lote_id = :l AND estado = 'ACTIVA'",
            ['l' => $lote]) as $r) {
            $rel[$r['archivo_egreso_id'] . ':' . $r['hoja_indice']] = (int) $r['archivo_dispersion_id'];
        }
        return [['lote_id' => $lote, 'tolerancia' => (string) $c['tolerancia'], 'archivos' => $archivos, 'rrhh' => $rrhh, 'tesoreria' => $tes,
                 'globales' => $globales, 'egreso' => $egreso, 'empleados' => $empleados, 'relaciones_manuales' => $rel], $q];
    }

    private function persist(Database $db, int $cid, Result $r): void
    {
        BatchInsert::run($db, 'conciliacion_archivos', ['conciliacion_id', 'archivo_id', 'importacion_id', 'tipo_registro', 'rol', 'grupo', 'banco', 'categoria',
            'registros', 'total_detalle', 'total_declarado', 'motivo'],
            array_map(static fn ($a) => [$cid, $a->archivoId, $a->importacionId, $a->tipoRegistro, $a->rol, $a->grupo, $a->banco, $a->categoria, $a->registros,
                $a->totalDetalle, $a->totalDeclarado, $a->motivo], $r->archivos));
        BatchInsert::run($db, 'conciliacion_controles', ['conciliacion_id', 'nivel', 'codigo', 'grupo', 'banco', 'categoria', 'referencia', 'descripcion',
            'valor_a', 'etiqueta_a', 'valor_b', 'etiqueta_b', 'valor_c', 'etiqueta_c', 'diferencia', 'estado', 'fuentes', 'detalle'],
            array_map(static function ($k) use ($cid) {
                $f = array_values(array_unique($k->fuentes));
                sort($f);
                return [$cid, $k->nivel, $k->codigo, $k->grupo, $k->banco, $k->categoria, $k->referencia, Py::sub($k->descripcion, 300), $k->valorA, $k->etiquetaA,
                    $k->valorB, $k->etiquetaB, $k->valorC, $k->etiquetaC, $k->diferencia, $k->estado, $f, Json::obj($k->detalle)];
            }, $r->controles), ['fuentes' => 'bigint[]', 'detalle' => 'jsonb']);
        BatchInsert::run($db, 'conciliacion_empleados', ['conciliacion_id', 'empleado_id', 'grupo', 'numero_empleado', 'area', 'registro_rrhh_id', 'percepciones',
            'deducciones', 'neto_esperado', 'pagado_santander', 'pagado_bbva', 'pagado_interbancaria', 'pagado_otros', 'pagado_total', 'diferencia', 'pagos',
            'estado', 'alerta', 'posible_pendiente', 'con_pension', 'detalle'],
            array_map(static fn ($x) => [$cid, $x->empleadoId, $x->grupo, $x->numero, $x->area, $x->registroRrhhId, $x->percepciones, $x->deducciones,
                $x->netoEsperado, $x->pagado['SANTANDER'], $x->pagado['BBVA'], $x->pagado['INTERBANCARIA'], $x->pagado['OTROS'], $x->pagadoTotal(),
                $x->diferencia(), $x->pagos, $x->estado, $x->alerta, $x->posiblePendiente, $x->conPension, Json::obj($x->detalle)], $r->empleados),
            ['detalle' => 'jsonb']);
        BatchInsert::run($db, 'conciliacion_pensiones', ['conciliacion_id', 'empleado_id', 'grupo', 'numero_empleado', 'retenido', 'dispersado', 'diferencia',
            'dispersiones', 'estado', 'alerta', 'detalle'],
            array_map(static fn ($p) => [$cid, $p->empleadoId, $p->grupo, $p->numero, $p->retenido, $p->dispersado, $p->diferencia(), $p->dispersiones,
                $p->estado, $p->alerta, Json::obj($p->detalle)], $r->pensiones), ['detalle' => 'jsonb']);
        BatchInsert::run($db, 'conciliacion_fuentes', ['conciliacion_id', 'empleado_id', 'tipo', 'registro_id', 'archivo_id', 'hoja_nombre', 'fila_origen',
            'banco', 'codigo_concepto', 'importe_original', 'importe', 'asociacion_estado'],
            array_map(static function ($f) use ($cid) {
                $hoja = Py::sub((string) ($f->hoja ?? ''), 255);
                return [$cid, $f->empleadoId, $f->tipo, $f->registroId, $f->archivoId, $hoja !== '' ? $hoja : null, $f->fila, $f->banco, $f->codigoConcepto,
                    $f->importeOriginal, $f->importe, $f->asociacion];
            }, $r->fuentes));
        BatchInsert::run($db, 'conciliacion_incidencias', ['conciliacion_id', 'clave', 'tipo', 'severidad', 'nivel', 'empleado_id', 'grupo', 'banco', 'importe',
            'mensaje', 'detalle'],
            array_map(static fn ($i) => [$cid, Py::sub($i->clave, 200), $i->tipo, $i->severidad, $i->nivel, $i->empleadoId, $i->grupo, $i->banco, $i->importe,
                Py::sub($i->mensaje, 500), Json::obj($i->detalle)], $r->incidencias), ['detalle' => 'jsonb']);
    }
}
