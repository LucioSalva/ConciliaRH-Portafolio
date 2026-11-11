<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Jobs;

use ConciliaRH\Engine\Support\DbError;
use ConciliaRH\Core\Database;
use ConciliaRH\Engine\Identity\Beneficiaries;
use ConciliaRH\Engine\Identity\Duplicates;
use ConciliaRH\Engine\Identity\History;
use ConciliaRH\Engine\Identity\PersonIndex;
use ConciliaRH\Engine\Normalization\Carry;
use ConciliaRH\Engine\Normalization\IdentityIncident;
use ConciliaRH\Engine\Normalization\ImportContext;
use ConciliaRH\Engine\Normalization\ImportMetrics;
use ConciliaRH\Engine\Normalization\IndexLoader;
use ConciliaRH\Engine\Normalization\PolicyImporter;
use ConciliaRH\Engine\Normalization\Reassociation;
use ConciliaRH\Engine\Normalization\RrhhImporter;
use ConciliaRH\Engine\Normalization\TreasuryImporter;
use ConciliaRH\Engine\Support\Json;
use ConciliaRH\Engine\Worker\JobContext;
use ConciliaRH\Engine\Worker\JobFailure;
use ConciliaRH\Engine\Worker\JobHandler;
use PDOException;
use stdClass;

final class NormalizeRecordsJob implements JobHandler
{
    private const IMPORTABLE = ['ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS'];
    private const TIPO_POR_FAMILIA = ['RRHH_TABULADO' => 'RRHH', 'RRHH_POLIZA_GLOBAL' => 'POLIZA_GLOBAL', 'POLIZA_EGRESO_BANCARIA' => 'POLIZA_EGRESO',
                                      'TESORERIA_SANTANDER' => 'TESORERIA', 'TESORERIA_BBVA' => 'TESORERIA', 'TESORERIA_PENSION_BBVA' => 'TESORERIA',
                                      'TESORERIA_INTERBANCARIA' => 'TESORERIA'];

    public function run(JobContext $ctx): array
    {
        try {
            return $this->execute($ctx);
        } catch (PDOException $e) {
            $state = (string) ($e->errorInfo[0] ?? '');
            if (str_starts_with($state, '23') || str_starts_with($state, '22') || str_starts_with($state, '42')) {
                throw JobFailure::permanent('Los registros del archivo contradicen una regla de integridad; la importación no se aplicó.',
                    'NORMALIZACION_INCONSISTENTE', DbError::detail($e));
            }
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    private function execute(JobContext $ctx): array
    {
        $id = (int) $ctx->params()['archivo_id'];
        $completa = (bool) ($ctx->params()['completa'] ?? false);
        $db = $ctx->db;
        $f = $db->fetchOne(
            'SELECT a.id, a.estado, a.analisis_vigente_id, a.clasificacion_duplicidad, a.lote_id, l.periodo_id, l.estado AS lote_estado,
                    p.estado AS periodo_estado, p.fecha_fin::text AS fecha_fin, an.perfil_id, an.resultado, an.advertencias, pi.codigo AS perfil, pi.familia
               FROM archivos_importados a
               JOIN lotes_importacion l ON l.id = a.lote_id
               JOIN periodos_nomina p ON p.id = l.periodo_id
               LEFT JOIN analisis_archivo an ON an.id = a.analisis_vigente_id
               LEFT JOIN perfiles_importacion pi ON pi.id = an.perfil_id
              WHERE a.id = :id', ['id' => $id]);
        if ($f === null) {
            throw JobFailure::permanent('El archivo a normalizar no existe.', 'ARCHIVO_INEXISTENTE');
        }
        if (!in_array($f['estado'], self::IMPORTABLE, true) || $f['analisis_vigente_id'] === null) {
            return ['archivo_id' => $id, 'omitido' => "El archivo está en estado {$f['estado']}."];
        }
        $tipo = self::TIPO_POR_FAMILIA[(string) ($f['familia'] ?? '')] ?? null;
        if ($tipo === null) {
            return ['archivo_id' => $id, 'omitido' => 'El perfil del análisis no produce registros normalizables.'];
        }
        $ctx->check();
        $t0 = hrtime(true);
        $ctx->progress(5, 'IMPORTANDO', 'Normalizando registros');
        $result = $this->import($ctx, $f, $tipo, $completa);
        if (isset($result['existente'])) {
            $ctx->progress(20, 'NORMALIZANDO', 'Re-asociando registros pendientes');
            $summary = Reassociation::run($ctx, [(int) $result['existente']]);
            return ['archivo_id' => $id, 'importacion_id' => (int) $result['existente'], 'modo' => 'REASOCIACION', ...$summary,
                    'duracion_ms' => intdiv(hrtime(true) - $t0, 1_000_000)];
        }
        if (isset($result['omitido'])) {
            return $result;
        }
        $ctx->progress(85, 'NORMALIZANDO', 'Re-asociando pendientes del periodo');
        $others = array_map(static fn ($r) => (int) $r['id'], $db->fetchAll(
            "SELECT id FROM importaciones_normalizadas WHERE periodo_id = :p AND estado = 'VIGENTE' AND id <> :i
                AND tipo_registro IN ('RRHH', 'TESORERIA') ORDER BY id", ['p' => (int) $f['periodo_id'], 'i' => (int) $result['importacion_id']]));
        $re = ($others !== [] && (($result['altas'] ?? 0) || ($result['cuentas_nuevas'] ?? 0))) ? Reassociation::run($ctx, $others) : [];
        $ctx->progress(100, 'FINALIZANDO', 'Normalización terminada');
        $pend = (int) ($result['pendientes'] ?? 0);
        return [...$result, 'reasociacion_periodo' => Json::obj($re), 'duracion_ms' => intdiv(hrtime(true) - $t0, 1_000_000),
                '_estado' => $pend ? 'COMPLETADO_CON_INCIDENCIAS' : 'COMPLETADO'];
    }

    /** @param array<string,mixed> $f @return array<string,mixed> */
    private function import(JobContext $ctx, array $f, string $tipo, bool $completa): array
    {
        $id = (int) $f['id'];
        return $ctx->db->transaction(function (Database $db) use ($ctx, $f, $tipo, $completa, $id): array {
            $db->fetchValue('SELECT pg_advisory_xact_lock(:k)', ['k' => Reassociation::LOCK_KEY]);
            $cur = $db->fetchOne('SELECT estado, analisis_vigente_id FROM archivos_importados WHERE id = :id FOR SHARE', ['id' => $id]);
            if (!in_array($cur['estado'], self::IMPORTABLE, true) || (int) $cur['analisis_vigente_id'] !== (int) $f['analisis_vigente_id']) {
                return ['archivo_id' => $id, 'omitido' => 'El análisis vigente cambió durante la normalización.'];
            }
            $existing = $db->fetchValue(
                "SELECT id FROM importaciones_normalizadas WHERE archivo_id = :a AND analisis_id = :an AND estado = 'VIGENTE' AND matching_version = :v",
                ['a' => $id, 'an' => (int) $f['analisis_vigente_id'], 'v' => Reassociation::MATCHING_VERSION]);
            if ($existing !== null && !$completa) {
                return ['archivo_id' => $id, 'existente' => (int) $existing];
            }
            $prev = $db->fetchValue('SELECT id FROM importaciones_normalizadas WHERE archivo_id = :a ORDER BY numero DESC LIMIT 1', ['a' => $id]);
            $db->execute("UPDATE importaciones_normalizadas SET estado = 'SUSTITUIDA', estado_motivo = 'NUEVA_NORMALIZACION'
                           WHERE archivo_id = :a AND estado = 'VIGENTE'", ['a' => $id]);
            if ($prev !== null) {
                $db->execute("UPDATE incidencias_identidad SET estado = 'DESCARTADA', resuelto_at = now(),
                                     resolucion = 'Importación sustituida por una nueva normalización.'
                               WHERE importacion_id = :i AND estado = 'ABIERTA'", ['i' => (int) $prev]);
            }
            $numero = (int) $db->fetchValue('SELECT coalesce(max(numero), 0) + 1 FROM importaciones_normalizadas WHERE archivo_id = :a', ['a' => $id]);
            [$puede, $motivo] = self::canUpdateMaster($f, $tipo);
            $grupo = self::grupo($f['perfil']);
            $impId = (int) $db->fetchValue(
                'INSERT INTO importaciones_normalizadas (archivo_id, analisis_id, numero, tipo_registro, periodo_id, lote_id, perfil_id, grupo, proceso_id,
                        matching_version, puede_actualizar_maestro, motivo_sin_maestro)
                 VALUES (:a, :an, :n, :t, :p, :l, :pf, :g, :pr, :mv, :pu, :mo) RETURNING id',
                ['a' => $id, 'an' => (int) $f['analisis_vigente_id'], 'n' => $numero, 't' => $tipo, 'p' => (int) $f['periodo_id'], 'l' => (int) $f['lote_id'],
                 'pf' => (int) $f['perfil_id'], 'g' => $grupo, 'pr' => $ctx->job->id, 'mv' => Reassociation::MATCHING_VERSION, 'pu' => $puede, 'mo' => $motivo]);
            $staging = $db->fetchAll(
                "SELECT s.fila_origen, s.oculta, s.importe, s.importe_estado, s.datos, s.cuenta_hmac, s.cuenta_ultimos4,
                        encode(s.cuenta_cifrada, 'hex') AS cuenta_cifrada_hex, s.cuenta_key_id, s.cuenta_hmac_key_id, s.hash_registro, s.hoja_id,
                        h.indice AS hoja_indice, h.nombre_original AS hoja_nombre
                   FROM staging_registros s JOIN archivo_hojas h ON h.id = s.hoja_id
                  WHERE s.analisis_id = :a ORDER BY h.indice, s.fila_origen, s.id", ['a' => (int) $f['analisis_vigente_id']]);
            foreach ($staging as $k => $s) {
                $staging[$k]['datos'] = Json::decode((string) $s['datos']) ?? [];
                $staging[$k]['cuenta_cifrada'] = $s['cuenta_cifrada_hex'] !== null ? hex2bin((string) $s['cuenta_cifrada_hex']) : null;
                unset($staging[$k]['cuenta_cifrada_hex']);
            }
            $ctx->check();
            $c = new ImportContext($db, $ctx, $impId, $f, $staging, new PersonIndex(), [], []);
            if (in_array($tipo, ['RRHH', 'TESORERIA'], true)) {
                [$c->idx, $c->emps] = IndexLoader::load($db);
                [$c->carry, $c->carryByPosition] = Carry::load($db, $prev !== null ? (int) $prev : null, $tipo, (int) $f['analisis_vigente_id']);
                $c->carryTreasury = $tipo === 'TESORERIA';
            }
            $ctx->progress(20, 'IMPORTANDO', 'Insertando registros');
            $tiempos = [];
            $t = hrtime(true);
            $summary = match ($tipo) {
                'RRHH' => (new RrhhImporter($c))->run($puede, $grupo ?? 'POLICIA'),
                'TESORERIA' => (new TreasuryImporter($c))->run(),
                'POLIZA_GLOBAL' => (new PolicyImporter($c))->global($grupo ?? 'POLICIA'),
                default => (new PolicyImporter($c))->egreso(),
            };
            $ctx->check();
            $tiempos['registros_ms'] = intdiv(hrtime(true) - $t, 1_000_000);
            $t = hrtime(true);
            if ($tipo === 'TESORERIA' && ($summary['categoria'] ?? null) === 'PENSION') {
                $summary['beneficiarios'] = Json::obj(Beneficiaries::link($db, $impId));
            }
            if (in_array($tipo, ['RRHH', 'TESORERIA'], true)) {
                $summary['sugerencias_pension'] = Json::obj(Beneficiaries::suggest($db, (int) $f['lote_id'], $ctx->job->id));
            }
            $tiempos['beneficiarios_ms'] = intdiv(hrtime(true) - $t, 1_000_000);
            IdentityIncident::save($db, $impId, $c->incs);
            $t = hrtime(true);
            if ($tipo === 'RRHH') {
                $focus = array_map(static fn ($r) => (int) $r['empleado_id'], $db->fetchAll(
                    'SELECT DISTINCT empleado_id FROM registros_rrhh WHERE importacion_id = :i AND empleado_id IS NOT NULL ORDER BY 1', ['i' => $impId]));
                $summary['duplicados'] = Json::obj(Duplicates::detect($db, $ctx->job->id, $focus));
            }
            $tiempos['duplicados_ms'] = intdiv(hrtime(true) - $t, 1_000_000);
            $summary['tiempos'] = Json::obj($tiempos);
            History::insert($db, $c->hist);
            $incTipos = [];
            foreach ($c->incs as $i) {
                $incTipos[$i->tipo] = ($incTipos[$i->tipo] ?? 0) + 1;
            }
            $metricas = ImportMetrics::compute($db, $impId, $tipo, [...$summary, 'incidencias' => Json::obj($incTipos)]);
            $db->execute('UPDATE importaciones_normalizadas SET registros = :r, metricas = CAST(:m AS jsonb) WHERE id = :id',
                ['r' => (int) ($summary['registros'] ?? 0), 'm' => Json::encode(Json::obj($metricas)), 'id' => $impId]);
            $resumen = array_filter($metricas, static fn ($v) => !(is_array($v) && array_is_list($v)));
            WorkerAudit::record($db, 'importacion.normalizar', 'archivos_importados', $id, null,
                ['importacion_id' => $impId, 'numero' => $numero, 'tipo_registro' => $tipo],
                ['proceso_id' => $ctx->job->id, 'matching_version' => Reassociation::MATCHING_VERSION, 'puede_actualizar_maestro' => $puede,
                 'motivo_sin_maestro' => $motivo, 'resumen' => Json::obj($resumen)]);
            $porEstado = $metricas['por_estado'] ?? new stdClass();
            $pend = 0;
            foreach (['SUGERENCIA', 'AMBIGUO', 'CONFLICTO', 'NO_IDENTIFICADO'] as $e) {
                $pend += (int) (((array) $porEstado)[$e] ?? 0);
            }
            return ['archivo_id' => $id, 'importacion_id' => $impId, 'modo' => 'COMPLETA', 'tipo_registro' => $tipo, ...$summary, 'pendientes' => $pend];
        });
    }

    private static function grupo(?string $perfil): ?string
    {
        if ($perfil !== null && str_ends_with($perfil, '_POLICIA')) {
            return 'POLICIA';
        }
        if ($perfil !== null && str_ends_with($perfil, '_BOMBEROS')) {
            return 'BOMBEROS';
        }
        return null;
    }

    /** @param array<string,mixed> $f @return array{0:bool,1:?string} */
    private static function canUpdateMaster(array $f, string $tipo): array
    {
        if ($tipo !== 'RRHH') {
            return [false, 'SOLO_RRHH_ACTUALIZA_MAESTRO'];
        }
        $codes = [];
        foreach ((array) (Json::decode($f['advertencias'] ?? null) ?? []) as $w) {
            if (is_array($w)) {
                $codes[] = $w['codigo'] ?? null;
            }
        }
        if (in_array('VISTA_DERIVADA', $codes, true)) {
            return [false, 'VISTA_DERIVADA'];
        }
        if (in_array($f['clasificacion_duplicidad'], ['DUPLICADO_BINARIO', 'DUPLICADO_CONTENIDO'], true)) {
            return [false, (string) $f['clasificacion_duplicidad']];
        }
        if ($f['lote_estado'] === 'ANULADO' || $f['periodo_estado'] === 'ANULADO') {
            return [false, 'LOTE_O_PERIODO_ANULADO'];
        }
        return [true, null];
    }
}
