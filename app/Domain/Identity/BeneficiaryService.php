<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Identity;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Periods\PeriodService;
use ConciliaRH\Domain\ValidationException;

/**
 * Beneficiarios de pensión (FASE 6, docs/BENEFICIARIES.md). Consulta y decisiones correctivas; nunca se infiere
 * parentesco ni se inventan RFC/CURP: los archivos no los traen. Cuentas siempre enmascaradas salvo "revelar".
 */
final class BeneficiaryService
{
    public const TABS = ['resumen', 'trabajadores', 'cuentas', 'pensiones', 'historial', 'auditoria'];

    public function __construct(
        private readonly Database $db,
        private readonly AuditLogger $audit,
        private readonly EmployeeService $employees,
    ) {
    }

    /**
     * @param array{q:string,estado:string,origen:string,banco:string} $f
     * @return array{rows:list<array<string,mixed>>,total:int,page:int}
     */
    public function paginate(array $f, int $page, int $perPage): array
    {
        $w = ['true'];
        $p = [];
        if (in_array($f['estado'], ['ACTIVO', 'INACTIVO'], true)) {
            $w[] = 'b.estado = :estado';
            $p['estado'] = $f['estado'];
        }
        if (in_array($f['origen'], ['EVIDENCIA_FUERTE', 'CONFIRMACION_MANUAL'], true)) {
            $w[] = 'b.origen = :origen';
            $p['origen'] = $f['origen'];
        }
        if (in_array($f['banco'], ['SANTANDER', 'BBVA', 'INTERBANCARIA'], true)) {
            $w[] = 'EXISTS (SELECT 1 FROM registros_tesoreria r WHERE r.beneficiario_id = b.id AND r.banco = :banco)';
            $p['banco'] = $f['banco'];
        }
        $q = trim($f['q']);
        if ($q !== '') {
            $n = EmployeeService::normalizeId($q);
            if (preg_match('/^\d{1,20}$/', $n)) {
                // Número del TRABAJADOR relacionado (el beneficiario no tiene número propio)
                $w[] = "EXISTS (SELECT 1 FROM beneficiario_trabajadores bt JOIN empleados e ON e.id = bt.empleado_id
                                 WHERE bt.beneficiario_id = b.id AND e.numero_empleado LIKE :num ESCAPE '!')";
                $p['num'] = EmployeeService::likeEscape($n) . '%';
            } else {
                $key = EmployeeService::nameKey($q);
                if (mb_strlen($key) < 3) {
                    throw new ValidationException(['q' => 'Escriba al menos 3 letras del nombre o el número del trabajador.']);
                }
                foreach (array_slice(array_filter(explode(' ', $key)), 0, 5) as $i => $t) {
                    $w[] = "b.nombre_normalizado LIKE :t$i ESCAPE '!'";
                    $p["t$i"] = '%' . EmployeeService::likeEscape($t) . '%';
                }
            }
        }
        $where = implode(' AND ', $w);
        $total = (int) $this->db->fetchValue("SELECT count(*) FROM beneficiarios_pension b WHERE $where", $p);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $rows = $this->db->fetchAll(
            "SELECT b.id, b.nombre_original, b.estado, b.origen, pe.descripcion AS ultima_aparicion,
                    (SELECT string_agg(e.numero_empleado || ' ' || e.nombre_completo, ' · ' ORDER BY e.numero_empleado)
                       FROM beneficiario_trabajadores bt JOIN empleados e ON e.id = bt.empleado_id
                      WHERE bt.beneficiario_id = b.id AND bt.estado = 'ACTIVA') AS trabajadores,
                    (SELECT c.cuenta_ultimos4 FROM cuentas_bancarias c WHERE c.beneficiario_id = b.id AND c.estado = 'ACTIVA' ORDER BY c.id DESC LIMIT 1) AS ultimos4,
                    (SELECT string_agg(DISTINCT r.banco, ', ') FROM registros_tesoreria r WHERE r.beneficiario_id = b.id) AS bancos
               FROM beneficiarios_pension b JOIN periodos_nomina pe ON pe.id = b.ultimo_periodo_id
              WHERE $where ORDER BY b.nombre_normalizado, b.id LIMIT :lim OFFSET :off",
            $p + ['lim' => $perPage, 'off' => ($page - 1) * $perPage]);
        return ['rows' => $rows, 'total' => $total, 'page' => $page];
    }

    /** @return array<string,mixed> */
    public function find(int $id, string $tab): array
    {
        $b = $this->db->fetchOne(
            'SELECT b.*, p1.descripcion AS primer_periodo, p2.descripcion AS ultimo_periodo, u.username AS confirmado_por_usuario
               FROM beneficiarios_pension b JOIN periodos_nomina p1 ON p1.id = b.primer_periodo_id JOIN periodos_nomina p2 ON p2.id = b.ultimo_periodo_id
               LEFT JOIN usuarios u ON u.id = b.confirmado_por WHERE b.id = :id', ['id' => $id]);
        if ($b === null) {
            throw HttpException::notFound();
        }
        $b['trabajadores'] = $this->db->fetchAll(
            'SELECT bt.*, e.numero_empleado, e.nombre_completo, e.grupo, pd.descripcion AS desde, ph.descripcion AS hasta,
                    uc.username AS confirmado_por_usuario, ud.username AS desactivado_por_usuario
               FROM beneficiario_trabajadores bt JOIN empleados e ON e.id = bt.empleado_id
               JOIN periodos_nomina pd ON pd.id = bt.vigente_desde_periodo_id LEFT JOIN periodos_nomina ph ON ph.id = bt.vigente_hasta_periodo_id
               LEFT JOIN usuarios uc ON uc.id = bt.confirmado_por LEFT JOIN usuarios ud ON ud.id = bt.desactivado_por
              WHERE bt.beneficiario_id = :id ORDER BY (bt.estado = \'ACTIVA\') DESC, bt.id', ['id' => $id]);
        foreach ($b['trabajadores'] as &$t) {
            $t['evidencia'] = AssociationService::json($t['evidencia']);
        }
        unset($t);
        $b['cuentas'] = $this->db->fetchAll(
            'SELECT c.id, c.tipo, c.cuenta_ultimos4, c.banco, c.origen, c.estado, c.confirmado_at, c.veces_vista, c.desactivacion_motivo,
                    p1.descripcion AS primer_periodo, p2.descripcion AS ultimo_periodo, u.username AS confirmado_por_usuario
               FROM cuentas_bancarias c JOIN periodos_nomina p1 ON p1.id = c.primer_periodo_id JOIN periodos_nomina p2 ON p2.id = c.ultimo_periodo_id
               LEFT JOIN usuarios u ON u.id = c.confirmado_por
              WHERE c.beneficiario_id = :id ORDER BY (c.estado = \'ACTIVA\') DESC, c.id DESC', ['id' => $id]);
        $b['n_pensiones'] = (int) $this->db->fetchValue('SELECT count(*) FROM registros_tesoreria WHERE beneficiario_id = :id', ['id' => $id]);
        if ($tab === 'pensiones') {
            $b['pensiones'] = $this->db->fetchAll(
                "SELECT r.id, r.banco, r.importe, r.asociacion_estado, r.asociacion_metodo, r.cuenta_ultimos4, r.fila_origen, r.archivo_id,
                        pe.descripcion AS periodo, l.numero AS lote_numero, n.estado AS importacion_estado, e.numero_empleado, e.nombre_completo
                   FROM registros_tesoreria r JOIN periodos_nomina pe ON pe.id = r.periodo_id JOIN lotes_importacion l ON l.id = r.lote_id
                   JOIN importaciones_normalizadas n ON n.id = r.importacion_id LEFT JOIN empleados e ON e.id = r.empleado_id
                  WHERE r.beneficiario_id = :id ORDER BY pe.fecha_fin DESC, r.id DESC LIMIT 200", ['id' => $id]);
        }
        if ($tab === 'historial') {
            $b['historial'] = $this->db->fetchAll(
                "SELECT h.*, u.username FROM asociaciones_historial h LEFT JOIN usuarios u ON u.id = h.usuario_id
                  WHERE h.registro_tipo = 'TESORERIA' AND h.registro_id IN (SELECT r.id FROM registros_tesoreria r WHERE r.beneficiario_id = :id)
                  ORDER BY h.id DESC LIMIT 200", ['id' => $id]);
        }
        if ($tab === 'auditoria') {
            $b['auditoria'] = $this->db->fetchAll(
                "SELECT a.id, a.accion, a.username, a.creado_at, a.entidad FROM auditoria a
                  WHERE (a.entidad = 'beneficiarios_pension' AND a.entidad_id = :id)
                     OR (a.entidad = 'beneficiario_trabajadores' AND a.entidad_id IN (SELECT bt.id::text FROM beneficiario_trabajadores bt WHERE bt.beneficiario_id = :id2))
                     OR (a.entidad = 'cuentas_bancarias' AND a.entidad_id IN (SELECT c.id::text FROM cuentas_bancarias c WHERE c.beneficiario_id = :id3))
                  ORDER BY a.id DESC LIMIT 100", ['id' => (string) $id, 'id2' => $id, 'id3' => $id]);
        }
        return $b;
    }

    public function updateNotes(CurrentUser $actor, int $id, string $notas): void
    {
        $notas = trim($notas);
        if (mb_strlen($notas) > 1000) {
            throw new ValidationException(['observaciones' => 'Máximo 1000 caracteres.']);
        }
        $this->db->transaction(function () use ($actor, $id, $notas): void {
            $b = $this->db->fetchOne('SELECT id, observaciones FROM beneficiarios_pension WHERE id = :id FOR UPDATE', ['id' => $id]);
            if ($b === null) {
                throw HttpException::notFound();
            }
            $this->db->execute('UPDATE beneficiarios_pension SET observaciones = :o, actualizado_por = :u WHERE id = :id',
                ['o' => $notas === '' ? null : $notas, 'u' => $actor->id, 'id' => $id]);
            $this->audit->record('beneficiarios.observaciones', 'beneficiarios_pension', $id,
                ['longitud' => mb_strlen((string) $b['observaciones'])], ['longitud' => mb_strlen($notas)], null);
        });
    }

    /** Decisión correctiva: la relación queda INACTIVA (con autor y motivo); nunca se borra ni se reactiva. */
    public function deactivateRelation(CurrentUser $actor, int $id, int $relId, string $motivo): void
    {
        $motivo = PeriodService::requiredReason($motivo);
        $this->db->transaction(function () use ($actor, $id, $relId, $motivo): void {
            $r = $this->db->fetchOne('SELECT id, estado, empleado_id FROM beneficiario_trabajadores WHERE id = :r AND beneficiario_id = :b FOR UPDATE',
                ['r' => $relId, 'b' => $id]);
            if ($r === null) {
                throw HttpException::notFound();
            }
            if ($r['estado'] !== 'ACTIVA') {
                throw new ValidationException(['relacion' => 'La relación ya está inactiva.']);
            }
            $this->db->execute("UPDATE beneficiario_trabajadores SET estado = 'INACTIVA', desactivado_por = :u, desactivado_at = now(),
                                       motivo_desactivacion = :m,
                                       vigente_hasta_periodo_id = (SELECT ultimo_periodo_id FROM beneficiarios_pension WHERE id = :b)
                                 WHERE id = :id", ['u' => $actor->id, 'm' => $motivo, 'b' => $id, 'id' => $relId]);
            // La CLABE ya no puede asociar automáticamente a ese trabajador (se conserva en el historial como INACTIVA)
            $ctas = $this->db->fetchAll("SELECT id FROM cuentas_bancarias WHERE beneficiario_id = :b AND empleado_id = :e AND estado = 'ACTIVA' FOR UPDATE",
                ['b' => $id, 'e' => $r['empleado_id']]);
            foreach ($ctas as $cta) {
                $this->db->execute("UPDATE cuentas_bancarias SET estado = 'INACTIVA', desactivado_por = :u, desactivado_at = now(), desactivacion_motivo = :m
                                     WHERE id = :id", ['u' => $actor->id, 'm' => 'Relación beneficiario–trabajador desactivada: ' . $motivo, 'id' => $cta['id']]);
                $this->audit->record('cuentas.desactivar', 'cuentas_bancarias', (int) $cta['id'], ['estado' => 'ACTIVA'], ['estado' => 'INACTIVA'],
                    ['beneficiario_id' => $id, 'motivo' => $motivo, 'por_relacion' => $relId]);
            }
            $this->audit->record('beneficiarios.desactivar_relacion', 'beneficiario_trabajadores', $relId, ['estado' => 'ACTIVA'], ['estado' => 'INACTIVA'],
                ['beneficiario_id' => $id, 'empleado_id' => (string) $r['empleado_id'], 'motivo' => $motivo]);
        });
    }

    public function revealAccount(CurrentUser $actor, int $id, int $cuentaId): string
    {
        $empleado = $this->db->fetchValue('SELECT empleado_id FROM cuentas_bancarias WHERE id = :c AND beneficiario_id = :b', ['c' => $cuentaId, 'b' => $id]);
        if ($empleado === null) {
            throw HttpException::notFound();
        }
        return $this->employees->revealAccount($actor, (int) $empleado, $cuentaId);
    }
}
