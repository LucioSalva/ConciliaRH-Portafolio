<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Jobs;

use ConciliaRH\Engine\Support\DbError;
use ConciliaRH\Core\Database;
use ConciliaRH\Engine\Analysis\Advertencia;
use ConciliaRH\Engine\Analysis\AnalysisResult;
use ConciliaRH\Engine\Analysis\Analyzer;
use ConciliaRH\Engine\Analysis\Fingerprint;
use ConciliaRH\Engine\Files\Limits;
use ConciliaRH\Engine\Files\StoredOriginal;
use ConciliaRH\Engine\Identity\AccountCrypto;
use ConciliaRH\Engine\Support\BatchInsert;
use ConciliaRH\Engine\Support\Json;
use ConciliaRH\Engine\Support\Py;
use ConciliaRH\Engine\Workbook\AnalysisError;
use ConciliaRH\Engine\Worker\JobContext;
use ConciliaRH\Engine\Worker\JobFailure;
use ConciliaRH\Engine\Worker\JobHandler;
use ConciliaRH\Engine\Worker\LeaseLost;
use PDOException;
use RuntimeException;
use Throwable;

final class AnalyzeFileJob implements JobHandler
{
    private const STARTABLE = ['VERIFICADO', 'ANALIZANDO', 'ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS', 'REVISION_MANUAL'];

    public function run(JobContext $ctx): array
    {
        $id = (int) $ctx->params()['archivo_id'];
        $db = $ctx->db;
        $row = $db->fetchOne(
            'SELECT a.id, a.estado, a.ruta_relativa, a.extension, a.tiene_macros, a.proceso_id, a.hoja_manual_indice, a.hoja_manual_nombre,
                    pm.codigo AS perfil_manual, a.lote_id, a.sha256, a.tamano_bytes, a.clasificacion_duplicidad, a.archivo_relacionado_id,
                    a.huella_contenido, a.huella_version, t.codigo AS tipo_documento
               FROM archivos_importados a
               JOIN tipos_documento t ON t.id = a.tipo_documento_id
               LEFT JOIN perfiles_importacion pm ON pm.id = a.perfil_manual_id
              WHERE a.id = :id', ['id' => $id]);
        if ($row === null) {
            throw JobFailure::permanent('El archivo a analizar no existe.', 'ARCHIVO_INEXISTENTE');
        }
        if ($row['estado'] === 'ANULADO') {
            return ['archivo_id' => $id, 'omitido' => 'El archivo fue anulado.'];
        }
        if ((int) $row['proceso_id'] !== $ctx->job->id) {
            return ['archivo_id' => $id, 'omitido' => 'Un análisis más reciente sustituyó a este trabajo.'];
        }
        if ($db->fetchOne('SELECT 1 FROM analisis_archivo WHERE archivo_id = :a AND proceso_id = :p', ['a' => $id, 'p' => $ctx->job->id]) !== null) {
            return ['archivo_id' => $id, 'omitido' => 'Este análisis ya fue persistido.'];
        }
        if (!in_array($row['estado'], self::STARTABLE, true)) {
            return ['archivo_id' => $id, 'omitido' => "El archivo está en estado {$row['estado']}."];
        }
        $ctx->check();
        $started = $db->fetchOne(
            "UPDATE archivos_importados SET estado = 'ANALIZANDO', error_codigo = NULL, error_resumen = NULL
              WHERE id = :id AND proceso_id = :p AND estado = ANY(CAST(:s AS text[])) RETURNING id",
            ['id' => $id, 'p' => $ctx->job->id, 's' => BatchInsert::arrayLiteral(self::STARTABLE)]);
        if ($started === null) {
            return ['archivo_id' => $id, 'omitido' => 'El archivo cambió de estado antes de iniciar el análisis.'];
        }
        $ctx->progress(3, 'ANALIZANDO', 'Preparando el análisis');
        try {
            $path = StoredOriginal::resolve($ctx->settings->storagePath, (string) $row['ruta_relativa']);
        } catch (RuntimeException) {
            throw JobFailure::permanent('La ruta registrada del archivo no es válida.', 'ALMACENAMIENTO_INVALIDO');
        }
        if (!file_exists($path)) {
            throw JobFailure::retriable('El original no está disponible en el almacenamiento.', 'ORIGINAL_NO_DISPONIBLE');
        }
        if ((int) filesize($path) !== (int) $row['tamano_bytes'] || hash_file('sha256', $path) !== $row['sha256']) {
            throw JobFailure::permanent('El original almacenado no coincide con el verificado.', 'INTEGRIDAD_FALLIDA');
        }
        AccountCrypto::requireEncryption($ctx->settings);
        [$hkid, $hkey] = AccountCrypto::activeHmac($db, $ctx->settings);
        $cfg = [];
        foreach ($db->fetchAll("SELECT clave, valor FROM configuracion WHERE clave LIKE 'analisis.%' OR clave = 'conciliacion.tolerancia_monetaria'") as $c) {
            $cfg[(string) $c['clave']] = (string) $c['valor'];
        }
        $int = static fn (string $k, int $d): int => ctype_digit($cfg[$k] ?? '') ? (int) $cfg[$k] : $d;
        $tol = preg_match('/^-?\d+(\.\d+)?$/', $cfg['conciliacion.tolerancia_monetaria'] ?? '') === 1 ? $cfg['conciliacion.tolerancia_monetaria'] : '0.01';
        $limits = Limits::fromDatabase($db);
        $manualSheet = $row['hoja_manual_indice'] !== null ? [(int) $row['hoja_manual_indice'], (string) $row['hoja_manual_nombre']] : null;
        $analyzer = new Analyzer($int('analisis.max_celdas', 1_000_000), $limits->maxXml, $tol, $int('analisis.margen_ambiguedad', 10),
            static fn (float $p, string $m) => $ctx->progress($p, 'ANALIZANDO', $m), $ctx->check(...), AccountCrypto::stagingKey($hkey));
        try {
            $result = $analyzer->analyze($path, (string) $row['extension'], (bool) $row['tiene_macros'], $manualSheet,
                $row['perfil_manual'] !== null ? (string) $row['perfil_manual'] : null);
        } catch (LeaseLost $e) {
            throw $e;
        } catch (JobFailure $e) {
            if ($e->isTimeout()) {
                throw JobFailure::permanent('El análisis excedió el tiempo máximo permitido.', 'TIEMPO_EXCEDIDO');
            }
            throw $e;
        } catch (AnalysisError $e) {
            throw JobFailure::permanent($e->getMessage(), $e->errorCode);
        } catch (PDOException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw JobFailure::permanent('La estructura del libro no se pudo interpretar.', 'ESTRUCTURA_ILEGIBLE',
                $e::class . ' en ' . basename($e->getFile()) . ':' . $e->getLine());
        }
        $ctx->check();
        if ($result->tipoDocumento !== null && $result->tipoDocumento !== $row['tipo_documento']) {
            $result->advertencias[] = new Advertencia('TIPO_DOCUMENTAL_DISCREPANTE', 'ADVERTENCIA',
                'El perfil detectado corresponde a otro tipo documental que el elegido al cargar; corrija la clasificación del archivo si procede (no se cambia automáticamente).');
            if ($result->resultado === 'ANALIZADO') {
                $result->resultado = 'ANALIZADO_CON_ADVERTENCIAS';
            }
        }
        $ctx->progress(90, 'FINALIZANDO', 'Guardando el análisis');
        try {
            $summary = $this->persist($ctx, $row, $result, $hkid, $hkey);
        } catch (PDOException $e) {
            $state = (string) ($e->errorInfo[0] ?? '');
            if (str_starts_with($state, '23') || str_starts_with($state, '22')) {
                throw JobFailure::permanent('El resultado del análisis contradice una regla de integridad de la base de datos.',
                    'ANALISIS_INCONSISTENTE', DbError::detail($e));
            }
            throw $e;
        }
        $ctx->progress(100, 'FINALIZANDO', 'Análisis terminado');
        return [...$summary, '_estado' => $result->resultado === 'ANALIZADO' ? 'COMPLETADO' : 'COMPLETADO_CON_INCIDENCIAS'];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function persist(JobContext $ctx, array $row, AnalysisResult $r, string $hkid, string $key): array
    {
        $db = $ctx->db;
        $id = (int) $row['id'];
        $perfilIds = [];
        foreach ($db->fetchAll('SELECT id, codigo FROM perfiles_importacion') as $p) {
            $perfilIds[(string) $p['codigo']] = (int) $p['id'];
        }
        $perfilId = $r->perfil !== null ? ($perfilIds[$r->perfil] ?? null) : null;
        return $db->transaction(function (Database $db) use ($ctx, $row, $r, $hkid, $key, $id, $perfilIds, $perfilId): array {
            $locked = $db->fetchOne('SELECT estado, proceso_id FROM archivos_importados WHERE id = :id FOR UPDATE', ['id' => $id]);
            if ($locked === null || $locked['estado'] !== 'ANALIZANDO' || (int) $locked['proceso_id'] !== $ctx->job->id) {
                return ['archivo_id' => $id, 'omitido' => 'El estado del archivo cambió durante el análisis.'];
            }
            $prev = $db->fetchOne('SELECT id, huella_contenido, huella_version FROM analisis_archivo WHERE archivo_id = :id AND vigente', ['id' => $id]);
            $last = $r->huella !== null ? $db->fetchOne(
                'SELECT huella_contenido FROM analisis_archivo WHERE archivo_id = :id AND huella_version = :v AND huella_contenido IS NOT NULL
                  ORDER BY numero DESC LIMIT 1', ['id' => $id, 'v' => $r->huellaVersion]) : null;
            if ($last !== null && $last['huella_contenido'] !== $r->huella) {
                $r->advertencias[] = new Advertencia('HUELLA_DISTINTA_MISMA_VERSION', 'ADVERTENCIA',
                    'La huella v1 cambió respecto del análisis anterior del mismo archivo; revise la relación con otros archivos.');
                if ($r->resultado === 'ANALIZADO') {
                    $r->resultado = 'ANALIZADO_CON_ADVERTENCIAS';
                }
            }
            if ($prev !== null) {
                $db->execute('UPDATE analisis_archivo SET vigente = false WHERE id = :id', ['id' => (int) $prev['id']]);
                foreach (['staging_registros', 'controles_archivo', 'archivo_hojas'] as $t) {
                    $db->execute("DELETE FROM $t WHERE analisis_id = :id", ['id' => (int) $prev['id']]);
                }
            }
            $numero = (int) $db->fetchValue('SELECT coalesce(max(numero), 0) + 1 FROM analisis_archivo WHERE archivo_id = :id', ['id' => $id]);
            $manual = ($row['hoja_manual_indice'] !== null || ($row['perfil_manual'] ?? null) !== null)
                ? ['hoja_indice' => $row['hoja_manual_indice'] !== null ? (int) $row['hoja_manual_indice'] : null,
                   'hoja_nombre' => $row['hoja_manual_nombre'], 'perfil' => $row['perfil_manual']] : null;
            $analisisId = (int) $db->fetchValue(
                'INSERT INTO analisis_archivo (archivo_id, numero, proceso_id, vigente, resultado, motor_version, reglas_version, perfil_id,
                        perfil_confianza, perfil_origen, perfil_puntaje, hoja_indice, hoja_origen, total_hojas, filas_detalle, huella_contenido,
                        huella_version, advertencias, evidencia, metricas, seleccion_manual, hash_key_id)
                 VALUES (:a, :n, :p, true, :res, :mv, :rv, :pid, :conf, :po, :pun, :hi, :ho, :th, :fd, :hc, :hv, CAST(:adv AS jsonb),
                         CAST(:ev AS jsonb), CAST(:met AS jsonb), CAST(:man AS jsonb), :hk) RETURNING id',
                ['a' => $id, 'n' => $numero, 'p' => $ctx->job->id, 'res' => $r->resultado, 'mv' => $r->motorVersion, 'rv' => $r->reglasVersion,
                 'pid' => $perfilId, 'conf' => $r->confianza, 'po' => $r->perfilOrigen, 'pun' => $r->puntaje, 'hi' => $r->hoja, 'ho' => $r->hojaOrigen,
                 'th' => count($r->sheets), 'fd' => $r->filasDetalle, 'hc' => $r->huella, 'hv' => $r->huellaVersion,
                 'adv' => Json::encode(array_map(static fn ($w) => $w->asArray(), $r->advertencias)), 'ev' => Json::encode(Json::obj($r->evidencia)),
                 'met' => Json::encode(Json::obj($r->metricas)), 'man' => $manual === null ? null : Json::encode($manual), 'hk' => $hkid]);
            $hojaIds = [];
            foreach ($r->sheets as $s) {
                $hojaIds[$s->index] = (int) $db->fetchValue(
                    'INSERT INTO archivo_hojas (analisis_id, archivo_id, indice, nombre_original, visibilidad, filas_detectadas, columnas_detectadas,
                            rango_usado, dimension_declarada, cantidad_celdas_no_vacias, tiene_filtros, tiene_filas_ocultas, filas_ocultas,
                            tiene_columnas_ocultas, tiene_formulas, cantidad_formulas, formulas_sin_cache, celdas_combinadas, encabezado_fila,
                            encabezado_filas, perfil_sugerido_id, puntaje_candidata, seleccionada, estado, motivo_seleccion, filas_detalle, filas_total,
                            advertencias, metadata)
                     VALUES (:a, :ar, :i, :n, :v, :fd, :cd, :ru, :dd, :cc, :tf, :tfo, :fo, :tco, :tfm, :cf, :fsc, :cm, :ef, :efs, :ps, :pc, :sel, :est,
                             :mot, :fdet, :ftot, CAST(:adv AS jsonb), CAST(:md AS jsonb)) RETURNING id',
                    ['a' => $analisisId, 'ar' => $id, 'i' => $s->index, 'n' => Py::sub($s->name, 255) !== '' ? Py::sub($s->name, 255) : '(sin nombre)',
                     'v' => $s->visibility, 'fd' => $s->filasDetectadas, 'cd' => $s->columnasDetectadas, 'ru' => $s->rangoUsado, 'dd' => $s->dimension,
                     'cc' => $s->cellsNonempty, 'tf' => $s->tieneFiltros, 'tfo' => $s->filasOcultas > 0, 'fo' => $s->filasOcultas,
                     'tco' => $s->tieneColumnasOcultas, 'tfm' => $s->formulas === null ? null : $s->formulas > 0, 'cf' => $s->formulas,
                     'fsc' => $s->formulasSinCache, 'cm' => $s->merges, 'ef' => $s->headerRow, 'efs' => $s->headerRows,
                     'ps' => $s->perfil !== null ? ($perfilIds[$s->perfil] ?? null) : null, 'pc' => $s->puntaje, 'sel' => $s->seleccionada,
                     'est' => $s->estado, 'mot' => $s->motivo !== '' ? Py::sub($s->motivo, 500) : null, 'fdet' => $s->filasDetalle,
                     'ftot' => $s->filasTotal, 'adv' => Json::encode(array_map(static fn ($w) => $w->asArray(), $s->advertencias)),
                     'md' => Json::encode(Json::obj($s->metadata))]);
                if ($s->columns !== []) {
                    BatchInsert::run($db, 'archivo_columnas', ['hoja_id', 'indice', 'letra', 'encabezado_original', 'encabezado_normalizado',
                        'campo_canonico', 'tipo_inferido', 'pct_nulos', 'pct_texto', 'pct_numerico', 'pct_fecha', 'identificador_probable', 'es_auxiliar'],
                        array_map(static fn ($c) => [$hojaIds[$s->index], $c->index, $c->letter, $c->headerOriginal, $c->headerNormalized, $c->canon,
                            $c->tipo, Py::reprFloat($c->pctNull), Py::reprFloat($c->pctText), Py::reprFloat($c->pctNum), Py::reprFloat($c->pctDate),
                            $c->identificador, $c->auxiliar], $s->columns));
                }
            }
            BatchInsert::run($db, 'controles_archivo', ['analisis_id', 'archivo_id', 'hoja_id', 'codigo', 'descripcion', 'tipo_valor', 'valor_declarado',
                'valor_calculado', 'diferencia', 'tolerancia', 'estado', 'filas_evidencia', 'mensaje', 'detalle'],
                array_map(static fn ($c) => [$analisisId, $id, $c->hoja !== null ? ($hojaIds[$c->hoja] ?? null) : null, $c->codigo, $c->descripcion,
                    $c->tipoValor, $c->declarado, $c->calculado, $c->diferencia, $c->tolerancia, $c->estado, $c->filas,
                    $c->mensaje !== '' ? $c->mensaje : null, Json::obj($c->detalle)], $r->controls),
                ['filas_evidencia' => 'int[]', 'detalle' => 'jsonb']);
            $rows = [];
            foreach ($r->staging as $st) {
                $ok = $st->cuenta !== null && strlen($st->cuenta) >= 6 && strlen($st->cuenta) <= 20;
                $mac = $ok ? AccountCrypto::accountHmac($key, (string) $st->cuenta) : null;
                $last4 = $ok ? substr((string) $st->cuenta, -4) : ($st->cuenta !== null && $st->cuenta !== '' ? substr($st->cuenta, -4) : null);
                $blob = $ok ? AccountCrypto::encrypt($ctx->settings, (string) $st->cuenta) : null;
                $rows[] = [$analisisId, $id, $hojaIds[$st->hoja], $perfilId, $st->fila, $st->oculta, $st->importe, $st->importeEstado,
                    Json::obj($st->datos), $mac, $last4, $st->hash, $st->clave, $blob, $blob !== null ? $ctx->settings->encKeyId : null,
                    $mac !== null ? $hkid : null];
            }
            BatchInsert::run($db, 'staging_registros', ['analisis_id', 'archivo_id', 'hoja_id', 'perfil_id', 'fila_origen', 'oculta', 'importe',
                'importe_estado', 'datos', 'cuenta_hmac', 'cuenta_ultimos4', 'hash_registro', 'clave_hash', 'cuenta_cifrada', 'cuenta_key_id',
                'cuenta_hmac_key_id'], $rows, ['datos' => 'jsonb', 'cuenta_cifrada' => 'bytea']);
            [$clasif, $relacionado, $relacion] = $this->classify($db, $row, $r, $perfilId, $analisisId);
            $db->execute(
                'UPDATE archivos_importados SET estado = :e, perfil_importacion_id = :p, huella_contenido = :h, huella_version = :hv,
                        clasificacion_duplicidad = :c, archivo_relacionado_id = :rel, analisis_vigente_id = :an, procesado_at = now() WHERE id = :id',
                ['e' => $r->resultado, 'p' => $perfilId, 'h' => $r->huella, 'hv' => $r->huellaVersion, 'c' => $clasif, 'rel' => $relacionado,
                 'an' => $analisisId, 'id' => $id]);
            $codes = array_values(array_unique(array_map(static fn ($w) => $w->codigo, $r->advertencias)));
            sort($codes, SORT_STRING);
            $ctrl = [];
            foreach ($r->controls as $c) {
                if ($c->estado !== 'NO_APLICA') {
                    $ctrl[$c->codigo] = $c->estado;
                }
            }
            WorkerAudit::record($db, 'archivos.analizar', 'archivos_importados', $id, ['estado' => 'ANALIZANDO'], ['estado' => $r->resultado], [
                'analisis_numero' => $numero, 'perfil' => $r->perfil, 'confianza' => $r->confianza, 'perfil_origen' => $r->perfilOrigen,
                'hoja_indice' => $r->hoja, 'hoja_origen' => $r->hojaOrigen, 'filas_detalle' => $r->filasDetalle,
                'huella' => $r->huella !== null ? substr($r->huella, 0, 16) . '…' : null, 'huella_version' => $r->huellaVersion,
                'advertencias' => array_slice($codes, 0, 30), 'controles' => Json::obj($ctrl), 'reanalisis' => $prev !== null,
                'worker' => $ctx->workerId, 'reglas_version' => AnalysisResult::REGLAS_VERSION,
                'huella_anterior' => $prev !== null && $prev['huella_contenido'] !== null ? substr((string) $prev['huella_contenido'], 0, 16) . '…' : null]);
            $prevRel = $row['archivo_relacionado_id'] !== null ? (int) $row['archivo_relacionado_id'] : null;
            if ($clasif !== $row['clasificacion_duplicidad'] || $relacionado !== $prevRel) {
                WorkerAudit::record($db, 'archivos.clasificar_contenido', 'archivos_importados', $id,
                    ['clasificacion_duplicidad' => $row['clasificacion_duplicidad'], 'archivo_relacionado_id' => $prevRel],
                    ['clasificacion_duplicidad' => $clasif, 'archivo_relacionado_id' => $relacionado], Json::obj($relacion));
            }
            return ['archivo_id' => $id, 'analisis_id' => $analisisId, 'resultado' => $r->resultado, 'perfil' => $r->perfil,
                    'clasificacion_duplicidad' => $clasif, 'filas_detalle' => $r->filasDetalle, 'controles' => count($r->controls),
                    'metricas' => Json::obj($r->metricas)];
        });
    }

    /**
     * @param array<string,mixed> $row
     * @return array{0:string,1:?int,2:array<string,mixed>}
     */
    private function classify(Database $db, array $row, AnalysisResult $r, ?int $perfilId, int $analisisId): array
    {
        if ($row['clasificacion_duplicidad'] === 'DUPLICADO_BINARIO') {
            return ['DUPLICADO_BINARIO', $row['archivo_relacionado_id'] !== null ? (int) $row['archivo_relacionado_id'] : null, ['motivo' => 'mismo SHA-256']];
        }
        if ($r->resultado === 'REVISION_MANUAL') {
            return ['REVISION_MANUAL', null, ['motivo' => 'análisis en revisión manual']];
        }
        if ($r->huella === null) {
            return ['SIN_HUELLA', null, ['motivo' => 'sin perfil para calcular la huella']];
        }
        $same = $db->fetchValue(
            "SELECT id FROM archivos_importados WHERE id < :id AND estado <> 'ANULADO' AND huella_contenido = :h AND huella_version = :v ORDER BY id LIMIT 1",
            ['id' => (int) $row['id'], 'h' => $r->huella, 'v' => Fingerprint::VERSION]);
        if ($same !== null) {
            return ['DUPLICADO_CONTENIDO', (int) $same, ['motivo' => 'misma huella lógica v1, distinto SHA-256']];
        }
        if ($perfilId === null) {
            return ['NUEVO', null, []];
        }
        $cands = $db->fetchAll(
            "SELECT a.id, an.id AS analisis_id, an.filas_detalle FROM archivos_importados a
               JOIN lotes_importacion l ON l.id = a.lote_id
               JOIN analisis_archivo an ON an.id = a.analisis_vigente_id
              WHERE a.id < :id AND a.estado <> 'ANULADO' AND a.perfil_importacion_id = :p
                AND l.periodo_id = (SELECT periodo_id FROM lotes_importacion WHERE id = :l)
              ORDER BY a.id", ['id' => (int) $row['id'], 'p' => $perfilId, 'l' => (int) $row['lote_id']]);
        $mine = array_fill_keys($r->encabezados, true);
        $global = $r->perfil !== null && str_starts_with($r->perfil, 'RRHH_POLIZA_GLOBAL');
        $best = null;
        $myKey = $db->fetchValue('SELECT hash_key_id FROM analisis_archivo WHERE id = :id', ['id' => $analisisId]);
        $noComparables = [];
        foreach ($cands as $c) {
            $other = $db->fetchValue('SELECT hash_key_id FROM analisis_archivo WHERE id = :id', ['id' => (int) $c['analisis_id']]);
            if ($other !== null && $myKey !== null && $other !== $myKey) {
                $noComparables[] = (int) $c['id'];
                continue;
            }
            $theirs = [];
            foreach ($db->fetchAll('SELECT c.encabezado_normalizado FROM archivo_columnas c JOIN archivo_hojas h ON h.id = c.hoja_id
                                     WHERE h.analisis_id = :a AND h.seleccionada AND c.encabezado_normalizado IS NOT NULL',
                ['a' => (int) $c['analisis_id']]) as $x) {
                $theirs[(string) $x['encabezado_normalizado']] = true;
            }
            $union = count($mine + $theirs);
            $jacc = $global ? 1.0 : ($union > 0 ? count(array_intersect_key($mine, $theirs)) / $union : 0.0);
            $ov = $db->fetchOne(
                'SELECT (SELECT count(*) FROM (SELECT DISTINCT hash_registro FROM staging_registros WHERE analisis_id = :a) x
                           JOIN (SELECT DISTINCT hash_registro FROM staging_registros WHERE analisis_id = :b) y USING (hash_registro)) AS filas,
                        (SELECT count(*) FROM (SELECT DISTINCT clave_hash FROM staging_registros WHERE analisis_id = :a2 AND clave_hash IS NOT NULL) x
                           JOIN (SELECT DISTINCT clave_hash FROM staging_registros WHERE analisis_id = :b2 AND clave_hash IS NOT NULL) y USING (clave_hash)) AS claves',
                ['a' => $analisisId, 'b' => (int) $c['analisis_id'], 'a2' => $analisisId, 'b2' => (int) $c['analisis_id']]);
            $size = max($r->filasDetalle, (int) $c['filas_detalle'], 1);
            $rowOverlap = (int) $ov['filas'] / $size;
            $keyOverlap = (int) $ov['claves'] / $size;
            if ($jacc >= 0.8 && ($rowOverlap >= 0.5 || $keyOverlap >= 0.8)) {
                $score = $rowOverlap + $keyOverlap + $jacc;
                $ev = ['motivo' => 'mismo periodo y perfil; estructura y registros coincidentes', 'encabezados_jaccard' => Py::round($jacc, 2),
                       'registros_en_comun' => (int) $ov['filas'], 'claves_en_comun' => (int) $ov['claves'], 'registros_propios' => $r->filasDetalle,
                       'registros_otro' => (int) $c['filas_detalle']];
                if ($best === null || $score > $best[0]) {
                    $best = [$score, (int) $c['id'], $ev];
                }
            }
        }
        if ($best !== null) {
            return ['POSIBLE_VERSION', $best[1], $best[2]];
        }
        if ($noComparables !== []) {
            return ['NUEVO', null, ['no_comparables_por_llave' => $noComparables,
                                    'motivo' => 'archivos del periodo analizados con otra llave HMAC: reanalícelos para comparar registros']];
        }
        return ['NUEVO', null, []];
    }
}
