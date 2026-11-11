<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Lots;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Periods\PeriodService;
use ConciliaRH\Domain\ValidationException;
use PDOException;

/**
 * Relaciones entre lotes del mismo periodo (FASE 4): CORRIGE, SUSTITUYE, COMPLEMENTA, RELACIONADO.
 * Sólo DOCUMENTAN la relación: no mezclan ni ocultan datos (la elección del lote oficial sigue siendo
 * explícita). Se anulan, nunca se borran. Reglas de integridad en BD (fn_lote_relacion_reglas).
 */
final class LotRelationService
{
    public const TYPES = ['CORRIGE' => 'Corrige a', 'SUSTITUYE' => 'Sustituye a', 'COMPLEMENTA' => 'Complementa a', 'RELACIONADO' => 'Relacionado con'];

    public function __construct(private readonly Database $db, private readonly AuditLogger $audit)
    {
    }

    /** Relaciones donde el lote es origen o destino. @return list<array<string,mixed>> */
    public function forLot(int $loteId): array
    {
        return $this->db->fetchAll(
            'SELECT r.*, lo.numero AS origen_numero, lo.nombre AS origen_nombre, ld.numero AS destino_numero, ld.nombre AS destino_nombre,
                    u.username AS creado_por_usuario, ua.username AS anulado_por_usuario
               FROM lotes_relaciones r
               JOIN lotes_importacion lo ON lo.id = r.lote_origen_id JOIN lotes_importacion ld ON ld.id = r.lote_destino_id
               JOIN usuarios u ON u.id = r.creado_por LEFT JOIN usuarios ua ON ua.id = r.anulado_por
              WHERE r.lote_origen_id = :a OR r.lote_destino_id = :b ORDER BY (r.estado = \'ACTIVA\') DESC, r.id DESC',
            ['a' => $loteId, 'b' => $loteId]
        );
    }

    /** Otros lotes vigentes del mismo periodo (candidatos a relacionar). @return list<array<string,mixed>> */
    public function candidates(int $loteId): array
    {
        return $this->db->fetchAll(
            "SELECT l.id, l.numero, l.nombre, l.estado, l.es_oficial FROM lotes_importacion l
              WHERE l.periodo_id = (SELECT periodo_id FROM lotes_importacion WHERE id = :a) AND l.id <> :b AND l.estado <> 'ANULADO'
              ORDER BY l.numero",
            ['a' => $loteId, 'b' => $loteId]
        );
    }

    public function create(CurrentUser $actor, int $origen, int $destino, string $tipo, string $motivo): int
    {
        $motivo = PeriodService::requiredReason($motivo);
        if (!isset(self::TYPES[$tipo])) {
            throw new ValidationException(['tipo' => 'Tipo de relación inválido.']);
        }
        if ($origen === $destino) {
            throw new ValidationException(['destino' => 'Elija un lote distinto.']);
        }
        return $this->db->transaction(function () use ($actor, $origen, $destino, $tipo, $motivo): int {
            $n = (int) $this->db->fetchValue('SELECT count(*) FROM lotes_importacion WHERE id IN (:a, :b)', ['a' => $origen, 'b' => $destino]);
            if ($n !== 2) {
                throw HttpException::notFound();
            }
            try {
                $id = (int) $this->db->fetchValue(
                    'INSERT INTO lotes_relaciones (lote_origen_id, lote_destino_id, tipo, motivo, creado_por) VALUES (:o, :d, :t, :m, :u) RETURNING id',
                    ['o' => $origen, 'd' => $destino, 't' => $tipo, 'm' => $motivo, 'u' => $actor->id]
                );
            } catch (PDOException $e) {
                $state = $e->errorInfo[0] ?? '';
                if ($state === '23505') {
                    throw new ValidationException(['destino' => 'Esa relación ya existe.']);
                }
                if ($state === '23514') {
                    throw new ValidationException(['destino' => 'Sólo se relacionan lotes vigentes del mismo periodo abierto, sin relaciones inversas de corrección/sustitución.']);
                }
                throw $e;
            }
            $this->audit->record('lotes.relacionar', 'lotes_relaciones', $id, null,
                ['lote_origen_id' => $origen, 'lote_destino_id' => $destino, 'tipo' => $tipo], ['motivo' => $motivo]);
            return $id;
        });
    }

    public function annul(CurrentUser $actor, int $loteId, int $relId, string $motivo): void
    {
        $motivo = PeriodService::requiredReason($motivo);
        $this->db->transaction(function () use ($actor, $loteId, $relId, $motivo): void {
            $r = $this->db->fetchOne('SELECT * FROM lotes_relaciones WHERE id = :id AND (lote_origen_id = :a OR lote_destino_id = :b) FOR UPDATE',
                ['id' => $relId, 'a' => $loteId, 'b' => $loteId]);
            if ($r === null) {
                throw HttpException::notFound();
            }
            if ($r['estado'] !== 'ACTIVA') {
                throw new ValidationException(['relacion' => 'La relación ya está anulada.']);
            }
            try {
                $this->db->execute("UPDATE lotes_relaciones SET estado = 'ANULADA', anulado_por = :u, anulado_at = now(), motivo_anulacion = :m WHERE id = :id",
                    ['u' => $actor->id, 'm' => $motivo, 'id' => $relId]);
            } catch (PDOException $e) {
                if (($e->errorInfo[0] ?? '') === '23514') {
                    throw new ValidationException(['relacion' => 'Con el periodo cerrado las relaciones entre lotes no se modifican.']);
                }
                throw $e;
            }
            $this->audit->record('lotes.anular_relacion', 'lotes_relaciones', $relId, ['estado' => 'ACTIVA'], ['estado' => 'ANULADA'],
                ['motivo' => $motivo, 'tipo' => $r['tipo']]);
        });
    }
}
