<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Identity;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Jobs\JobQueue;
use ConciliaRH\Domain\Security\Throttle;
use ConciliaRH\Domain\ValidationException;
use PDOException;

/**
 * Posibles duplicados y fusión NO destructiva de empleados (docs/IDENTITY_MERGE.md).
 *
 * La regla de evidencias y conflictos vive en la BD (fn_fusion_analisis): la previsualización, la fusión y el detector usan
 * exactamente la misma. La fusión se confirma con la HUELLA del análisis previsualizado: si algo cambió entretanto
 * (otra fusión, una importación, una asociación), la BD la rechaza con REGISTRO_MODIFICADO. Uno a uno; nunca en bloque.
 */
final class MergeService
{
    public const CONFIANZAS = ['ALTA', 'MEDIA', 'BAJA'];
    public const ESTADOS_SOLICITUD = ['PENDIENTE_APROBACION', 'EJECUTADA', 'RECHAZADA', 'CANCELADA', 'EXPIRADA'];
    public const ESTADOS = ['PENDIENTE', 'DESCARTADO', 'FUSIONADO', 'OBSOLETO'];
    public const EVIDENCIAS = [
        'MISMO_RFC' => 'Mismo RFC válido', 'MISMA_CURP' => 'Misma CURP válida', 'MISMO_ISSEMYM' => 'Mismo ISSEMYM',
        'CUENTA_CONFIRMADA_COMPARTIDA' => 'Una cuenta registrada para ambos', 'PAGOS_A_LA_MISMA_CUENTA' => 'Pagos de nómina a la misma cuenta',
        'REGISTRO_CON_IDENTIFICADORES_DE_AMBOS' => 'Un registro trajo identificadores de ambos', 'MISMO_NOMBRE' => 'Mismo nombre normalizado',
        'NOMBRE_SIMILAR' => 'Nombre muy parecido',
    ];
    public const CONFLICTOS = [
        'MISMO_EMPLEADO' => 'Es el mismo empleado', 'YA_FUSIONADO' => 'Alguno ya está fusionado en otro',
        'RFC_DIFERENTE' => 'RFC válidos distintos', 'CURP_DIFERENTE' => 'CURP válidas distintas', 'ISSEMYM_DIFERENTE' => 'ISSEMYM distintos',
        'NOMINA_SIMULTANEA_MISMO_LOTE' => 'Ambos cobran nómina en la misma carga (lote)',
        'MISMO_PERIODO_CARGAS_DISTINTAS' => 'Ambos aparecen en el mismo periodo, en cargas distintas',
        'PAGOS_NOMINA_EN_EL_MISMO_LOTE' => 'Ambos recibieron pagos de nómina en el mismo lote',
        'CUENTAS_NOMINA_ACTIVAS_DISTINTAS' => 'Cada uno tiene una cuenta de nómina activa distinta',
        'GRUPO_DIFERENTE' => 'Grupo distinto', 'NOMBRE_DIFERENTE' => 'Nombre distinto',
        'IDENTIFICADOR_EDITADO' => 'Un RFC, CURP o ISSEMYM de alguno se editó o borró a mano (el conflicto podría estar oculto)',
    ];

    public function __construct(private readonly Database $db, private readonly AuditLogger $audit, private readonly JobQueue $jobs)
    {
    }

    /** @param array<string,string> $f @return array{rows:list<array<string,mixed>>,total:int,page:int,conteos:array<string,int>} */
    public function duplicates(array $f, int $page, int $per): array
    {
        $w = ['true'];
        $p = [];
        $estado = in_array($f['estado'] ?? '', self::ESTADOS, true) ? $f['estado'] : 'PENDIENTE';
        $w[] = 'd.estado = :estado';
        $p['estado'] = $estado;
        if (in_array($f['confianza'] ?? '', self::CONFIANZAS, true)) {
            $w[] = 'd.confianza = :conf';
            $p['conf'] = $f['confianza'];
        }
        $q = mb_strtoupper(trim($f['q'] ?? ''));
        if ($q !== '') {
            if (mb_strlen($q) < 3) {
                throw new ValidationException(['q' => 'Escriba al menos 3 caracteres.']);
            }
            // Carácter de escape «!» (una barra invertida dentro de comillas confunde el parser de parámetros de PDO)
            $w[] = "(a.numero_empleado = :qn OR b.numero_empleado = :qn2 OR a.nombre_normalizado LIKE :ql ESCAPE '!' OR b.nombre_normalizado LIKE :ql2 ESCAPE '!')";
            $like = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $p += ['qn' => $q, 'qn2' => $q, 'ql' => $like, 'ql2' => $like];
        }
        $where = implode(' AND ', $w);
        $from = "FROM duplicados_empleados d JOIN empleados a ON a.id = d.empleado_a JOIN empleados b ON b.id = d.empleado_b WHERE $where";
        $total = (int) $this->db->fetchValue("SELECT count(*) $from", $p);
        $page = max(1, min($page, max(1, (int) ceil($total / $per))));
        $rows = $this->db->fetchAll(
            "SELECT d.*, a.numero_empleado AS a_numero, a.nombre_completo AS a_nombre, a.grupo AS a_grupo, b.numero_empleado AS b_numero,
                    b.nombre_completo AS b_nombre, b.grupo AS b_grupo
               $from ORDER BY CASE d.confianza WHEN 'ALTA' THEN 1 WHEN 'MEDIA' THEN 2 ELSE 3 END, d.id DESC LIMIT :lim OFFSET :off",
            [...$p, 'lim' => $per, 'off' => ($page - 1) * $per]);
        foreach ($rows as &$r) {
            $r['evidencias'] = json_decode((string) $r['evidencias'], true) ?: [];
            $r['conflictos'] = json_decode((string) $r['conflictos'], true) ?: [];
        }
        $conteos = array_column($this->db->fetchAll("SELECT confianza, count(*) AS n FROM duplicados_empleados WHERE estado = 'PENDIENTE' GROUP BY 1"), 'n', 'confianza');
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'conteos' => array_map('intval', $conteos)];
    }

    public function dismiss(CurrentUser $u, int $id, string $motivo): void
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 5 || mb_strlen($motivo) > 1000) {
            throw new ValidationException(['motivo' => 'Indique el motivo (5 a 1000 caracteres).']);
        }
        $d = $this->db->fetchOne('SELECT id, estado, confianza, empleado_a, empleado_b FROM duplicados_empleados WHERE id = :id', ['id' => $id])
            ?? throw HttpException::notFound();
        $n = $this->db->execute("UPDATE duplicados_empleados SET estado = 'DESCARTADO', motivo = :m, decidido_por = :u WHERE id = :id AND estado = 'PENDIENTE'",
            ['m' => $motivo, 'u' => $u->id, 'id' => $id]);
        if ($n !== 1) {
            throw new ValidationException(['estado' => 'REGISTRO_MODIFICADO: el par ya no está pendiente.']);
        }
        $this->audit->record('empleados.duplicado_descartar', 'duplicados_empleados', $id, ['estado' => 'PENDIENTE'], ['estado' => 'DESCARTADO'],
            ['empleado_a' => (int) $d['empleado_a'], 'empleado_b' => (int) $d['empleado_b'], 'confianza' => $d['confianza'], 'motivo' => $motivo]);
    }

    public function detectNow(CurrentUser $u): int
    {
        // Una detección a la vez y como mucho una cada 10 minutos (toma el candado global mientras corre)
        $prev = $this->db->fetchOne("SELECT id, estado FROM procesos WHERE tipo = 'DETECTAR_DUPLICADOS'
                                      AND (estado IN ('PENDIENTE', 'EN_EJECUCION') OR created_at > now() - interval '10 minutes') ORDER BY id DESC LIMIT 1");
        if ($prev !== null) {
            throw new ValidationException(['detectar' => 'Ya hay una detección reciente o en curso (proceso #' . (int) $prev['id'] . '). Intente más tarde.']);
        }
        $id = $this->jobs->enqueue($u, 'DETECTAR_DUPLICADOS', [], 150, 'duplicados:' . date('YmdHi'), 600);
        $this->audit->record('empleados.duplicados_detectar', 'procesos', $id, null, null, null);
        return $id;
    }

    /**
     * Previsualización: análisis de la BD + datos de ambos (identificadores enmascarados sin permiso) + huella a confirmar.
     * @return array<string,mixed>
     */
    public function preview(CurrentUser $u, int $origen, int $destino): array
    {
        if ($origen === $destino) {
            throw new ValidationException(['destino' => 'Elija dos empleados distintos.']);
        }
        $emps = [];
        foreach ([$origen, $destino] as $id) {
            $e = $this->db->fetchOne(
                'SELECT e.id, e.numero_empleado, e.nombre_completo, e.rfc, e.rfc_valido, e.curp, e.curp_valida, e.issemym, e.grupo, e.activo,
                        e.fusionado_en_id, p1.descripcion AS primer_periodo, p2.descripcion AS ultimo_periodo, c.numero_empleado AS canonico_numero
                   FROM empleados e JOIN periodos_nomina p1 ON p1.id = e.primer_periodo_id JOIN periodos_nomina p2 ON p2.id = e.ultimo_periodo_id
                   LEFT JOIN empleados c ON c.id = e.fusionado_en_id WHERE e.id = :id', ['id' => $id]) ?? throw HttpException::notFound();
            if (!$u->can('empleados.ver_sensible')) {
                foreach (['rfc', 'curp', 'issemym'] as $k) {
                    $e[$k] = AccountVault::maskId($e[$k]);
                }
            }
            $e['cuentas'] = $this->db->fetchAll(
                "SELECT uso, tipo, banco, estado, cuenta_ultimos4 FROM cuentas_bancarias WHERE empleado_id IN (SELECT id FROM empleados WHERE id = :id OR fusionado_en_id = :id2)
                  ORDER BY estado, id", ['id' => $id, 'id2' => $id]);
            $e['alias'] = $this->db->fetchAll('SELECT id, numero_empleado FROM empleados WHERE fusionado_en_id = :id ORDER BY id', ['id' => $id]);
            $emps[$id] = $e;
        }
        $an = (string) $this->db->fetchValue('SELECT fn_fusion_analisis(:o, :d)', ['o' => $origen, 'd' => $destino]);
        $huella = (string) $this->db->fetchValue('SELECT fn_fusion_huella(CAST(:a AS jsonb))', ['a' => $an]);
        $a = json_decode($an, true);
        // Conflictos graves del análisis + los que la BD agrega (identificadores editados a mano): la misma regla que aplicará la BD
        $extra = array_values(array_filter(explode(',', trim((string) $this->db->fetchValue('SELECT fn_fusion_conflictos_extra(:o, :d)',
            ['o' => $origen, 'd' => $destino]), '{}'))));
        foreach ($extra as $x) {
            $a['conflictos'][] = ['codigo' => $x, 'severidad' => 'GRAVE'];
        }
        $graves = array_values(array_unique(array_map(static fn ($c) => $c['codigo'], array_filter($a['conflictos'], static fn ($c) => $c['severidad'] === 'GRAVE'))));
        return ['origen' => $emps[$origen], 'destino' => $emps[$destino], 'analisis' => $a, 'huella' => $huella, 'graves' => $graves,
                'puede_conflictos' => $u->can('empleados.fusionar_conflictos')];
    }

    /**
     * Confirma lo previsualizado. Sin conflictos graves aplica la fusión; CON conflictos graves crea una solicitud que
     * debe aprobar otra persona (docs/CRITICAL_MERGE_APPROVAL.md). La BD decide con el análisis vigente, no con el cliente.
     * @param list<string> $aceptados
     * @return array{tipo:'fusion'|'solicitud',id:int}
     */
    public function merge(CurrentUser $u, int $origen, int $destino, string $motivo, array $aceptados, string $huella): array
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 20 || mb_strlen($motivo) > 2000) {
            throw new ValidationException(['motivo' => 'El motivo de la fusión debe tener entre 20 y 2000 caracteres.']);
        }
        if (!preg_match('/^[0-9a-f]{64}$/', $huella)) {
            throw new ValidationException(['huella' => 'Previsualice la fusión antes de confirmarla.']);
        }
        $aceptados = array_values(array_unique(array_filter($aceptados, static fn ($c) => is_string($c) && isset(self::CONFLICTOS[$c]))));
        try {
            $an = json_decode((string) $this->db->fetchValue('SELECT fn_fusion_analisis(:o, :d)', ['o' => $origen, 'd' => $destino]), true);
            $graves = array_filter($an['conflictos'] ?? [], static fn ($c) => ($c['severidad'] ?? '') === 'GRAVE');
            if (trim((string) $this->db->fetchValue('SELECT fn_fusion_conflictos_extra(:o, :d)', ['o' => $origen, 'd' => $destino]), '{}') !== '') {
                $graves[] = ['codigo' => 'IDENTIFICADOR_EDITADO'];
            }
            if ($graves !== []) {
                return ['tipo' => 'solicitud', 'id' => $this->requestCritical($u, $origen, $destino, $motivo, $aceptados, $huella)];
            }
            // La fusión y su auditoría se confirman juntas (nunca una fusión sin su registro de auditoría)
            return ['tipo' => 'fusion', 'id' => (int) $this->db->transaction(function () use ($u, $origen, $destino, $motivo, $huella): int {
                $id = (int) $this->db->fetchValue('SELECT fn_fusionar_empleados(:o, :d, :u, :m, CAST(:a AS text[]), :h)',
                    ['o' => $origen, 'd' => $destino, 'u' => $u->id, 'm' => $motivo, 'a' => '{}', 'h' => $huella]);
                $this->auditFusion($id, $origen, $destino, $motivo, null);
                return $id;
            })];
        } catch (PDOException $e) {
            throw $this->mapError($e);
        }
    }

    /** @param list<string> $aceptados */
    private function requestCritical(CurrentUser $u, int $origen, int $destino, string $motivo, array $aceptados, string $huella): int
    {
        return (int) $this->db->transaction(function () use ($u, $origen, $destino, $motivo, $aceptados, $huella): int {
            $id = (int) $this->db->fetchValue('SELECT fn_solicitar_fusion_critica(:o, :d, :u, :m, CAST(:a AS text[]), :h)',
                ['o' => $origen, 'd' => $destino, 'u' => $u->id, 'm' => $motivo, 'a' => '{' . implode(',', $aceptados) . '}', 'h' => $huella]);
            $s = $this->db->fetchOne('SELECT conflictos_graves, expira_at FROM solicitudes_fusion WHERE id = :id', ['id' => $id]);
            $this->audit->record('empleados.fusion_solicitar', 'solicitudes_fusion', $id, null, ['estado' => 'PENDIENTE_APROBACION'], [
                'origen' => $origen, 'destino' => $destino, 'motivo' => $motivo, 'conflictos_graves' => trim((string) $s['conflictos_graves'], '{}'),
                'expira_at' => $s['expira_at'], 'huella' => $huella]);
            return $id;
        });
    }

    private function auditFusion(int $id, int $origen, int $destino, string $motivo, ?array $solicitud): void
    {
        $f = $this->db->fetchOne('SELECT confianza, analisis, conflictos_aceptados, reapuntados FROM fusiones_empleados WHERE id = :id', ['id' => $id]);
        $a = json_decode((string) $f['analisis'], true);
        $this->audit->record('empleados.fusionar', 'empleados', $origen, ['fusionado_en_id' => null], ['fusionado_en_id' => $destino], [
            'fusion_id' => $id, 'origen' => $origen, 'destino' => $destino, 'confianza' => $f['confianza'], 'motivo' => $motivo,
            'evidencias' => array_column($a['evidencias'], 'codigo'), 'conflictos' => array_column($a['conflictos'], 'codigo'),
            'conflictos_aceptados' => trim((string) $f['conflictos_aceptados'], '{}'), 'reapuntados' => trim((string) $f['reapuntados'], '{}'),
            'comparacion' => $a['comparacion'], 'conteos' => $a['conteos'], 'version' => $a['version'], ...($solicitud ?? [])]);
    }

    /** @param array<string,string> $f @return list<array<string,mixed>> */
    public function requests(array $f, int $limit = 200): array
    {
        $this->db->fetchValue('SELECT fn_solicitudes_fusion_expirar()');
        $where = [];
        $params = [];
        if (in_array($f['estado'] ?? '', self::ESTADOS_SOLICITUD, true)) {
            $where[] = 's.estado = :e';
            $params['e'] = $f['estado'];
        }
        return $this->db->fetchAll(
            'SELECT s.id, s.origen_id, s.destino_id, s.estado, s.conflictos_graves, s.solicitado_at, s.expira_at, s.decidido_at,
                    us.username AS solicitante, ud.username AS decisor, o.numero_empleado AS origen_numero, d.numero_empleado AS destino_numero,
                    d.nombre_completo AS destino_nombre, s.solicitado_por, s.fusion_id
               FROM solicitudes_fusion s JOIN usuarios us ON us.id = s.solicitado_por LEFT JOIN usuarios ud ON ud.id = s.decidido_por
               JOIN empleados o ON o.id = s.origen_id JOIN empleados d ON d.id = s.destino_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY (s.estado = \'PENDIENTE_APROBACION\') DESC, s.id DESC LIMIT ' . max(1, min(500, $limit)),
            $params);
    }

    /** @return array<string,mixed> */
    public function request(CurrentUser $u, int $id): array
    {
        $this->db->fetchValue('SELECT fn_solicitudes_fusion_expirar()');
        $s = $this->db->fetchOne(
            'SELECT s.*, us.username AS solicitante, us.nombre AS solicitante_nombre, ud.username AS decisor,
                    o.numero_empleado AS origen_numero, o.nombre_completo AS origen_nombre, d.numero_empleado AS destino_numero, d.nombre_completo AS destino_nombre
               FROM solicitudes_fusion s JOIN usuarios us ON us.id = s.solicitado_por LEFT JOIN usuarios ud ON ud.id = s.decidido_por
               JOIN empleados o ON o.id = s.origen_id JOIN empleados d ON d.id = s.destino_id WHERE s.id = :id', ['id' => $id])
            ?? throw HttpException::notFound();
        $s['analisis'] = json_decode((string) $s['analisis'], true);
        $s['graves'] = array_values(array_filter(explode(',', trim((string) $s['conflictos_graves'], '{}'))));
        $pendiente = $s['estado'] === 'PENDIENTE_APROBACION';
        $propia = (int) $s['solicitado_por'] === $u->id;
        $s['puede_aprobar'] = $pendiente && !$propia && $u->can('empleados.fusionar_conflictos_aprobar');
        $s['puede_cancelar'] = $pendiente && $propia;
        $s['es_propia'] = $propia;
        return $s;
    }

    /** Segunda persona: aprueba y ejecuta. @return array{estado:string,fusion_id:?int,motivo?:string} */
    public function approve(CurrentUser $u, int $id, string $motivo): array
    {
        $motivo = $this->decisionReason($motivo);
        Throttle::check($this->db, $u->id, ['empleados.fusion_aprobar', 'empleados.fusion_rechazar'], 10, 60,
            'Alcanzó el máximo de decisiones de fusión por minuto. Espere un momento.');
        try {
            return $this->db->transaction(function () use ($u, $id, $motivo): array {
                $s = $this->db->fetchOne('SELECT origen_id, destino_id, motivo, solicitado_por FROM solicitudes_fusion WHERE id = :id', ['id' => $id])
                    ?? throw HttpException::notFound();
                $fid = $this->db->fetchValue('SELECT fn_aprobar_solicitud_fusion(:id, :u, :m)', ['id' => $id, 'u' => $u->id, 'm' => $motivo]);
                $row = $this->db->fetchOne('SELECT estado, motivo_decision FROM solicitudes_fusion WHERE id = :id', ['id' => $id]);
                $estado = (string) $row['estado'];
                if ($fid === null) {
                    // Expiró, cambió el análisis o el solicitante perdió su permiso: la solicitud quedó cerrada (se confirma para que conste)
                    $motivo = match (true) {
                        $estado === 'EXPIRADA' => 'EXPIRO',
                        str_contains((string) $row['motivo_decision'], 'solicitante') => 'SOLICITANTE_SIN_PERMISO',
                        default => 'ANALISIS_CAMBIO',
                    };
                    $this->audit->record('empleados.fusion_' . ($estado === 'EXPIRADA' ? 'expirada' : 'cancelada'), 'solicitudes_fusion', $id,
                        ['estado' => 'PENDIENTE_APROBACION'], ['estado' => $estado], ['motivo' => $motivo]);
                    return ['estado' => $estado, 'fusion_id' => null, 'motivo' => $motivo];
                }
                $this->audit->record('empleados.fusion_aprobar', 'solicitudes_fusion', $id, ['estado' => 'PENDIENTE_APROBACION'], ['estado' => 'EJECUTADA'], [
                    'fusion_id' => (int) $fid, 'solicitado_por' => (int) $s['solicitado_por'], 'aprobado_por' => $u->id, 'motivo_aprobacion' => $motivo]);
                $this->auditFusion((int) $fid, (int) $s['origen_id'], (int) $s['destino_id'], (string) $s['motivo'],
                    ['solicitud_id' => $id, 'solicitado_por' => (int) $s['solicitado_por'], 'aprobado_por' => $u->id]);
                return ['estado' => 'EJECUTADA', 'fusion_id' => (int) $fid];
            });
        } catch (PDOException $e) {
            throw $this->mapError($e);
        }
    }

    /** RECHAZADA (aprobador distinto) o CANCELADA (el propio solicitante). */
    public function decide(CurrentUser $u, int $id, string $decision, string $motivo): void
    {
        $motivo = $this->decisionReason($motivo);
        if ($decision === 'RECHAZADA') {
            Throttle::check($this->db, $u->id, ['empleados.fusion_aprobar', 'empleados.fusion_rechazar'], 10, 60,
                'Alcanzó el máximo de decisiones de fusión por minuto. Espere un momento.');
        }
        try {
            $this->db->transaction(function () use ($u, $id, $decision, $motivo): void {
                if ($this->db->fetchValue('SELECT 1 FROM solicitudes_fusion WHERE id = :id', ['id' => $id]) === null) {
                    throw HttpException::notFound();
                }
                $this->db->fetchValue('SELECT fn_decidir_solicitud_fusion(:id, :u, :d, :m)', ['id' => $id, 'u' => $u->id, 'd' => $decision, 'm' => $motivo]);
                $this->audit->record($decision === 'RECHAZADA' ? 'empleados.fusion_rechazar' : 'empleados.fusion_cancelar', 'solicitudes_fusion', $id,
                    ['estado' => 'PENDIENTE_APROBACION'], ['estado' => $decision], ['motivo' => $motivo]);
            });
        } catch (PDOException $e) {
            throw $this->mapError($e);
        }
    }

    private function decisionReason(string $motivo): string
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 5 || mb_strlen($motivo) > 1000) {
            throw new ValidationException(['motivo' => 'Escriba el motivo de la decisión (5 a 1000 caracteres).']);
        }
        return $motivo;
    }

    /** @return array<string,mixed> */
    public function fusion(int $id): array
    {
        $f = $this->db->fetchOne(
            'SELECT f.*, u.username AS usuario, us.username AS separada_por_usuario, o.numero_empleado AS origen_numero, o.nombre_completo AS origen_nombre,
                    d.numero_empleado AS destino_numero, d.nombre_completo AS destino_nombre
               FROM fusiones_empleados f JOIN usuarios u ON u.id = f.usuario_id LEFT JOIN usuarios us ON us.id = f.separada_por
               JOIN empleados o ON o.id = f.origen_id JOIN empleados d ON d.id = f.destino_id WHERE f.id = :id', ['id' => $id])
            ?? throw HttpException::notFound();
        $f['analisis'] = json_decode((string) $f['analisis'], true);
        return $f;
    }

    /** @return list<array<string,mixed>> */
    public function fusions(int $limit = 200): array
    {
        return $this->db->fetchAll(
            'SELECT f.id, f.origen_id, f.destino_id, f.estado, f.confianza, f.created_at, u.username AS usuario, o.numero_empleado AS origen_numero,
                    d.numero_empleado AS destino_numero, d.nombre_completo AS destino_nombre
               FROM fusiones_empleados f JOIN usuarios u ON u.id = f.usuario_id JOIN empleados o ON o.id = f.origen_id JOIN empleados d ON d.id = f.destino_id
              ORDER BY f.id DESC LIMIT ' . max(1, min(500, $limit)));
    }

    public function separate(CurrentUser $u, int $id, string $motivo): void
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 20 || mb_strlen($motivo) > 2000) {
            throw new ValidationException(['motivo' => 'El motivo de la separación debe tener entre 20 y 2000 caracteres.']);
        }
        $f = $this->fusion($id);
        try {
            $this->db->transaction(function () use ($u, $id, $motivo, $f): void {
                $this->db->fetchValue('SELECT fn_separar_fusion(:f, :u, :m)', ['f' => $id, 'u' => $u->id, 'm' => $motivo]);
                $this->audit->record('empleados.separar', 'empleados', (int) $f['origen_id'], ['fusionado_en_id' => (int) $f['destino_id']],
                    ['fusionado_en_id' => null], ['fusion_id' => $id, 'motivo' => $motivo]);
            });
        } catch (PDOException $e) {
            throw $this->mapError($e);
        }
    }

    private function mapError(PDOException $e): \Throwable
    {
        $state = $e->errorInfo[0] ?? '';
        $msg = (string) ($e->errorInfo[2] ?? '');
        return match (true) {
            str_contains($msg, 'CUATRO_OJOS') => new ValidationException(['aprobacion' => 'Quien solicita no puede aprobar su propia solicitud: se requiere una segunda persona.']),
            str_contains($msg, 'APROBACION_REQUERIDA') => new ValidationException(['fusion' => 'Esta fusión tiene conflictos graves: requiere la aprobación de una segunda persona.']),
            str_contains($msg, 'SOLICITUD_NO_PENDIENTE') => new ValidationException(['solicitud' => 'La solicitud ya no está pendiente (se decidió, canceló o expiró).']),
            str_contains($msg, 'SIN_CONFLICTOS_GRAVES') => new ValidationException(['fusion' => 'Esta fusión no tiene conflictos graves: no requiere aprobación.']),
            $state === '23505' && str_contains($msg, 'sf_pendiente_uk') => new ValidationException(['solicitud' => 'Ya hay una solicitud pendiente para esta pareja de empleados.']),
            $state === '40001', $state === '40P01' => new ValidationException(['fusion' => 'REGISTRO_MODIFICADO: los datos de los empleados cambiaron desde la previsualización. Vuelva a previsualizar.']),
            $state === '42501' => HttpException::forbidden(),
            str_contains($msg, 'CONFLICTOS_SIN_ACEPTAR') => new ValidationException(['conflictos' => 'Debe aceptar expresamente cada conflicto grave.']),
            str_contains($msg, 'EVIDENCIA_INSUFICIENTE') => new ValidationException(['fusion' => 'Evidencia insuficiente: el nombre por sí solo nunca permite fusionar.']),
            str_contains($msg, 'FUSION_BLOQUEADA') => new ValidationException(['fusion' => 'La fusión está bloqueada (mismo empleado o alguno ya fusionado).']),
            $state === '23514', $state === '22023' => new ValidationException(['fusion' => 'La operación no es válida en el estado actual.']),
            default => $e,
        };
    }
}
