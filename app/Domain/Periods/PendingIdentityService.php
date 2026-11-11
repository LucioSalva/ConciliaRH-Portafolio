<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Periods;

use ConciliaRH\Core\Database;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Identity\BulkReviewService;

final class PendingIdentityService
{
    public const MOTIVO_BLOQUE = 'Confirmación en bloque: nombre idéntico a una sola persona e importe igual a su neto de RH en la quincena';

    private const PENDIENTES = ['SUGERENCIA', 'AMBIGUO', 'CONFLICTO', 'NO_IDENTIFICADO'];

    public function __construct(private readonly Database $db, private readonly BulkReviewService $bulk)
    {
    }

    /** @return list<array<string,mixed>> */
    public function exactMatches(int $loteId): array
    {
        return $this->db->fetchAll(
            "WITH cand AS (
                 SELECT r.id, r.asociacion_version AS version, r.importe, r.nombre_original, r.banco, r.cuenta_ultimos4, r.fila_origen, r.archivo_id,
                        (r.asociacion_evidencia->'candidatos'->>0)::bigint AS empleado_id
                   FROM registros_tesoreria r
                   JOIN importaciones_normalizadas n ON n.id = r.importacion_id AND n.estado = 'VIGENTE'
                  WHERE r.lote_id = :l AND r.categoria = 'NOMINA' AND r.asociacion_estado = 'SUGERENCIA' AND r.importe_estado = 'OK'
                    AND r.asociacion_metodo = 'NOMBRE_EXACTO' AND jsonb_array_length(coalesce(r.asociacion_evidencia->'candidatos', '[]'::jsonb)) = 1
             )
             SELECT c.id, c.version, c.importe, c.nombre_original, c.banco, c.cuenta_ultimos4, c.fila_origen, a.nombre_original AS archivo,
                    e.id AS empleado_id, e.numero_empleado, e.nombre_completo, rr.neto
               FROM cand c
               JOIN empleados e ON e.id = c.empleado_id AND e.fusionado_en_id IS NULL
               JOIN archivos_importados a ON a.id = c.archivo_id
               JOIN LATERAL (SELECT min(x.neto) AS neto, count(*) AS n FROM registros_rrhh x
                              JOIN importaciones_normalizadas xn ON xn.id = x.importacion_id AND xn.estado = 'VIGENTE'
                             WHERE x.lote_id = :l2 AND x.empleado_id = e.id) rr ON rr.n = 1 AND rr.neto = c.importe
              WHERE NOT EXISTS (SELECT 1 FROM registros_tesoreria o JOIN importaciones_normalizadas on2 ON on2.id = o.importacion_id AND on2.estado = 'VIGENTE'
                                 WHERE o.lote_id = :l3 AND o.categoria = 'NOMINA' AND o.empleado_id = e.id
                                   AND o.asociacion_estado IN ('ASOCIADO_AUTOMATICO', 'ASOCIADO_MANUAL'))
                AND NOT EXISTS (SELECT 1 FROM cand c2 WHERE c2.empleado_id = c.empleado_id AND c2.id <> c.id)
              ORDER BY a.nombre_original, c.fila_origen",
            ['l' => $loteId, 'l2' => $loteId, 'l3' => $loteId]
        );
    }

    /** @return array{preview:array<string,mixed>|null,items:list<string>} */
    public function bulkPreview(CurrentUser $user, int $loteId): array
    {
        $rows = $this->exactMatches($loteId);
        if ($rows === [] || !$user->can('asociaciones.masivo')) {
            return ['preview' => null, 'items' => []];
        }
        $p = $this->bulk->preview($user, 'CONFIRMAR_PROPUESTA', array_map(static fn ($r) => $r['id'] . ':' . $r['version'], $rows),
            ['confirmar_cuenta' => true, 'registrar_beneficiario' => false]);
        $items = [];
        foreach ($p['elegibles'] as $id => $e) {
            $items[] = $id . ':' . $e['version'] . ':' . $e['candidato'];
        }
        return ['preview' => $p, 'items' => $items];
    }

    /** @param list<string> $items */
    public function confirmBulk(CurrentUser $user, array $items, string $token, string $motivo): int
    {
        return $this->bulk->execute($user, 'CONFIRMAR_PROPUESTA', $items, ['confirmar_cuenta' => true, 'registrar_beneficiario' => false], $token,
            trim($motivo) !== '' ? $motivo : self::MOTIVO_BLOQUE);
    }

    /** @return list<array<string,mixed>> */
    public function pendingTreasury(int $loteId): array
    {
        return $this->db->fetchAll(
            "SELECT r.id, r.categoria, r.banco, r.importe, r.nombre_original, r.cuenta_ultimos4, r.asociacion_estado, r.fila_origen,
                    a.nombre_original AS archivo, a.poblacion
               FROM registros_tesoreria r
               JOIN importaciones_normalizadas n ON n.id = r.importacion_id AND n.estado = 'VIGENTE'
               JOIN archivos_importados a ON a.id = r.archivo_id
              WHERE r.lote_id = :l AND r.asociacion_estado = ANY(CAST(:e AS text[]))
              ORDER BY r.categoria DESC, a.nombre_original, r.fila_origen",
            ['l' => $loteId, 'e' => '{' . implode(',', self::PENDIENTES) . '}']
        );
    }

    /** @return list<array<string,mixed>> */
    public function pendingRrhh(int $loteId): array
    {
        return $this->db->fetchAll(
            "SELECT r.id, r.numero_empleado, r.nombre_original, r.neto, r.asociacion_estado, r.fila_origen, a.nombre_original AS archivo
               FROM registros_rrhh r
               JOIN importaciones_normalizadas n ON n.id = r.importacion_id AND n.estado = 'VIGENTE'
               JOIN archivos_importados a ON a.id = r.archivo_id
              WHERE r.lote_id = :l AND r.asociacion_estado = ANY(CAST(:e AS text[]))
              ORDER BY a.nombre_original, r.fila_origen",
            ['l' => $loteId, 'e' => '{' . implode(',', self::PENDIENTES) . '}']
        );
    }
}
