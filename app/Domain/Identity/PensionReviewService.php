<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Identity;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Periods\PeriodService;
use ConciliaRH\Domain\ValidationException;
use PDOException;

/**
 * Revisión especializada de pensiones con evidencia MEDIA (FASE 8, /revision-pensiones; docs/BENEFICIARIES.md §5b).
 *
 * Son pagos de pensión sin trabajador (típicamente interbancarios) que el worker sugirió con DOS señales coincidentes. Nada se
 * confirma solo: cada caso lo decide una persona, uno por uno, con la evidencia y la historia de la misma CLABE a la vista.
 *  - CONFIRMAR = asociación manual al trabajador candidato + registro del beneficiario (fn_confirmar_beneficiario), que aprende la CLABE.
 *  - RECHAZAR = el candidato no se vuelve a proponer para este registro.
 *  - DEJAR PENDIENTE = decisión explícita, con motivo, que no cambia la asociación (revision_pendientes).
 */
final class PensionReviewService
{
    public const SENALES = [
        'BENEFICIARIO_CONOCIDO' => 'El nombre coincide exactamente con un beneficiario ya registrado de ese trabajador',
        'IMPORTE_RETENCION_UNICA' => 'El importe es exactamente el faltante de pensión de un solo trabajador del lote',
        'CLABE_ASOCIADA_OTRO_PERIODO' => 'En otro periodo esa CLABE se asoció a este trabajador',
        'MISMO_NOMBRE_CON_ESA_CLABE' => 'En otro periodo la misma CLABE llegó con el mismo nombre de beneficiario',
        'IMPORTE_RECURRENTE_MISMA_CLABE' => 'En otro periodo la misma CLABE, con el mismo importe, apuntaba al mismo trabajador',
        'CLABE_CON_TRABAJADORES_DISTINTOS' => 'La misma CLABE se asoció a trabajadores distintos en otros periodos',
    ];
    public const MOTIVOS = [
        'BENEFICIARIO_E_IMPORTE' => 'Beneficiario conocido y faltante único coinciden en el mismo trabajador',
        'CLABE_Y_NOMBRE_HISTORICOS' => 'La CLABE ya se asoció a este trabajador y el nombre coincide con periodos anteriores',
        'IMPORTE_RECURRENTE_MISMA_CLABE' => 'Importe recurrente en la misma CLABE hacia el mismo trabajador',
    ];
    private const WHERE = "r.categoria = 'PENSION' AND r.asociacion_estado = 'SUGERENCIA' AND r.asociacion_evidencia->>'categoria' = 'MEDIA'";
    private const JOINS = "JOIN importaciones_normalizadas n ON n.id = r.importacion_id AND n.estado = 'VIGENTE'
               JOIN periodos_nomina pe ON pe.id = r.periodo_id AND pe.estado = 'ABIERTO'
               JOIN lotes_importacion l ON l.id = r.lote_id AND l.estado <> 'ANULADO'";

    public function __construct(private readonly Database $db, private readonly AuditLogger $audit, private readonly AssociationService $assoc)
    {
    }

    /** Casos por revisar (dinámico: jamás una cifra fija). */
    public function count(): int
    {
        return (int) $this->db->fetchValue('SELECT count(*) FROM registros_tesoreria r ' . self::JOINS . ' WHERE ' . self::WHERE);
    }

    /**
     * @param array{q:string,banco:string,revision:string} $f
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,importe_total:string}
     */
    public function search(array $f, int $page, int $perPage): array
    {
        $where = [self::WHERE];
        $p = [];
        if (in_array($f['banco'], ['SANTANDER', 'BBVA', 'INTERBANCARIA', 'OTROS'], true)) {
            $where[] = 'r.banco = :banco';
            $p['banco'] = $f['banco'];
        }
        if ($f['q'] !== '') {
            $where[] = "r.nombre_normalizado LIKE :q ESCAPE '!'";
            $p['q'] = '%' . strtr(mb_strtoupper(trim($f['q'])), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        $ultima = '(SELECT rp.created_at FROM revision_pendientes rp WHERE rp.registro_tesoreria_id = r.id AND rp.asociacion_version = r.asociacion_version
                    ORDER BY rp.id DESC LIMIT 1)';
        if ($f['revision'] === 'sin_revisar') {
            $where[] = "$ultima IS NULL";
        } elseif ($f['revision'] === 'pendientes') {
            $where[] = "$ultima IS NOT NULL";
        }
        $w = implode(' AND ', $where);
        $agg = $this->db->fetchOne('SELECT count(*) AS n, coalesce(sum(r.importe), 0) AS s FROM registros_tesoreria r ' . self::JOINS . " WHERE $w", $p);
        $total = (int) $agg['n'];
        $page = min(max(1, $page), max(1, (int) ceil($total / $perPage)));
        $rows = $this->db->fetchAll(
            "SELECT r.id, r.asociacion_version AS version, r.banco, r.nombre_original, r.cuenta_ultimos4, r.importe, r.asociacion_evidencia,
                    r.periodo_id, pe.descripcion AS periodo, l.numero AS lote_numero, $ultima AS dejado_pendiente_at
               FROM registros_tesoreria r " . self::JOINS . " WHERE $w
              ORDER BY ($ultima IS NOT NULL), r.importe DESC NULLS LAST, r.id LIMIT :lim OFFSET :off",
            $p + ['lim' => $perPage, 'off' => ($page - 1) * $perPage]);
        $this->decorate($rows);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'importe_total' => (string) $agg['s']];
    }

    /** @return array<string,mixed> caso + candidato + beneficiario + línea de tiempo de la misma CLABE + decisiones previas */
    public function find(int $id): array
    {
        $rows = $this->db->fetchAll(
            "SELECT r.id, r.asociacion_version AS version, r.asociacion_estado, r.banco, r.banco_receptor, r.nombre_original, r.cuenta_ultimos4,
                    r.tipo_cuenta, r.importe, r.asociacion_evidencia, r.periodo_id, r.lote_id, r.archivo_id, r.fila_origen, r.categoria,
                    pe.descripcion AS periodo, l.numero AS lote_numero, NULL AS dejado_pendiente_at
               FROM registros_tesoreria r " . self::JOINS . ' WHERE r.id = :id', ['id' => $id]);
        if ($rows === [] || $rows[0]['categoria'] !== 'PENSION') {
            throw HttpException::notFound();
        }
        $this->decorate($rows);
        $r = $rows[0];
        $r['es_media'] = $r['asociacion_estado'] === 'SUGERENCIA' && ($r['asociacion_evidencia']['categoria'] ?? '') === 'MEDIA';
        $r['historial'] = $this->db->fetchAll('SELECT * FROM fn_historial_clabe(:id)', ['id' => $id]);
        $r['decisiones'] = $this->db->fetchAll(
            'SELECT rp.motivo, rp.created_at, u.username FROM revision_pendientes rp JOIN usuarios u ON u.id = rp.usuario_id
              WHERE rp.registro_tesoreria_id = :id ORDER BY rp.id DESC', ['id' => $id]);
        return $r;
    }

    public function confirm(CurrentUser $u, int $id, int $version, string $motivo): void
    {
        if (!$u->can('asociaciones.resolver') || !$u->can('beneficiarios.confirmar') || !$u->can('cuentas.confirmar')) {
            throw HttpException::forbidden();
        }
        $motivo = PeriodService::requiredReason($motivo);
        try {
            $this->db->transaction(function () use ($u, $id, $version, $motivo): void {
                // Mismo orden de candados que la revisión masiva y el worker: el global primero
                $this->db->fetchValue('SELECT pg_advisory_xact_lock(4850181374600825153)');
                $r = $this->caseForDecision($id, $version);
                $cand = $r['candidatos'][0];
                $this->assoc->associate($u, 'TESORERIA', $id, $cand, $version, $motivo, false);
                $benef = (int) $this->db->fetchValue('SELECT fn_confirmar_beneficiario(:r, :u, :b, :m, NULL)',
                    ['r' => $id, 'u' => $u->id, 'b' => $r['asociacion_evidencia']['beneficiario_candidato'] ?? null, 'm' => $motivo]);
                $this->audit->record('pensiones.revision_confirmar', 'registros_tesoreria', $id, ['estado' => 'SUGERENCIA'], ['estado' => 'ASOCIADO_MANUAL'],
                    ['motivo' => $motivo, 'empleado_id' => (string) $cand, 'beneficiario_id' => $benef, 'categoria' => 'MEDIA',
                     'senales' => $r['asociacion_evidencia']['senales'] ?? []]);
            });
        } catch (PDOException $e) {
            throw $this->mapError($e);
        }
    }

    public function reject(CurrentUser $u, int $id, int $version, string $motivo): void
    {
        if (!$u->can('asociaciones.resolver')) {
            throw HttpException::forbidden();
        }
        try {
            $this->db->transaction(function () use ($u, $id, $version, $motivo): void {
                $r = $this->caseForDecision($id, $version);
                $this->assoc->reject($u, 'TESORERIA', $id, $r['candidatos'][0], $version, $motivo);
            });
        } catch (PDOException $e) {
            throw $this->mapError($e);
        }
    }

    public function leavePending(CurrentUser $u, int $id, int $version, string $motivo): void
    {
        if (!$u->can('asociaciones.resolver')) {
            throw HttpException::forbidden();
        }
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 5 || mb_strlen($motivo) > 500) {
            throw new ValidationException(['motivo' => 'Explique por qué queda pendiente (5 a 500 caracteres).']);
        }
        $this->db->transaction(function () use ($u, $id, $version, $motivo): void {
            $this->caseForDecision($id, $version);
            $this->db->fetchValue('SELECT fn_revision_dejar_pendiente(:r, :v, :u, :m)', ['r' => $id, 'v' => $version, 'u' => $u->id, 'm' => $motivo]);
            $this->audit->record('pensiones.revision_pendiente', 'registros_tesoreria', $id, null, null, ['motivo' => $motivo, 'version' => $version]);
        });
    }

    /** El caso debe seguir siendo una sugerencia MEDIA de pensión con UN candidato y la misma versión que vio la persona. @return array<string,mixed> */
    private function caseForDecision(int $id, int $version): array
    {
        $rows = $this->db->fetchAll('SELECT r.id, r.asociacion_version, r.asociacion_evidencia FROM registros_tesoreria r ' . self::JOINS
            . ' WHERE r.id = :id AND ' . self::WHERE . ' FOR UPDATE OF r', ['id' => $id]);
        if ($rows === []) {
            if ($this->db->fetchValue("SELECT 1 FROM registros_tesoreria WHERE id = :id AND categoria = 'PENSION'", ['id' => $id]) === null) {
                throw HttpException::notFound();
            }
            throw new ValidationException(['registro' => 'Este caso ya no es una sugerencia MEDIA pendiente (alguien lo decidió o el motor lo re-evaluó).']);
        }
        $r = $rows[0];
        if ((int) $r['asociacion_version'] !== $version) {
            throw new ValidationException(['registro' => 'REGISTRO_MODIFICADO: el caso cambió desde que lo abrió. Recargue y revise de nuevo.']);
        }
        $r['asociacion_evidencia'] = AssociationService::json($r['asociacion_evidencia']);
        $r['candidatos'] = array_values(array_map('intval', (array) ($r['asociacion_evidencia']['candidatos'] ?? [])));
        if (count($r['candidatos']) !== 1) {
            throw new ValidationException(['registro' => 'El caso no tiene un único trabajador candidato: decídalo desde Asociaciones.']);
        }
        return $r;
    }

    /** @param list<array<string,mixed>> $rows */
    private function decorate(array &$rows): void
    {
        $emp = [];
        $ben = [];
        foreach ($rows as &$r) {
            $r['asociacion_evidencia'] = AssociationService::json($r['asociacion_evidencia']);
            $r['candidatos'] = array_values(array_map('intval', (array) ($r['asociacion_evidencia']['candidatos'] ?? [])));
            $emp = [...$emp, ...$r['candidatos']];
            if (isset($r['asociacion_evidencia']['beneficiario_candidato'])) {
                $ben[] = (int) $r['asociacion_evidencia']['beneficiario_candidato'];
            }
        }
        unset($r);
        $emps = $emp === [] ? [] : array_column($this->db->fetchAll(
            'SELECT id, numero_empleado, nombre_completo, grupo FROM empleados WHERE id = ANY(CAST(:ids AS bigint[]))',
            ['ids' => '{' . implode(',', array_unique($emp)) . '}']), null, 'id');
        $bens = $ben === [] ? [] : array_column($this->db->fetchAll(
            'SELECT id, nombre_original FROM beneficiarios_pension WHERE id = ANY(CAST(:ids AS bigint[]))',
            ['ids' => '{' . implode(',', array_unique($ben)) . '}']), 'nombre_original', 'id');
        foreach ($rows as &$r) {
            $ev = $r['asociacion_evidencia'];
            $r['candidato'] = $emps[$r['candidatos'][0] ?? 0] ?? null;
            $r['beneficiario_nombre'] = isset($ev['beneficiario_candidato']) ? ($bens[(int) $ev['beneficiario_candidato']] ?? null) : null;
            $r['motivo_texto'] = self::MOTIVOS[$ev['motivo'] ?? ''] ?? (string) ($ev['motivo'] ?? '');
            $r['senales_texto'] = array_values(array_map(static fn ($s) => ['codigo' => $s, 'texto' => self::SENALES[$s] ?? $s], (array) ($ev['senales'] ?? [])));
            $r['periodos_misma_clabe'] = (int) ($ev['detalle_senales']['clabe_otros_periodos'] ?? 0);
        }
    }

    private function mapError(PDOException $e): \Throwable
    {
        return match ($e->errorInfo[0] ?? '') {
            '42501' => HttpException::forbidden(),
            '23505' => new ValidationException(['registro' => 'Esa CLABE ya está registrada para otra persona: revise el caso en Beneficiarios.']),
            '23514', '22023' => new ValidationException(['registro' => 'El caso no puede confirmarse en su estado actual (periodo cerrado, lote anulado o importación no vigente).']),
            '40001', '40P01', '55P03' => new ValidationException(['registro' => 'Otra operación estaba modificando este caso. Recargue e intente de nuevo.']),
            default => $e,
        };
    }
}
