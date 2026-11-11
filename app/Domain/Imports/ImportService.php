<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Imports;

use ConciliaRH\Core\Config;
use ConciliaRH\Core\Database;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Core\Logger;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Jobs\JobQueue;
use ConciliaRH\Domain\Periods\PeriodService;
use ConciliaRH\Domain\Settings\SettingsService;
use ConciliaRH\Domain\ValidationException;
use PDOException;
use Throwable;

final class ImportService
{
    public const DUPLICATE_STATES = ['PENDIENTE', 'NUEVO', 'DUPLICADO_BINARIO', 'DUPLICADO_CONTENIDO', 'POSIBLE_VERSION', 'SIN_HUELLA', 'REVISION_MANUAL'];
    public const POBLACIONES = ['POLICIA', 'BOMBEROS'];
    private const PRE_ANALYSIS = ['RECIBIDO', 'VERIFICADO', 'RECHAZADO', 'ERROR', 'ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS', 'REVISION_MANUAL'];

    public function __construct(
        private readonly Database $db,
        private readonly AuditLogger $audit,
        private readonly JobQueue $jobs,
        private readonly SettingsService $settings,
        private readonly ImportStorage $storage,
        private readonly FileInspector $inspector,
        private readonly UploadMover $mover,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function maxUploadSize(): int
    {
        return (int) $this->config->get('upload.max_size', 26214400);
    }

    /** @return array{max_entries:int,max_uncompressed:int,max_ratio:int,max_xml:int} */
    public function containerLimits(): array
    {
        return [
            'max_entries' => $this->settings->int('importacion.zip_max_entradas', 5000),
            'max_uncompressed' => $this->settings->int('importacion.zip_max_descomprimido_mb', 300) * 1_048_576,
            'max_ratio' => $this->settings->int('importacion.zip_max_ratio', 150),
            'max_xml' => $this->settings->int('importacion.xml_max_mb', 200) * 1_048_576,
        ];
    }

    /**
     * @return int id del archivo registrado
     * @throws ImportException
     */
    public function upload(CurrentUser $actor, int $loteId, int $tipoId, int $origenId, string $observaciones,
                           ?UploadedFile $file, bool $confirmDuplicate, ?string $poblacion = null): int
    {
        if ($poblacion !== null && !in_array($poblacion, self::POBLACIONES, true)) {
            throw new ValidationException(['poblacion' => 'Población no válida.']);
        }
        \ConciliaRH\Domain\Security\Throttle::check($this->db, $actor->id, ['archivos.cargar', 'archivos.rechazar_carga'], 30, 600,
            'Alcanzó el máximo de cargas en 10 minutos. Espere un momento antes de cargar más archivos.');
        $safeName = $file !== null ? FilenameSanitizer::sanitize($file->clientName) : '';
        try {
            return $this->doUpload($actor, $loteId, $tipoId, $origenId, $observaciones, $file, $confirmDuplicate, $safeName, $poblacion);
        } catch (ImportException $e) {
            $this->audit->record('archivos.rechazar_carga', 'lotes_importacion', $loteId, null, null, [
                'codigo' => $e->errorCode(),
                'nombre_sha256' => $safeName === '' ? null : hash('sha256', $safeName),
                'extension' => FilenameSanitizer::extension($safeName),
            ] + array_intersect_key($e->context(), array_flip(['mime_detectado', 'motivo', 'entradas', 'archivo_existente_id'])));
            throw $e;
        }
    }

    private function doUpload(CurrentUser $actor, int $loteId, int $tipoId, int $origenId, string $observaciones,
                              ?UploadedFile $file, bool $confirmDuplicate, string $safeName, ?string $poblacion): int
    {
        $this->assertLoteOpen($loteId);
        $this->assertClassification($tipoId, $origenId);
        if (mb_strlen(trim($observaciones)) > 2000) {
            throw new ValidationException(['observaciones' => 'Máximo 2000 caracteres.']);
        }
        if ($file === null) {
            throw new ImportException('UPLOAD_SIN_ARCHIVO');
        }
        match ($file->error) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => throw new ImportException('UPLOAD_DEMASIADO_GRANDE'),
            UPLOAD_ERR_PARTIAL => throw new ImportException('UPLOAD_INCOMPLETO'),
            UPLOAD_ERR_NO_FILE => throw new ImportException('UPLOAD_SIN_ARCHIVO'),
            default => throw new ImportException('UPLOAD_ERROR_SERVIDOR'),
        };
        if (!$this->mover->isUploaded($file->tmpPath)) {
            $this->logger->security('Archivo no proveniente de una carga HTTP', ['lote_id' => $loteId]);
            throw new ImportException('UPLOAD_ERROR_SERVIDOR');
        }
        $size = (int) @filesize($file->tmpPath);
        if ($size === 0) {
            throw new ImportException('ARCHIVO_VACIO');
        }
        if ($size > $this->maxUploadSize()) {
            throw new ImportException('UPLOAD_DEMASIADO_GRANDE');
        }
        if ($safeName === '' || mb_strlen($safeName) < 3) {
            throw new ImportException('NOMBRE_INVALIDO');
        }
        $ext = FilenameSanitizer::extension($safeName);
        if (!isset(FileInspector::ALLOWED_MIME[$ext])) {
            throw new ImportException('EXTENSION_NO_PERMITIDA');
        }
        $inspection = $this->inspector->inspect($file->tmpPath, $ext, $this->containerLimits());

        $uuid = ImportStorage::uuid();
        $staged = null;
        $final = null;
        $committing = false;
        try {
            $staged = $this->storage->stage($file, $this->mover, $uuid);
            $sha = (string) hash_file('sha256', $staged);
            $size = (int) filesize($staged);
            $existing = $this->findExact($sha, $loteId);
            if ($existing !== null && (int) $existing['lote_id'] === $loteId) {
                throw new ImportException('DUPLICADO_EXACTO_EN_LOTE', null, ['archivo_existente_id' => (int) $existing['id']]);
            }
            if ($existing !== null && !$confirmDuplicate) {
                throw new ImportException('DUPLICADO_EXACTO_REQUIERE_CONFIRMACION', null, [
                    'archivo_existente_id' => (int) $existing['id'],
                    'donde' => sprintf('%s · %s', $existing['periodo_descripcion'], $existing['lote_nombre']),
                ]);
            }

            return $this->db->transaction(function () use ($actor, $loteId, $tipoId, $origenId, $observaciones, $file,
                                                          $safeName, $ext, $inspection, $uuid, $staged, $sha, $size, $existing, $poblacion, &$final, &$committing): int {
                $lote = $this->db->fetchOne(
                    "SELECT l.id, l.periodo_id FROM lotes_importacion l JOIN periodos_nomina p ON p.id = l.periodo_id
                      WHERE l.id = :id AND l.estado = 'ABIERTO' AND p.estado = 'ABIERTO' FOR UPDATE OF l FOR SHARE OF p",
                    ['id' => $loteId]
                );
                if ($lote === null) {
                    throw new ImportException('LOTE_NO_ABIERTO');
                }
                $relative = ImportStorage::relativePath((int) $lote['periodo_id'], $loteId, $uuid, $ext);
                try {
                    $id = (int) $this->db->fetchValue(
                        'INSERT INTO archivos_importados (uuid, lote_id, tipo_documento_id, origen_administrativo_id, nombre_original,
                                extension, mime_declarado, mime_detectado, firma, tamano_bytes, sha256, clasificacion_duplicidad,
                                archivo_relacionado_id, ruta_relativa, tiene_macros, contenedor, observaciones, usuario_carga_id, poblacion)
                         VALUES (CAST(:uuid AS uuid), :lote, :tipo, :origen, :nombre, :ext, :mime_decl, :mime, :firma, :tam, :sha,
                                 :clasif, :rel_id, :ruta, :macros, CAST(:cont AS jsonb), :obs, :u, :pob)
                         RETURNING id',
                        [
                            'uuid' => $uuid, 'lote' => $loteId, 'tipo' => $tipoId, 'origen' => $origenId, 'nombre' => $safeName,
                            'ext' => $ext, 'mime_decl' => $file->clientMime !== '' ? $file->clientMime : null, 'mime' => $inspection['mime'],
                            'firma' => $inspection['firma'], 'tam' => $size, 'sha' => $sha,
                            'clasif' => $existing !== null ? 'DUPLICADO_BINARIO' : 'PENDIENTE',
                            'rel_id' => $existing !== null ? (int) $existing['id'] : null,
                            'ruta' => $relative, 'macros' => $inspection['tiene_macros'],
                            'cont' => json_encode($inspection['contenedor'], JSON_THROW_ON_ERROR),
                            'obs' => trim($observaciones) === '' ? null : trim($observaciones), 'u' => $actor->id, 'pob' => $poblacion,
                        ]
                    );
                } catch (PDOException $e) {
                    if ($e->getCode() === '23505') {
                        throw new ImportException('DUPLICADO_EXACTO_EN_LOTE');
                    }
                    if ($e->getCode() === '23514' && str_contains($e->getMessage(), 'no están abiertos')) {
                        throw new ImportException('LOTE_NO_ABIERTO');
                    }
                    throw $e;
                }
                $candidate = $this->storage->absolute($relative);
                if (file_exists($candidate)) {
                    throw new ImportException('ALMACENAMIENTO_ERROR');
                }
                $final = $candidate;
                $this->storage->finalize($staged, $relative);
                $jobId = $this->jobs->enqueue($actor, 'VERIFICAR_ARCHIVO', ['archivo_id' => $id], 50, 'verificar:' . $uuid . ':1', 900);
                $this->db->execute('UPDATE archivos_importados SET proceso_id = :p WHERE id = :id', ['p' => $jobId, 'id' => $id]);
                $this->audit->record('archivos.cargar', 'archivos_importados', $id, null, [
                    'lote_id' => $loteId, 'nombre_original' => $safeName, 'extension' => $ext, 'tamano_bytes' => $size,
                    'sha256' => $sha, 'tipo_documento_id' => $tipoId, 'origen_administrativo_id' => $origenId,
                    'clasificacion_duplicidad' => $existing !== null ? 'DUPLICADO_BINARIO' : 'PENDIENTE',
                    'archivo_relacionado_id' => $existing['id'] ?? null, 'tiene_macros' => $inspection['tiene_macros'],
                    'poblacion' => $poblacion,
                ], ['proceso_id' => $jobId]);
                $committing = true;
                return $id;
            });
        } catch (Throwable $e) {
            $this->storage->discard($staged);
            if ($committing) {
                $this->logger->security('Fallo en el COMMIT de una carga: el definitivo se conserva para revisión', ['lote_id' => $loteId]);
            } else {
                $this->storage->discard($final);
            }
            if ($e instanceof ImportException || $e instanceof ValidationException) {
                throw $e;
            }
            $this->logger->error('Fallo al registrar la carga', ['exception' => $e, 'lote_id' => $loteId]);
            throw new ImportException('ALMACENAMIENTO_ERROR');
        }
    }

    public function reclassify(CurrentUser $actor, int $id, int $tipoId, int $origenId): void
    {
        $this->assertClassification($tipoId, $origenId);
        $this->db->transaction(function () use ($id, $tipoId, $origenId): void {
            $a = $this->lock($id);
            if (!in_array($a['estado'], self::PRE_ANALYSIS, true)) {
                throw new ImportException('ESTADO_INVALIDO');
            }
            $this->assertLoteOpen((int) $a['lote_id']);
            if ((int) $a['tipo_documento_id'] === $tipoId && (int) $a['origen_administrativo_id'] === $origenId) {
                return;
            }
            $this->db->execute(
                'UPDATE archivos_importados SET tipo_documento_id = :t, origen_administrativo_id = :o WHERE id = :id',
                ['t' => $tipoId, 'o' => $origenId, 'id' => $id]
            );
            $this->audit->record('archivos.clasificar', 'archivos_importados', $id,
                ['tipo_documento_id' => (int) $a['tipo_documento_id'], 'origen_administrativo_id' => (int) $a['origen_administrativo_id']],
                ['tipo_documento_id' => $tipoId, 'origen_administrativo_id' => $origenId]);
        });
    }

    public function setPopulation(CurrentUser $actor, int $id, string $poblacion): void
    {
        if (!in_array($poblacion, self::POBLACIONES, true)) {
            throw new ValidationException(['poblacion' => 'Población no válida.']);
        }
        $this->db->transaction(function () use ($id, $poblacion): void {
            $a = $this->lock($id);
            if ($a['estado'] === 'ANULADO') {
                throw new ImportException('ESTADO_INVALIDO');
            }
            $this->assertLoteOpen((int) $a['lote_id']);
            if ($a['poblacion'] === $poblacion) {
                return;
            }
            $this->db->execute('UPDATE archivos_importados SET poblacion = :p WHERE id = :id', ['p' => $poblacion, 'id' => $id]);
            $this->audit->record('archivos.clasificar', 'archivos_importados', $id, ['poblacion' => $a['poblacion']], ['poblacion' => $poblacion]);
        });
    }

    public function reprocess(CurrentUser $actor, int $id): int
    {
        return $this->db->transaction(function () use ($actor, $id): int {
            $a = $this->lock($id);
            if ($a['estado'] !== 'ERROR') {
                throw new ImportException('ESTADO_INVALIDO');
            }
            $this->assertLoteOpen((int) $a['lote_id']);
            $attempt = 1 + (int) $this->db->fetchValue(
                "SELECT count(*) FROM auditoria WHERE accion = 'archivos.reprocesar' AND entidad = 'archivos_importados' AND entidad_id = :id",
                ['id' => (string) $id]
            );
            $this->db->execute("UPDATE archivos_importados SET estado = 'RECIBIDO', error_codigo = NULL, error_resumen = NULL WHERE id = :id", ['id' => $id]);
            $jobId = $this->jobs->enqueue($actor, 'VERIFICAR_ARCHIVO', ['archivo_id' => $id], 50, 'verificar:' . $a['uuid'] . ':r' . $attempt, 900);
            $this->db->execute('UPDATE archivos_importados SET proceso_id = :p WHERE id = :id', ['p' => $jobId, 'id' => $id]);
            $this->audit->record('archivos.reprocesar', 'archivos_importados', $id, ['estado' => 'ERROR', 'error_codigo' => $a['error_codigo']],
                ['estado' => 'RECIBIDO'], ['proceso_id' => $jobId]);
            return $jobId;
        });
    }

    public function annul(CurrentUser $actor, int $id, string $motivo): void
    {
        $motivo = PeriodService::requiredReason($motivo);
        $this->db->transaction(function () use ($actor, $id, $motivo): void {
            $a = $this->lock($id);
            if ($a['estado'] === 'ANULADO' || $a['estado'] === 'ANALIZANDO') {
                throw new ImportException('ESTADO_INVALIDO');
            }
            if ($a['estado'] === 'VERIFICANDO' && $a['proceso_id'] !== null && $this->db->fetchValue(
                "SELECT estado FROM procesos WHERE id = :p", ['p' => $a['proceso_id']]) === 'EN_EJECUCION') {
                throw new ImportException('ESTADO_INVALIDO', 'La verificación está en curso; espere a que termine para anular el archivo.');
            }
            if ($a['proceso_id'] !== null) {
                $this->db->execute(
                    "UPDATE procesos SET estado = 'CANCELADO', cancelado_por = :u, finalizado_at = now(), mensaje = 'Archivo anulado'
                      WHERE id = :p AND estado = 'PENDIENTE'",
                    ['u' => $actor->id, 'p' => $a['proceso_id']]
                );
            }
            $this->db->execute(
                "UPDATE archivos_importados SET estado = 'ANULADO', anulado_por = :u, anulado_at = now(), motivo_anulacion = :m WHERE id = :id",
                ['u' => $actor->id, 'm' => $motivo, 'id' => $id]
            );
            $this->audit->record('archivos.anular', 'archivos_importados', $id, ['estado' => $a['estado']], ['estado' => 'ANULADO'], ['motivo' => $motivo]);
        });
    }

    /** @return array{path:string,nombre:string,tamano:int} */
    public function prepareDownload(CurrentUser $actor, int $id, bool $confirmMacros = false): array
    {
        \ConciliaRH\Domain\Security\Throttle::check($this->db, $actor->id, ['archivos.descargar_original', 'archivos.integridad_fallida'], 30, 600,
            'Alcanzó el máximo de descargas de originales en 10 minutos.');
        $a = $this->find($id);
        if ($a['estado'] === 'RECHAZADO') {
            throw new ImportException('ESTADO_INVALIDO', 'Los archivos rechazados por la verificación de seguridad no se descargan.');
        }
        if ($a['tiene_macros'] === true && !$confirmMacros) {
            throw new ImportException('MACROS_REQUIERE_CONFIRMACION');
        }
        if (!$this->storage->verify((string) $a['ruta_relativa'], (int) $a['tamano_bytes'], (string) $a['sha256'])) {
            $this->logger->security('Integridad del original comprometida o archivo ausente', ['archivo_id' => $id]);
            $this->audit->record('archivos.integridad_fallida', 'archivos_importados', $id, null, null, ['operacion' => 'descarga']);
            throw new ImportException('INTEGRIDAD_ERROR');
        }
        $this->audit->record('archivos.descargar_original', 'archivos_importados', $id, null, null, [
            'sha256' => $a['sha256'], 'tamano_bytes' => (int) $a['tamano_bytes'], 'tiene_macros' => $a['tiene_macros'] === true,
        ]);
        return ['path' => $this->storage->absolute((string) $a['ruta_relativa']), 'nombre' => (string) $a['nombre_original'], 'tamano' => (int) $a['tamano_bytes']];
    }

    /** @return list<array{clave:string,titulo:string,estado:string,detalle:string}> */
    public function pipeline(int $id): array
    {
        $f = $this->db->fetchOne('SELECT estado, verificado_at FROM archivos_importados WHERE id = :id', ['id' => $id]) ?? [];
        $e = (string) ($f['estado'] ?? '');
        $verif = ($f['verificado_at'] ?? null) !== null && $e !== 'RECHAZADO';
        $analizado = in_array($e, ['ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS'], true);
        $norm = $analizado ? $this->db->fetchOne(
            "SELECT registros FROM importaciones_normalizadas WHERE archivo_id = :id AND estado = 'VIGENTE' ORDER BY id DESC LIMIT 1", ['id' => $id]) : null;
        $conc = $norm !== null ? (int) $this->db->fetchValue(
            "SELECT count(DISTINCT c.id) FROM conciliacion_archivos ca JOIN conciliaciones c ON c.id = ca.conciliacion_id
              WHERE ca.archivo_id = :id AND c.estado IN ('COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS', 'REVISION_MANUAL', 'CERRADA')", ['id' => $id]) : 0;
        return self::pipelineSteps($e, $verif, $norm !== null ? (int) $norm['registros'] : null, $conc);
    }

    /** @return list<array{clave:string,titulo:string,estado:string,detalle:string}> */
    public static function pipelineSteps(string $e, bool $verif, ?int $registros, int $conc): array
    {
        $anulado = $e === 'ANULADO';
        $verif = $verif && $e !== 'RECHAZADO';   // un archivo rechazado nunca cuenta como verificado
        $analizado = in_array($e, ['ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS'], true);
        $norm = $analizado && $registros !== null ? ['registros' => $registros] : null;
        $conc = $norm !== null ? $conc : 0;
        $step = static fn (string $estado, string $detalle): array => [$estado, $detalle];
        $stop = $step('stopped', 'Detenido: archivo anulado');
        $recibido = $step('done', $anulado ? 'Archivo anulado' : 'Original guardado');
        if ($e === 'RECIBIDO') {
            $recibido = $step('current', 'En espera de verificación');
        }
        $verificado = match (true) {
            $verif => $step('done', 'Firma y contenido válidos'),
            $e === 'RECHAZADO' => $step('error', 'Rechazado'),
            $e === 'ERROR' => $step('error', 'Falló la verificación'),
            $e === 'VERIFICANDO' => $step('current', 'Verificando…'),
            $anulado => $stop,
            default => $step('pending', 'Pendiente'),
        };
        $analisis = match (true) {
            $analizado => $step('done', $e === 'ANALIZADO_CON_ADVERTENCIAS' ? 'Con advertencias' : 'Estructura reconocida'),
            $e === 'REVISION_MANUAL' => $step('warning', 'Elija la hoja o el perfil'),
            $e === 'ERROR' && $verif => $step('error', 'Falló el análisis'),
            $e === 'ANALIZANDO' => $step('current', 'Analizando…'),
            $e === 'VERIFICADO' => $step('current', 'En espera de análisis'),
            $anulado => $stop,
            default => $step('pending', 'Pendiente'),
        };
        $normalizado = match (true) {
            $norm !== null => $step('done', number_format((int) $norm['registros']) . ' registro(s)'),
            $analizado => $step('current', 'Por normalizar'),
            $anulado => $stop,
            default => $step('pending', 'Pendiente'),
        };
        $conciliado = match (true) {
            $conc > 0 => $step('done', 'En ' . $conc . ' cotejo(s)'),
            $norm !== null => $step('current', 'Listo para conciliar el lote'),
            $anulado => $stop,
            default => $step('pending', 'Pendiente'),
        };
        $out = [];
        foreach ([['RECIBIDO', 'Recibido', $recibido], ['VERIFICADO', 'Verificado', $verificado], ['ANALIZADO', 'Analizado', $analisis],
                  ['NORMALIZADO', 'Normalizado', $normalizado], ['CONCILIADO', 'Conciliado', $conciliado]] as [$clave, $titulo, [$estado, $detalle]]) {
            $out[] = ['clave' => $clave, 'titulo' => $titulo, 'estado' => $estado, 'detalle' => $detalle];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public function find(int $id): array
    {
        $a = $this->db->fetchOne(
            'SELECT a.*, t.codigo AS tipo_codigo, t.nombre AS tipo_nombre, o.codigo AS origen_codigo, o.nombre AS origen_nombre,
                    pf.codigo AS perfil_codigo, l.numero AS lote_numero, l.nombre AS lote_nombre, l.estado AS lote_estado,
                    p.id AS periodo_id, p.descripcion AS periodo_descripcion, u.username AS usuario_carga,
                    pr.estado AS proceso_estado, pr.etapa AS proceso_etapa, pr.progreso AS proceso_progreso,
                    rel.nombre_original AS relacionado_nombre, rel.lote_id AS relacionado_lote_id
               FROM archivos_importados a
               JOIN tipos_documento t ON t.id = a.tipo_documento_id
               JOIN origenes_administrativos o ON o.id = a.origen_administrativo_id
               LEFT JOIN perfiles_importacion pf ON pf.id = a.perfil_importacion_id
               JOIN lotes_importacion l ON l.id = a.lote_id
               JOIN periodos_nomina p ON p.id = l.periodo_id
               JOIN usuarios u ON u.id = a.usuario_carga_id
               LEFT JOIN procesos pr ON pr.id = a.proceso_id
               LEFT JOIN archivos_importados rel ON rel.id = a.archivo_relacionado_id
              WHERE a.id = :id',
            ['id' => $id]
        );
        if ($a === null) {
            throw HttpException::notFound();
        }
        return $a;
    }

    /**
     * @param array{periodo?:int|null,lote?:int|null,tipo?:int|null,estado?:string,duplicidad?:string,q?:string} $f
     * @return array{rows:list<array<string,mixed>>,total:int,page:int}
     */
    public function paginate(array $f, int $page, int $perPage): array
    {
        $where = ['TRUE'];
        $p = [];
        if (($f['periodo'] ?? null) !== null) {
            $where[] = 'l.periodo_id = :periodo';
            $p['periodo'] = $f['periodo'];
        }
        if (($f['lote'] ?? null) !== null) {
            $where[] = 'a.lote_id = :lote';
            $p['lote'] = $f['lote'];
        }
        if (($f['tipo'] ?? null) !== null) {
            $where[] = 'a.tipo_documento_id = :tipo';
            $p['tipo'] = $f['tipo'];
        }
        if (in_array($f['estado'] ?? '', ['RECIBIDO', 'VERIFICANDO', 'VERIFICADO', 'RECHAZADO', 'ANALIZANDO', 'ANALIZADO',
                'ANALIZADO_CON_ADVERTENCIAS', 'REVISION_MANUAL', 'ERROR', 'ANULADO'], true)) {
            $where[] = 'a.estado = :estado';
            $p['estado'] = $f['estado'];
        }
        if (in_array($f['duplicidad'] ?? '', self::DUPLICATE_STATES, true)) {
            $where[] = 'a.clasificacion_duplicidad = :dup';
            $p['dup'] = $f['duplicidad'];
        }
        if (($f['q'] ?? '') !== '') {
            $where[] = '(unaccent_simple(a.nombre_original) ILIKE unaccent_simple(:q) OR a.sha256 = lower(:qsha))';
            $p['q'] = '%' . addcslashes($f['q'], '%_\\') . '%';
            $p['qsha'] = $f['q'];
        }
        $sql = implode(' AND ', $where);
        $from = 'FROM archivos_importados a JOIN lotes_importacion l ON l.id = a.lote_id JOIN periodos_nomina pe ON pe.id = l.periodo_id
                 JOIN tipos_documento t ON t.id = a.tipo_documento_id JOIN usuarios u ON u.id = a.usuario_carga_id';
        $total = (int) $this->db->fetchValue("SELECT count(*) $from WHERE $sql", $p);
        $page = min($page, max(1, (int) ceil($total / $perPage)));
        $rows = $this->db->fetchAll(
            "SELECT a.id, a.nombre_original, a.extension, a.tamano_bytes, a.estado, a.clasificacion_duplicidad, a.created_at,
                    a.sha256, t.nombre AS tipo_nombre, l.id AS lote_id, l.numero AS lote_numero, l.nombre AS lote_nombre,
                    pe.id AS periodo_id, pe.descripcion AS periodo_descripcion, u.username AS usuario_carga
               $from WHERE $sql ORDER BY a.created_at DESC, a.id DESC LIMIT :limit OFFSET :offset",
            [...$p, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage]
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page];
    }

    /** @return list<array<string,mixed>> */
    public function activeTypes(): array
    {
        return $this->db->fetchAll('SELECT id, codigo, nombre FROM tipos_documento WHERE activo ORDER BY orden, nombre');
    }

    /** @return list<array<string,mixed>> */
    public function activeOrigins(): array
    {
        return $this->db->fetchAll('SELECT id, codigo, nombre FROM origenes_administrativos WHERE activo ORDER BY orden, nombre');
    }

    public function defaultOriginId(): int
    {
        return (int) $this->db->fetchValue("SELECT id FROM origenes_administrativos WHERE codigo = 'SIN_DEFINIR'");
    }

    /** @return array<string,mixed>|null archivo vigente con el mismo SHA-256 (primero el del mismo lote) */
    private function findExact(string $sha, int $loteId): ?array
    {
        return $this->db->fetchOne(
            "SELECT a.id, a.lote_id, l.nombre AS lote_nombre, p.descripcion AS periodo_descripcion
               FROM archivos_importados a JOIN lotes_importacion l ON l.id = a.lote_id JOIN periodos_nomina p ON p.id = l.periodo_id
              WHERE a.sha256 = :sha AND a.estado <> 'ANULADO' AND l.estado <> 'ANULADO'
              ORDER BY (a.lote_id = :lote) DESC, a.id LIMIT 1",
            ['sha' => $sha, 'lote' => $loteId]
        );
    }

    private function assertLoteOpen(int $loteId): void
    {
        $ok = $this->db->fetchValue(
            "SELECT 1 FROM lotes_importacion l JOIN periodos_nomina p ON p.id = l.periodo_id
              WHERE l.id = :id AND l.estado = 'ABIERTO' AND p.estado = 'ABIERTO'",
            ['id' => $loteId]
        );
        if (!$ok) {
            throw new ImportException('LOTE_NO_ABIERTO');
        }
    }

    private function assertClassification(int $tipoId, int $origenId): void
    {
        $ok = $this->db->fetchValue(
            'SELECT (SELECT activo FROM tipos_documento WHERE id = :t) AND (SELECT activo FROM origenes_administrativos WHERE id = :o)',
            ['t' => $tipoId, 'o' => $origenId]
        );
        if ($ok !== true) {
            throw new ImportException('CLASIFICACION_INVALIDA');
        }
    }

    /** @return array<string,mixed> */
    private function lock(int $id): array
    {
        $a = $this->db->fetchOne('SELECT * FROM archivos_importados WHERE id = :id FOR UPDATE', ['id' => $id]);
        if ($a === null) {
            throw HttpException::notFound();
        }
        return $a;
    }
}
