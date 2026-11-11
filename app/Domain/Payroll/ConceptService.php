<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Payroll;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Periods\PeriodService;
use ConciliaRH\Domain\ValidationException;

/**
 * Catálogo de conceptos de nómina (FASE 4). Las pensiones se identifican por CÓDIGO (044, 060, 171, 249, 263
 * sembrados como CONFIGURADA) y el catálogo es ampliable: un usuario con `conceptos.editar` puede fijar el
 * tipo y la marca de pensión de cualquier código; al hacerlo queda CONFIGURADA (el motor ya no lo cambia).
 */
final class ConceptService
{
    public function __construct(private readonly Database $db, private readonly AuditLogger $audit)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->fetchAll(
            'SELECT k.*, (SELECT count(*) FROM registros_rrhh_conceptos c WHERE c.concepto_id = k.id) AS usos FROM conceptos_nomina k ORDER BY k.codigo'
        );
    }

    public function update(CurrentUser $actor, int $id, string $tipo, bool $esPension, string $motivo): void
    {
        $motivo = PeriodService::requiredReason($motivo);
        if (!in_array($tipo, ['PERCEPCION', 'DEDUCCION', 'DESCONOCIDO'], true)) {
            throw new ValidationException(['tipo' => 'Tipo inválido.']);
        }
        if ($esPension && $tipo !== 'DEDUCCION') {
            throw new ValidationException(['tipo' => 'Un concepto de pensión alimenticia debe ser deducción.']);
        }
        $this->db->transaction(function () use ($actor, $id, $tipo, $esPension, $motivo): void {
            $k = $this->db->fetchOne('SELECT * FROM conceptos_nomina WHERE id = :id FOR UPDATE', ['id' => $id]);
            if ($k === null) {
                throw HttpException::notFound();
            }
            $this->db->execute(
                "UPDATE conceptos_nomina SET tipo = :t, es_pension = :p, clasificacion = 'CONFIGURADA', actualizado_por = :u WHERE id = :id",
                ['t' => $tipo, 'p' => $esPension, 'u' => $actor->id, 'id' => $id]
            );
            $this->audit->record('conceptos.editar', 'conceptos_nomina', $id,
                ['tipo' => $k['tipo'], 'es_pension' => (bool) $k['es_pension'], 'clasificacion' => $k['clasificacion']],
                ['tipo' => $tipo, 'es_pension' => $esPension, 'clasificacion' => 'CONFIGURADA'],
                ['codigo' => $k['codigo'], 'motivo' => $motivo]);
        });
    }
}
