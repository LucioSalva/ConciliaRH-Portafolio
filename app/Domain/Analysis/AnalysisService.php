<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Analysis;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Imports\ImportException;
use ConciliaRH\Domain\Jobs\JobQueue;
use ConciliaRH\Domain\Periods\PeriodService;
use ConciliaRH\Domain\Settings\SettingsService;
use ConciliaRH\Domain\ValidationException;

final class AnalysisService
{
    public const ANALYZABLE = ['VERIFICADO', 'ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS', 'REVISION_MANUAL'];

    public function __construct(
        private readonly Database $db,
        private readonly AuditLogger $audit,
        private readonly JobQueue $jobs,
        private readonly SettingsService $settings,
    ) {
    }

    /** @return array<string,mixed>|null análisis vigente con su perfil */
    public function current(int $archivoId): ?array
    {
        $a = $this->db->fetchOne(
            'SELECT an.*, p.codigo AS perfil_codigo, p.nombre AS perfil_nombre, t.codigo AS perfil_tipo_codigo
               FROM archivos_importados a
               JOIN analisis_archivo an ON an.id = a.analisis_vigente_id
               LEFT JOIN perfiles_importacion p ON p.id = an.perfil_id
               LEFT JOIN tipos_documento t ON t.id = p.tipo_documento_id
              WHERE a.id = :id',
            ['id' => $archivoId]
        );
        if ($a === null) {
            return null;
        }
        foreach (['advertencias', 'evidencia', 'metricas', 'seleccion_manual'] as $k) {
            $a[$k] = is_string($a[$k]) ? json_decode($a[$k], true) : $a[$k];
        }
        return $a;
    }

    /** @return list<array<string,mixed>> */
    public function sheets(int $analisisId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT h.*, p.codigo AS perfil_codigo FROM archivo_hojas h
               LEFT JOIN perfiles_importacion p ON p.id = h.perfil_sugerido_id
              WHERE h.analisis_id = :a ORDER BY h.indice',
            ['a' => $analisisId]
        );
        foreach ($rows as &$r) {
            $r['advertencias'] = is_string($r['advertencias']) ? json_decode($r['advertencias'], true) : $r['advertencias'];
            $r['metadata'] = is_string($r['metadata']) ? json_decode($r['metadata'], true) : $r['metadata'];
        }
        return $rows;
    }

    /** Metadatos de columnas (sin valores) de una hoja del análisis. @return list<array<string,mixed>> */
    public function columns(int $hojaId): array
    {
        return $this->db->fetchAll('SELECT * FROM archivo_columnas WHERE hoja_id = :h ORDER BY indice', ['h' => $hojaId]);
    }

    /** @return list<array<string,mixed>> */
    public function controls(int $analisisId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT c.*, h.indice AS hoja_indice, h.nombre_original AS hoja_nombre
               FROM controles_archivo c LEFT JOIN archivo_hojas h ON h.id = c.hoja_id
              WHERE c.analisis_id = :a ORDER BY coalesce(h.indice, -1), c.id',
            ['a' => $analisisId]
        );
        foreach ($rows as &$r) {
            $r['detalle'] = is_string($r['detalle']) ? json_decode($r['detalle'], true) : $r['detalle'];
        }
        return $rows;
    }

    /** Archivos relacionados: el que este archivo referencia y los que lo referencian. @return list<array<string,mixed>> */
    public function related(int $archivoId): array
    {
        return $this->db->fetchAll(
            "SELECT a.id, a.nombre_original, a.clasificacion_duplicidad, a.estado, l.nombre AS lote_nombre, l.numero AS lote_numero,
                    CASE WHEN a.id = (SELECT archivo_relacionado_id FROM archivos_importados WHERE id = :id1) THEN 'REFERENCIADO'
                         ELSE 'REFERENCIA_A_ESTE' END AS sentido
               FROM archivos_importados a JOIN lotes_importacion l ON l.id = a.lote_id
              WHERE (a.id = (SELECT archivo_relacionado_id FROM archivos_importados WHERE id = :id2) OR a.archivo_relacionado_id = :id3)
                AND a.id <> :id4
              ORDER BY a.id LIMIT 50",
            ['id1' => $archivoId, 'id2' => $archivoId, 'id3' => $archivoId, 'id4' => $archivoId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function history(int $archivoId): array
    {
        return $this->db->fetchAll(
            'SELECT an.id, an.numero, an.vigente, an.resultado, an.perfil_confianza, an.perfil_origen, an.hoja_indice, an.hoja_origen,
                    an.filas_detalle, an.huella_contenido, an.created_at, p.codigo AS perfil_codigo
               FROM analisis_archivo an LEFT JOIN perfiles_importacion p ON p.id = an.perfil_id
              WHERE an.archivo_id = :id ORDER BY an.numero DESC LIMIT 20',
            ['id' => $archivoId]
        );
    }

    /** Perfiles que pueden elegirse manualmente. @return list<array<string,mixed>> */
    public function selectableProfiles(): array
    {
        return $this->db->fetchAll(
            'SELECT p.id, p.codigo, p.nombre, t.nombre AS tipo_nombre FROM perfiles_importacion p
               JOIN tipos_documento t ON t.id = p.tipo_documento_id
              WHERE p.activo AND p.implementado ORDER BY p.familia, p.codigo'
        );
    }

    public function reanalyze(CurrentUser $actor, int $archivoId): int
    {
        return $this->db->transaction(function () use ($actor, $archivoId): int {
            $a = $this->lockAnalyzable($archivoId);
            $jobId = $this->enqueue($actor, $a, 'r');
            $this->audit->record('analisis.reprocesar', 'archivos_importados', $archivoId, ['estado' => $a['estado']], null,
                ['proceso_id' => $jobId]);
            return $jobId;
        });
    }

    public function selectSheet(CurrentUser $actor, int $archivoId, ?int $indice, string $motivo): int
    {
        $motivo = PeriodService::requiredReason($motivo);
        return $this->db->transaction(function () use ($actor, $archivoId, $indice, $motivo): int {
            $a = $this->lockAnalyzable($archivoId);
            $nombre = null;
            if ($indice !== null) {
                $nombre = $this->db->fetchValue(
                    'SELECT h.nombre_original FROM archivo_hojas h WHERE h.analisis_id = :an AND h.indice = :i',
                    ['an' => $a['analisis_vigente_id'], 'i' => $indice]
                );
                if ($nombre === null) {
                    throw new ValidationException(['hoja' => 'La hoja elegida no existe en el análisis vigente.']);
                }
            }
            $this->db->execute(
                'UPDATE archivos_importados SET hoja_manual_indice = :i, hoja_manual_nombre = :n, seleccion_manual_por = :u,
                        seleccion_manual_at = now() WHERE id = :id',
                ['i' => $indice, 'n' => $nombre, 'u' => $actor->id, 'id' => $archivoId]
            );
            $jobId = $this->enqueue($actor, $a, 'h');
            $this->audit->record('analisis.seleccionar_hoja', 'archivos_importados', $archivoId,
                ['hoja_indice' => $a['hoja_manual_indice'], 'hoja_nombre' => $a['hoja_manual_nombre']],
                ['hoja_indice' => $indice, 'hoja_nombre' => $nombre],
                ['motivo' => $motivo, 'proceso_id' => $jobId, 'huella_invalidada' => $a['huella_contenido'] !== null]);
            return $jobId;
        });
    }

    public function selectProfile(CurrentUser $actor, int $archivoId, ?int $perfilId, string $motivo): int
    {
        $motivo = PeriodService::requiredReason($motivo);
        return $this->db->transaction(function () use ($actor, $archivoId, $perfilId, $motivo): int {
            $a = $this->lockAnalyzable($archivoId);
            $codigo = null;
            if ($perfilId !== null) {
                $codigo = $this->db->fetchValue('SELECT codigo FROM perfiles_importacion WHERE id = :p AND activo AND implementado',
                    ['p' => $perfilId]);
                if ($codigo === null) {
                    throw new ValidationException(['perfil' => 'Elija un perfil activo e implementado.']);
                }
            }
            $anterior = $a['perfil_manual_id'] !== null
                ? $this->db->fetchValue('SELECT codigo FROM perfiles_importacion WHERE id = :p', ['p' => $a['perfil_manual_id']]) : null;
            $this->db->execute(
                'UPDATE archivos_importados SET perfil_manual_id = :p, seleccion_manual_por = :u, seleccion_manual_at = now() WHERE id = :id',
                ['p' => $perfilId, 'u' => $actor->id, 'id' => $archivoId]
            );
            $jobId = $this->enqueue($actor, $a, 'p');
            $this->audit->record('analisis.seleccionar_perfil', 'archivos_importados', $archivoId, ['perfil_manual' => $anterior],
                ['perfil_manual' => $codigo], ['motivo' => $motivo, 'proceso_id' => $jobId, 'huella_invalidada' => $a['huella_contenido'] !== null]);
            return $jobId;
        });
    }

    /** @return array<string,mixed> */
    public function compare(int $a, int $b): array
    {
        if ($a === $b) {
            throw new ValidationException(['con' => 'Elija un archivo distinto para comparar.']);
        }
        $side = function (int $id): array {
            $f = $this->db->fetchOne(
                'SELECT a.id, a.nombre_original, a.sha256, a.huella_contenido, a.huella_version, a.estado, a.clasificacion_duplicidad,
                        a.analisis_vigente_id, a.tamano_bytes, l.nombre AS lote_nombre, pe.descripcion AS periodo_descripcion
                   FROM archivos_importados a JOIN lotes_importacion l ON l.id = a.lote_id JOIN periodos_nomina pe ON pe.id = l.periodo_id
                  WHERE a.id = :id', ['id' => $id]);
            if ($f === null) {
                throw HttpException::notFound();
            }
            $an = $this->current($id);
            $sheets = $an ? $this->sheets((int) $an['id']) : [];
            $sel = null;
            foreach ($sheets as $s) {
                if ($s['seleccionada']) {
                    $sel = $s;
                }
            }
            $headers = $sel ? array_values(array_filter(array_column($this->columns((int) $sel['id']), 'encabezado_normalizado'))) : [];
            $controls = [];
            foreach ($an ? $this->controls((int) $an['id']) : [] as $c) {
                $controls[$c['codigo'] . ($c['hoja_indice'] !== null ? '#' . $c['hoja_indice'] : '')] = $c;
            }
            return ['archivo' => $f, 'analisis' => $an, 'hojas' => $sheets, 'seleccionada' => $sel, 'encabezados' => $headers, 'controles' => $controls];
        };
        $A = $side($a);
        $B = $side($b);
        $common = null;
        $llaves = $A['analisis'] && $B['analisis'] ? [$A['analisis']['hash_key_id'] ?? null, $B['analisis']['hash_key_id'] ?? null] : [null, null];
        if ($llaves[0] !== null && $llaves[1] !== null && $llaves[0] !== $llaves[1]) {
            $common = ['no_comparable' => true];
        } elseif ($A['analisis'] && $B['analisis']) {
            $common = $this->db->fetchOne(
                'SELECT (SELECT count(DISTINCT hash_registro) FROM staging_registros WHERE analisis_id = :a1) AS total_a,
                        (SELECT count(DISTINCT hash_registro) FROM staging_registros WHERE analisis_id = :b1) AS total_b,
                        (SELECT count(*) FROM (SELECT DISTINCT hash_registro FROM staging_registros WHERE analisis_id = :a2) x
                           JOIN (SELECT DISTINCT hash_registro FROM staging_registros WHERE analisis_id = :b2) y USING (hash_registro)) AS comunes,
                        (SELECT count(*) FROM (SELECT DISTINCT clave_hash FROM staging_registros WHERE analisis_id = :a3 AND clave_hash IS NOT NULL) x
                           JOIN (SELECT DISTINCT clave_hash FROM staging_registros WHERE analisis_id = :b3 AND clave_hash IS NOT NULL) y USING (clave_hash)) AS claves_comunes',
                ['a1' => $A['analisis']['id'], 'b1' => $B['analisis']['id'], 'a2' => $A['analisis']['id'], 'b2' => $B['analisis']['id'],
                 'a3' => $A['analisis']['id'], 'b3' => $B['analisis']['id']]
            );
        }
        return [
            'a' => $A, 'b' => $B,
            'mismo_sha' => $A['archivo']['sha256'] === $B['archivo']['sha256'],
            'misma_huella' => $A['archivo']['huella_contenido'] !== null && $A['archivo']['huella_contenido'] === $B['archivo']['huella_contenido']
                && $A['archivo']['huella_version'] === $B['archivo']['huella_version'],
            'solo_a' => array_values(array_diff($A['encabezados'], $B['encabezados'])),
            'solo_b' => array_values(array_diff($B['encabezados'], $A['encabezados'])),
            'registros' => $common,
            'codigos_control' => array_values(array_unique(array_merge(array_keys($A['controles']), array_keys($B['controles'])))),
        ];
    }

    /** @return array<string,mixed> */
    private function lockAnalyzable(int $archivoId): array
    {
        $a = $this->db->fetchOne(
            "SELECT a.*, l.estado AS lote_estado, p.estado AS periodo_estado, pr.estado AS proceso_estado
               FROM archivos_importados a JOIN lotes_importacion l ON l.id = a.lote_id JOIN periodos_nomina p ON p.id = l.periodo_id
               LEFT JOIN procesos pr ON pr.id = a.proceso_id
              WHERE a.id = :id FOR UPDATE OF a",
            ['id' => $archivoId]
        );
        if ($a === null) {
            throw HttpException::notFound();
        }
        if (!in_array($a['estado'], self::ANALYZABLE, true)) {
            throw new ImportException('ESTADO_INVALIDO', 'El archivo no está en un estado que permita (re)analizarlo.');
        }
        if (in_array($a['proceso_estado'], ['PENDIENTE', 'EN_EJECUCION'], true)) {
            throw new ImportException('ESTADO_INVALIDO', 'Ya hay un análisis en curso para este archivo; espere a que termine.');
        }
        if ($a['lote_estado'] !== 'ABIERTO' || $a['periodo_estado'] !== 'ABIERTO') {
            throw new ImportException('LOTE_NO_ABIERTO');
        }
        $wait = $this->settings->int('analisis.reanalisis_espera_segundos', 30);
        $recent = $wait > 0 && (bool) $this->db->fetchValue(
            "SELECT EXISTS (SELECT 1 FROM procesos WHERE tipo = 'ANALIZAR_ARCHIVO' AND parametros->>'archivo_id' = :id
                              AND created_at > now() - make_interval(secs => :w))",
            ['id' => (string) $archivoId, 'w' => $wait]
        );
        if ($recent) {
            throw new ImportException('ESTADO_INVALIDO', "Espere {$wait} segundos entre dos análisis del mismo archivo.");
        }
        return $a;
    }

    /** @param array<string,mixed> $a */
    private function enqueue(CurrentUser $actor, array $a, string $kind): int
    {
        $n = 1 + (int) $this->db->fetchValue(
            "SELECT count(*) FROM procesos WHERE tipo = 'ANALIZAR_ARCHIVO' AND parametros->>'archivo_id' = :id",
            ['id' => (string) $a['id']]
        );
        $timeout = $this->settings->int('analisis.timeout_segundos', 900);
        $jobId = $this->jobs->enqueue($actor, 'ANALIZAR_ARCHIVO', ['archivo_id' => (int) $a['id']], 60,
            'analizar:' . $a['uuid'] . ':' . $kind . $n, $timeout);
        $this->db->execute('UPDATE archivos_importados SET proceso_id = :p WHERE id = :id', ['p' => $jobId, 'id' => $a['id']]);
        return $jobId;
    }
}
