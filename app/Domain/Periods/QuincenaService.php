<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Periods;

use ConciliaRH\Core\Database;
use ConciliaRH\Domain\Imports\UploadedFile;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Conciliation\ConciliationService;
use ConciliaRH\Domain\Imports\ImportException;
use ConciliaRH\Domain\Imports\ImportService;
use ConciliaRH\Domain\Lots\LotService;
use ConciliaRH\Domain\ValidationException;

final class QuincenaService
{
    public const POBLACIONES = ['POLICIA' => 'Policías', 'BOMBEROS' => 'Bomberos'];

    public const COLUMNAS = [
        'NOMINA' => ['titulo' => 'Nómina de RH', 'tipo' => 'RRHH_TABULADO', 'origen' => 'RRHH', 'multiple' => false,
                     'ayuda' => 'Tabulado de la quincena (neto que RH autorizó a cada persona).'],
        'PAGOS' => ['titulo' => 'Pagos de nómina', 'tipo' => 'TESORERIA_DISPERSION', 'origen' => 'TESORERIA', 'multiple' => true,
                    'ayuda' => 'Archivos de Tesorería: Santander, BBVA e interbancaria.'],
        'PENSIONES' => ['titulo' => 'Pensiones alimenticias', 'tipo' => 'TESORERIA_PENSION', 'origen' => 'TESORERIA', 'multiple' => true,
                        'ayuda' => 'Dispersión de pensiones: Santander, BBVA e interbancaria.'],
    ];

    public const OPCIONALES = [
        'POLIZA_GLOBAL' => ['titulo' => 'Póliza GLOBAL de RH', 'tipo' => 'RRHH_POLIZA_GLOBAL', 'origen' => 'RRHH'],
        'POLIZA_EGRESO' => ['titulo' => 'Póliza de egreso bancaria', 'tipo' => 'POLIZA_EGRESO_BANCARIA', 'origen' => 'CONTABILIDAD'],
    ];

    private const EN_PROCESO = ['RECIBIDO', 'VERIFICANDO', 'VERIFICADO', 'ANALIZANDO'];
    private const CON_PROBLEMA = ['RECHAZADO', 'ERROR', 'REVISION_MANUAL'];

    public function __construct(
        private readonly Database $db,
        private readonly LotService $lots,
        private readonly ImportService $imports,
        private readonly ConciliationService $conc,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function workingLot(int $periodoId): ?array
    {
        return $this->db->fetchOne(
            "SELECT l.id, l.numero, l.nombre, l.estado, l.es_oficial
               FROM lotes_importacion l
              WHERE l.periodo_id = :p AND l.estado <> 'ANULADO'
              ORDER BY l.es_oficial DESC, (l.estado = 'ABIERTO') DESC, l.numero DESC
              LIMIT 1",
            ['p' => $periodoId]
        );
    }

    /** @return array<string,mixed> */
    public function overview(int $periodoId): array
    {
        $lot = $this->workingLot($periodoId);
        $otros = (int) $this->db->fetchValue("SELECT count(*) FROM lotes_importacion WHERE periodo_id = :p AND estado <> 'ANULADO'", ['p' => $periodoId]);
        $files = $lot !== null ? $this->files((int) $lot['id']) : [];
        $celdas = [];
        foreach (array_keys(self::POBLACIONES) as $pob) {
            foreach (self::COLUMNAS as $col => $def) {
                $celdas[$pob][$col] = [];
            }
        }
        $sinPoblacion = [];
        $opcionales = array_fill_keys(array_keys(self::OPCIONALES), []);
        foreach ($files as $f) {
            $col = $this->columnOf((string) $f['tipo']);
            if ($col === null) {
                $opt = $this->optionalOf((string) $f['tipo']);
                $opcionales[$opt ?? 'POLIZA_EGRESO'][] = $f;
                continue;
            }
            $pob = $f['poblacion'] ?? ($col === 'NOMINA' ? $f['grupo'] : null);
            if (!isset(self::POBLACIONES[$pob ?? ''])) {
                $sinPoblacion[] = $f;
                continue;
            }
            $celdas[$pob][$col][] = $f;
        }
        $faltan = [];
        foreach ($celdas as $pob => $cols) {
            foreach ($cols as $col => $list) {
                if ($col !== 'PENSIONES' && array_filter($list, static fn ($f) => !in_array($f['estado'], self::CON_PROBLEMA, true)) === []) {
                    $faltan[] = self::COLUMNAS[$col]['titulo'] . ' de ' . self::POBLACIONES[$pob];
                }
            }
        }
        $enProceso = count(array_filter($files, static fn ($f) => in_array($f['estado'], self::EN_PROCESO, true)
            || ($f['normalizacion'] === null && in_array($f['estado'], ['ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS'], true) && $f['tipo'] !== 'OTRO')));
        $problemas = count(array_filter($files, static fn ($f) => in_array($f['estado'], self::CON_PROBLEMA, true)));
        $conciliacion = $lot !== null ? $this->db->fetchOne(
            "SELECT c.id, c.numero, c.estado, c.created_at, c.terminada_at, c.totales, c.resumen, c.lote_oficial, c.tolerancia
               FROM conciliaciones c WHERE c.lote_id = :l ORDER BY c.numero DESC LIMIT 1",
            ['l' => (int) $lot['id']]
        ) : null;
        $pendientes = $lot !== null ? (int) $this->db->fetchValue(
            "SELECT count(*) FROM registros_tesoreria r JOIN importaciones_normalizadas n ON n.id = r.importacion_id AND n.estado = 'VIGENTE'
              WHERE r.lote_id = :l AND r.categoria = 'NOMINA' AND r.asociacion_estado IN ('SUGERENCIA', 'AMBIGUO', 'CONFLICTO', 'NO_IDENTIFICADO')",
            ['l' => (int) $lot['id']]
        ) : 0;
        $ultimaCarga = $files !== [] ? max(array_map(static fn ($f) => (string) $f['actualizado'], $files)) : null;
        return [
            'lot' => $lot,
            'lotes' => $otros,
            'celdas' => $celdas,
            'sin_poblacion' => $sinPoblacion,
            'opcionales' => $opcionales,
            'faltan' => $faltan,
            'en_proceso' => $enProceso,
            'problemas' => $problemas,
            'listo' => $lot !== null && $faltan === [] && $enProceso === 0 && $sinPoblacion === [],
            'conciliacion' => $conciliacion,
            'desactualizada' => $conciliacion !== null && $ultimaCarga !== null && strtotime($ultimaCarga) > strtotime((string) $conciliacion['created_at']),
            'archivos' => count($files),
            'pendientes' => $pendientes,
        ];
    }

    /**
     * @param list<UploadedFile> $files
     * @return array{cargados:int,errores:list<array{archivo:string,error:ImportException|ValidationException}>}
     */
    public function upload(CurrentUser $actor, int $periodoId, string $columna, ?string $poblacion, array $files, bool $confirmDuplicate): array
    {
        $def = self::COLUMNAS[$columna] ?? self::OPCIONALES[$columna] ?? null;
        if ($def === null) {
            throw new ValidationException(['columna' => 'Espacio de carga no válido.']);
        }
        if (isset(self::COLUMNAS[$columna]) && !isset(self::POBLACIONES[$poblacion ?? ''])) {
            throw new ValidationException(['poblacion' => 'Indique si los archivos son de Policías o de Bomberos.']);
        }
        if (isset(self::OPCIONALES[$columna]) && $poblacion !== null && !isset(self::POBLACIONES[$poblacion])) {
            throw new ValidationException(['poblacion' => 'Población no válida.']);
        }
        if ($files === []) {
            throw new ImportException('UPLOAD_SIN_ARCHIVO');
        }
        if (count($files) > 10) {
            throw new ValidationException(['archivos' => 'Cargue como máximo 10 archivos a la vez.']);
        }
        $tipo = $this->catalogId('tipos_documento', (string) $def['tipo']);
        $origen = $this->catalogId('origenes_administrativos', (string) $def['origen']);
        $lot = $this->workingLot($periodoId);
        if ($lot === null) {
            $loteId = $this->lots->create($actor, $periodoId, 'Carga de la quincena', '', 'Pantalla de la quincena');
        } elseif ($lot['estado'] !== 'ABIERTO') {
            throw new ImportException('LOTE_NO_ABIERTO');
        } else {
            $loteId = (int) $lot['id'];
        }
        $ok = 0;
        $errores = [];
        foreach ($files as $file) {
            try {
                $this->imports->upload($actor, $loteId, $tipo, $origen, '', $file, $confirmDuplicate, $poblacion);
                $ok++;
            } catch (ImportException | ValidationException $e) {
                $errores[] = ['archivo' => $file->clientName, 'error' => $e];
            }
        }
        return ['cargados' => $ok, 'errores' => $errores];
    }

    public function cotejar(CurrentUser $actor, int $periodoId, string $motivo): int
    {
        $lot = $this->workingLot($periodoId);
        if ($lot === null) {
            throw new ValidationException(['lote_id' => 'Cargue primero los archivos de la quincena.']);
        }
        $o = $this->overview($periodoId);
        if ($o['faltan'] !== []) {
            throw new ValidationException(['lote_id' => 'Faltan archivos: ' . implode(', ', $o['faltan']) . '.']);
        }
        if ($o['sin_poblacion'] !== []) {
            throw new ValidationException(['lote_id' => 'Indique a qué población pertenece cada archivo antes de cotejar.']);
        }
        return $this->conc->request($actor, (int) $lot['id'], $motivo);
    }

    /** @return list<array<string,mixed>> */
    public function files(int $loteId): array
    {
        return $this->db->fetchAll(
            "SELECT a.id, a.nombre_original, a.estado, a.poblacion, a.clasificacion_duplicidad, a.error_codigo, a.error_resumen,
                    greatest(a.updated_at, coalesce(n.updated_at, a.updated_at)) AS actualizado, t.codigo AS tipo,
                    n.id AS normalizacion, n.grupo, n.registros, n.metricas->>'banco' AS banco, n.metricas->>'categoria' AS categoria,
                    CASE WHEN n.tipo_registro = 'RRHH' THEN (SELECT sum(r.neto) FROM registros_rrhh r WHERE r.importacion_id = n.id)
                         WHEN n.tipo_registro = 'TESORERIA' THEN (SELECT sum(r.importe) FROM registros_tesoreria r WHERE r.importacion_id = n.id
                                                                    AND r.importe_estado = 'OK') END AS total,
                    CASE WHEN n.tipo_registro = 'TESORERIA' THEN (SELECT count(*) FROM registros_tesoreria r WHERE r.importacion_id = n.id
                                                                    AND r.asociacion_estado NOT IN ('ASOCIADO_AUTOMATICO', 'ASOCIADO_MANUAL')) END AS sin_identificar
               FROM archivos_importados a
               JOIN tipos_documento t ON t.id = a.tipo_documento_id
               LEFT JOIN importaciones_normalizadas n ON n.archivo_id = a.id AND n.estado = 'VIGENTE'
              WHERE a.lote_id = :l AND a.estado <> 'ANULADO'
              ORDER BY a.id",
            ['l' => $loteId]
        );
    }

    private function columnOf(string $tipo): ?string
    {
        foreach (self::COLUMNAS as $k => $d) {
            if ($d['tipo'] === $tipo) {
                return $k;
            }
        }
        return null;
    }

    private function optionalOf(string $tipo): ?string
    {
        foreach (self::OPCIONALES as $k => $d) {
            if ($d['tipo'] === $tipo) {
                return $k;
            }
        }
        return null;
    }

    private function catalogId(string $table, string $codigo): int
    {
        $id = $this->db->fetchValue("SELECT id FROM $table WHERE codigo = :c AND activo", ['c' => $codigo]);
        if ($id === null || $id === false) {
            throw new ValidationException(['columna' => 'El catálogo de tipos de archivo no tiene «' . $codigo . '» activo.']);
        }
        return (int) $id;
    }
}
