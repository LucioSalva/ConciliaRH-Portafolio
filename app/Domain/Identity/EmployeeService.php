<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Identity;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Periods\PeriodService;
use ConciliaRH\Domain\ValidationException;
use RuntimeException;

/**
 * Empleado maestro (FASE 4): consulta, búsqueda, detalle con trazabilidad y correcciones manuales.
 *
 * - Las ALTAS sólo las hace el motor desde un tabulado RRHH confiable (nunca la web ni Tesorería).
 * - RFC/CURP completos y la búsqueda por ellos exigen `empleados.ver_sensible`; si no, se enmascaran.
 * - Las cuentas se muestran como ••••1234; revelar el número completo exige `cuentas.ver_sensible`, es
 *   una acción POST explícita y queda auditada (sin el número en la auditoría).
 * - Toda corrección manual exige motivo y deja historial por campo + auditoría.
 */
final class EmployeeService
{
    public const RFC_RE = '/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/u';
    public const CURP_RE = '/^[A-Z][AEIOUX][A-Z]{2}[0-9]{6}[HMX](AS|BC|BS|CC|CL|CM|CS|CH|DF|DG|GT|GR|HG|JC|MC|MN|MS|NT|NL|OC|PL|QT|QR|SP|SL|SR|TC|TS|TL|VZ|YN|ZS|NE)[B-DF-HJ-NP-TV-Z]{3}[A-Z0-9][0-9]$/';
    private const EDITABLE = ['apellido_paterno', 'apellido_materno', 'nombres', 'issemym', 'rfc', 'curp', 'activo', 'observaciones'];

    public function __construct(
        private readonly Database $db,
        private readonly AuditLogger $audit,
        private readonly AccountVault $vault,
    ) {
    }

    /** Clave de comparación de nombre (misma regla que el motor: sin acentos, mayúsculas, sin puntuación). */
    public static function nameKey(string $v): string
    {
        $v = (string) preg_replace('/[\p{Cf}\p{Cc}]/u', '', $v);
        $v = \Normalizer::normalize($v, \Normalizer::FORM_KD) ?: $v;
        $v = (string) preg_replace('/\p{Mn}/u', '', $v);
        $v = mb_strtoupper($v, 'UTF-8');
        $v = (string) preg_replace('/[^A-Z0-9 ]/', ' ', $v);
        return trim((string) preg_replace('/\s+/', ' ', $v));
    }

    /** Escapa un literal para LIKE con ESCAPE '!' (no se usa la barra invertida: el analizador de PDO la toma como escape de comilla). */
    public static function likeEscape(string $v): string
    {
        return strtr($v, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    public static function normalizeId(string $v): string
    {
        return (string) preg_replace('/[\s\-_.]/u', '', mb_strtoupper(trim($v), 'UTF-8'));
    }

    /**
     * Búsqueda: número (prefijo), nombre (contiene, ≥ 3 caracteres, sobre la clave normalizada) y, sólo con
     * permiso sensible, RFC/CURP EXACTOS normalizados. Índices: numero_empleado (único), nombre_normalizado
     * (text_pattern_ops), rfc, curp.
     * @param array{q:string,grupo:string,estado:string,incidencias:bool} $f
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,criterio:?string}
     */
    public function paginate(CurrentUser $user, array $f, int $page, int $perPage): array
    {
        $where = ['true'];
        $params = [];
        $criterio = null;
        $q = trim($f['q']);
        if ($q !== '') {
            $id = self::normalizeId($q);
            if ($user->can('empleados.ver_sensible') && (preg_match(self::RFC_RE, $id) || preg_match(self::CURP_RE, $id))) {
                $where[] = '(e.rfc = :idq OR e.curp = :idq2)';
                $params += ['idq' => $id, 'idq2' => $id];
                $criterio = 'IDENTIFICADOR';
            } elseif (preg_match('/^[0-9A-Z-]{1,20}$/', $id) && preg_match('/\d/', $id)) {
                $where[] = "e.numero_empleado LIKE :num ESCAPE '!'";
                $params['num'] = self::likeEscape($id) . '%';
                $criterio = 'NUMERO';
            } else {
                $key = self::nameKey($q);
                if (mb_strlen($key) < 3) {
                    throw new ValidationException(['q' => 'Escriba al menos 3 letras del nombre o el número de empleado.']);
                }
                // Prefijo con índice; "contiene" en todos los tokens para nombres parciales
                $parts = array_slice(array_filter(explode(' ', $key)), 0, 5);
                foreach ($parts as $i => $t) {
                    $where[] = "e.nombre_normalizado LIKE :t$i ESCAPE '!'";
                    $params["t$i"] = '%' . self::likeEscape($t) . '%';
                }
                $criterio = 'NOMBRE';
            }
        }
        if (in_array($f['grupo'], ['POLICIA', 'BOMBEROS', 'OTRO'], true)) {
            $where[] = 'e.grupo = :g';
            $params['g'] = $f['grupo'];
        }
        if ($f['estado'] === 'ACTIVO' || $f['estado'] === 'INACTIVO') {
            $where[] = 'e.activo = :act';
            $params['act'] = $f['estado'] === 'ACTIVO';
        }
        if ($f['incidencias']) {
            $where[] = "EXISTS (SELECT 1 FROM incidencias_identidad i WHERE i.empleado_id = e.id AND i.estado = 'ABIERTA')";
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) $this->db->fetchValue("SELECT count(*) FROM empleados e WHERE $sqlWhere", $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $rows = $this->db->fetchAll(
            "SELECT e.id, e.numero_empleado, e.nombre_completo, e.rfc, e.rfc_valido, e.curp, e.curp_valida, e.grupo, e.area, e.cargo, e.fusionado_en_id,
                    e.activo, e.confianza_identidad, pe.descripcion AS ultimo_periodo,
                    (SELECT count(*) FROM incidencias_identidad i WHERE i.empleado_id = e.id AND i.estado = 'ABIERTA') AS incidencias,
                    (SELECT count(*) FROM cuentas_bancarias c WHERE c.empleado_id = e.id AND c.estado = 'ACTIVA') AS cuentas
               FROM empleados e JOIN periodos_nomina pe ON pe.id = e.ultimo_periodo_id
              WHERE $sqlWhere ORDER BY e.nombre_normalizado, e.id LIMIT :lim OFFSET :off",
            $params + ['lim' => $perPage, 'off' => ($page - 1) * $perPage]
        );
        return ['rows' => array_map(fn (array $r) => $this->mask($user, $r), $rows), 'total' => $total, 'page' => $page, 'criterio' => $criterio];
    }

    /** @return array<string,mixed> */
    public function find(CurrentUser $user, int $id): array
    {
        $e = $this->db->fetchOne(
            'SELECT e.*, p1.descripcion AS primer_periodo, p2.descripcion AS ultimo_periodo, a.id AS origen_archivo_id,
                    a.nombre_original AS origen_archivo, r.fila_origen AS origen_fila, r.hoja_nombre AS origen_hoja
               FROM empleados e
               JOIN periodos_nomina p1 ON p1.id = e.primer_periodo_id
               JOIN periodos_nomina p2 ON p2.id = e.ultimo_periodo_id
               LEFT JOIN registros_rrhh r ON r.id = e.origen_registro_id
               LEFT JOIN archivos_importados a ON a.id = r.archivo_id
              WHERE e.id = :id',
            ['id' => $id]
        );
        if ($e === null) {
            throw HttpException::notFound();
        }
        // Identidad canónica (FASE 7): a quién está fusionado y quiénes están fusionados en él
        $e['canonico'] = $e['fusionado_en_id'] === null ? null : $this->db->fetchOne(
            'SELECT e.id, e.numero_empleado, e.nombre_completo, e.fusion_id FROM empleados e WHERE e.id = :c', ['c' => (int) $e['fusionado_en_id']]);
        $e['alias'] = $this->db->fetchAll('SELECT id, numero_empleado, nombre_completo, fusion_id FROM empleados WHERE fusionado_en_id = :id ORDER BY id', ['id' => $id]);
        return $this->mask($user, $e);
    }

    /** Identificación mínima (para candidatos en asociaciones). @param list<int> $ids @return array<int,array<string,mixed>> */
    public function brief(CurrentUser $user, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($i) => is_int($i) && $i > 0)));
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT e.id, e.numero_empleado, e.nombre_completo, e.rfc, e.rfc_valido, e.curp, e.curp_valida, e.grupo, e.area, e.activo,
                    pe.descripcion AS ultimo_periodo
               FROM empleados e JOIN periodos_nomina pe ON pe.id = e.ultimo_periodo_id
              WHERE e.id = ANY(CAST(:ids AS bigint[]))',
            ['ids' => '{' . implode(',', $ids) . '}']
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = $this->mask($user, $r);
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function history(CurrentUser $user, int $id): array
    {
        $rows = $this->db->fetchAll(
            'SELECT h.*, u.username, pe.descripcion AS periodo, r.archivo_id, r.fila_origen
               FROM empleado_historial h LEFT JOIN usuarios u ON u.id = h.usuario_id LEFT JOIN periodos_nomina pe ON pe.id = h.periodo_id
               LEFT JOIN registros_rrhh r ON r.id = h.registro_rrhh_id
              WHERE h.empleado_id = :id ORDER BY h.id DESC LIMIT 200',
            ['id' => $id]
        );
        if (!$user->can('empleados.ver_sensible')) {
            foreach ($rows as &$r) {
                if (in_array($r['campo'], ['rfc', 'curp', 'issemym'], true)) {
                    $r['valor_anterior'] = AccountVault::maskId($r['valor_anterior']);
                    $r['valor_nuevo'] = AccountVault::maskId($r['valor_nuevo']);
                }
            }
        }
        return $rows;
    }

    /** Snapshots RRHH por quincena (sólo importaciones vigentes). @return list<array<string,mixed>> */
    public function rrhhRecords(CurrentUser $user, int $id): array
    {
        $rows = $this->db->fetchAll(
            "SELECT r.id, r.archivo_id, r.hoja_nombre, r.fila_origen, r.nombre_original, r.rfc, r.curp, r.area, r.departamento, r.puesto,
                    r.cargo, r.percepciones, r.deducciones, r.neto, r.asociacion_estado, r.asociacion_metodo, r.grupo,
                    pe.descripcion AS periodo, pe.fecha_fin, l.numero AS lote_numero, l.id AS lote_id, l.es_oficial,
                    (SELECT coalesce(sum(c.importe), 0) FROM registros_rrhh_conceptos c JOIN conceptos_nomina k ON k.id = c.concepto_id
                      WHERE c.registro_id = r.id AND k.es_pension) AS pension
               FROM registros_rrhh r
               JOIN importaciones_normalizadas n ON n.id = r.importacion_id AND n.estado = 'VIGENTE'
               JOIN periodos_nomina pe ON pe.id = r.periodo_id JOIN lotes_importacion l ON l.id = r.lote_id
              WHERE r.empleado_id IN (SELECT x.id FROM empleados x WHERE x.id = :id OR x.fusionado_en_id = :idc) ORDER BY pe.fecha_fin DESC, l.numero, r.archivo_id LIMIT 300",
            ['id' => $id, 'idc' => $id]
        );
        return array_map(fn (array $r) => $this->mask($user, $r), $rows);
    }

    /** @return list<array<string,mixed>> */
    public function treasuryRecords(int $id): array
    {
        return $this->db->fetchAll(
            "SELECT r.id, r.archivo_id, r.hoja_nombre, r.fila_origen, r.banco, r.categoria, r.nombre_original, r.cuenta_ultimos4, r.tipo_cuenta,
                    r.importe, r.importe_estado, r.asociacion_estado, r.asociacion_metodo, pe.descripcion AS periodo, pe.fecha_fin,
                    l.numero AS lote_numero, l.id AS lote_id
               FROM registros_tesoreria r
               JOIN importaciones_normalizadas n ON n.id = r.importacion_id AND n.estado = 'VIGENTE'
               JOIN periodos_nomina pe ON pe.id = r.periodo_id JOIN lotes_importacion l ON l.id = r.lote_id
              WHERE r.empleado_id IN (SELECT x.id FROM empleados x WHERE x.id = :id OR x.fusionado_en_id = :idc) ORDER BY pe.fecha_fin DESC, l.numero, r.archivo_id LIMIT 300",
            ['id' => $id, 'idc' => $id]
        );
    }

    /** @return list<array<string,mixed>> sin HMAC ni texto cifrado */
    public function accounts(int $id): array
    {
        return $this->db->fetchAll(
            'SELECT c.id, c.uso, c.beneficiario_nombre, c.tipo, c.cuenta_ultimos4, c.banco, c.origen, c.estado, c.confirmado_at,
                    c.confirmacion_motivo, c.primera_vista_at, c.ultima_vista_at, c.veces_vista, c.desactivado_at, c.desactivacion_motivo,
                    u.username AS confirmado_por_usuario, p1.descripcion AS primer_periodo, p2.descripcion AS ultimo_periodo,
                    r.archivo_id AS origen_archivo_id, r.fila_origen AS origen_fila
               FROM cuentas_bancarias c
               LEFT JOIN usuarios u ON u.id = c.confirmado_por
               JOIN periodos_nomina p1 ON p1.id = c.primer_periodo_id JOIN periodos_nomina p2 ON p2.id = c.ultimo_periodo_id
               JOIN registros_tesoreria r ON r.id = c.origen_registro_id
              WHERE c.empleado_id IN (SELECT x.id FROM empleados x WHERE x.id = :id OR x.fusionado_en_id = :idc) ORDER BY c.estado, c.id',
            ['id' => $id, 'idc' => $id]
        );
    }

    /** @return list<array<string,mixed>> */
    public function incidents(int $id): array
    {
        return $this->db->fetchAll(
            "SELECT i.* FROM incidencias_identidad i
              WHERE i.empleado_id IN (SELECT x.id FROM empleados x WHERE x.id = :id OR x.fusionado_en_id = :id4)
                 OR (i.registro_tipo = 'RRHH' AND i.registro_id IN (SELECT id FROM registros_rrhh WHERE empleado_id IN
                        (SELECT x.id FROM empleados x WHERE x.id = :id2 OR x.fusionado_en_id = :id5)))
                 OR (i.registro_tipo = 'TESORERIA' AND i.registro_id IN (SELECT id FROM registros_tesoreria WHERE empleado_id IN
                        (SELECT x.id FROM empleados x WHERE x.id = :id3 OR x.fusionado_en_id = :id6)))
              ORDER BY (i.estado = 'ABIERTA') DESC, i.id DESC LIMIT 200",
            ['id' => $id, 'id2' => $id, 'id3' => $id, 'id4' => $id, 'id5' => $id, 'id6' => $id]
        );
    }

    /** Auditoría relacionada con el empleado (entidad empleados o cuentas del empleado). @return list<array<string,mixed>> */
    public function auditTrail(int $id): array
    {
        return $this->db->fetchAll(
            "SELECT id, creado_at, username, origen, accion, entidad, entidad_id FROM auditoria
              WHERE (entidad = 'empleados' AND entidad_id IN (SELECT x.id::text FROM empleados x WHERE x.id = :idn OR x.fusionado_en_id = :idn2))
                 OR (entidad = 'cuentas_bancarias' AND entidad_id IN (SELECT id::text FROM cuentas_bancarias WHERE empleado_id IN
                        (SELECT x.id FROM empleados x WHERE x.id = :id2 OR x.fusionado_en_id = :id4)))
                 OR (accion LIKE 'asociaciones.%' AND contexto->>'empleado_id' = :id3)
              ORDER BY secuencia DESC LIMIT 100",
            ['idn' => $id, 'idn2' => $id, 'id2' => $id, 'id4' => $id, 'id3' => (string) $id]
        );
    }

    /**
     * Corrección manual de datos del maestro (no del número ni del origen). Exige motivo; cada campo
     * cambiado va a empleado_historial (fuente MANUAL). RFC/CURP: se normalizan; un valor ya usado por otro
     * empleado se rechaza (no hay fusión en esta fase).
     * @param array<string,string> $input
     */
    public function update(CurrentUser $actor, int $id, array $input, string $motivo): int
    {
        $motivo = PeriodService::requiredReason($motivo);
        return $this->db->transaction(function () use ($actor, $id, $input, $motivo): int {
            $e = $this->db->fetchOne('SELECT * FROM empleados WHERE id = :id FOR UPDATE', ['id' => $id]);
            if ($e === null) {
                throw HttpException::notFound();
            }
            $new = [];
            $errors = [];
            foreach (self::EDITABLE as $k) {
                if (!array_key_exists($k, $input)) {
                    continue;
                }
                // Sin permiso sensible no se consultan ni se sobrescriben a ciegas identificadores personales
                if (in_array($k, ['rfc', 'curp', 'issemym'], true) && !$actor->can('empleados.ver_sensible')) {
                    if (trim((string) $input[$k]) !== '') {
                        throw HttpException::forbidden();
                    }
                    continue;
                }
                $v = trim((string) $input[$k]);
                if ($k === 'activo') {
                    $v = $v === '1' ? 'true' : 'false';
                    if ($v !== ($e['activo'] ? 'true' : 'false')) {
                        $new[$k] = $v;
                    }
                    continue;
                }
                if (in_array($k, ['rfc', 'curp'], true)) {
                    $v = self::normalizeId($v);
                    if ($v !== '' && !preg_match($k === 'rfc' ? self::RFC_RE : self::CURP_RE, $v)) {
                        $errors[$k] = $k === 'rfc' ? 'El RFC no tiene una estructura válida.' : 'La CURP no tiene una estructura válida.';
                        continue;
                    }
                    if ($v !== '' && $v !== $e[$k] && (bool) $this->db->fetchValue("SELECT EXISTS (SELECT 1 FROM empleados WHERE $k = :v AND id <> :id)",
                            ['v' => $v, 'id' => $id])) {
                        $errors[$k] = 'Ese valor ya pertenece a otro empleado. Registre una incidencia; la fusión no está disponible.';
                        continue;
                    }
                }
                $max = $k === 'observaciones' ? 2000 : ($k === 'issemym' ? 40 : 100);
                if (mb_strlen($v) > $max) {
                    $errors[$k] = "Máximo $max caracteres.";
                    continue;
                }
                $v = $v === '' ? null : $v;
                if ($v !== $e[$k]) {
                    $new[$k] = $v;
                }
            }
            if ($errors !== []) {
                throw new ValidationException($errors);
            }
            if ($new === []) {
                return 0;
            }
            $sets = [];
            $params = ['id' => $id, 'u' => $actor->id];
            foreach ($new as $k => $v) {
                $sets[] = $k === 'activo' ? "activo = CAST(:$k AS boolean)" : "$k = :$k";
                $params[$k] = $v;
            }
            $this->db->execute('UPDATE empleados SET ' . implode(', ', $sets) . ', actualizado_por = :u WHERE id = :id', $params);
            $before = [];
            $after = [];
            foreach ($new as $k => $v) {
                $old = $k === 'activo' ? ($e['activo'] ? 'true' : 'false') : $e[$k];
                $this->db->execute(
                    "INSERT INTO empleado_historial (empleado_id, campo, valor_anterior, valor_nuevo, fuente, usuario_id, motivo)
                     VALUES (:e, :c, :a, :n, 'MANUAL', :u, :m)",
                    ['e' => $id, 'c' => $k, 'a' => $old === null ? null : mb_substr((string) $old, 0, 300),
                     'n' => $v === null ? null : mb_substr((string) $v, 0, 300), 'u' => $actor->id, 'm' => $motivo]
                );
                $sensitive = in_array($k, ['rfc', 'curp', 'issemym'], true);
                $before[$k] = $sensitive ? AccountVault::maskId($old === null ? null : (string) $old) : $old;
                $after[$k] = $sensitive ? AccountVault::maskId($v) : $v;
            }
            $this->audit->record('empleados.editar', 'empleados', $id, $before, $after, ['motivo' => $motivo, 'numero_empleado' => $e['numero_empleado']]);
            return count($new);
        });
    }

    /** Revela el número completo de una cuenta (permiso cuentas.ver_sensible). Auditado SIN el número. */
    public function revealAccount(CurrentUser $actor, int $empleadoId, int $cuentaId): string
    {
        if (!$actor->can('cuentas.ver_sensible') || !$actor->can('empleados.ver')) {
            throw HttpException::forbidden();
        }
        // FASE 9 §39: revelar CLABE completas es excepcional; 20 por hora por usuario (también cuentan los intentos fallidos)
        \ConciliaRH\Domain\Security\Throttle::check($this->db, $actor->id, ['cuentas.revelar', 'cuentas.revelar_fallido'], 20, 3600,
            'Alcanzó el máximo de cuentas reveladas por hora. Si necesita más, solicítelo a un administrador.');
        $c = $this->db->fetchOne(
            'SELECT id, cuenta_cifrada, key_id, cuenta_ultimos4, uso FROM cuentas_bancarias
              WHERE id = :c AND empleado_id IN (SELECT x.id FROM empleados x WHERE x.id = :e OR x.fusionado_en_id = :e2)',
            ['c' => $cuentaId, 'e' => $empleadoId, 'e2' => $empleadoId]
        );
        if ($c === null) {
            throw HttpException::notFound();
        }
        $blob = is_resource($c['cuenta_cifrada']) ? (string) stream_get_contents($c['cuenta_cifrada']) : (string) $c['cuenta_cifrada'];
        try {
            $plain = $this->vault->reveal($blob, (string) $c['key_id']);
            // El texto cifrado debe corresponder a ESTA cuenta (defensa ante un intercambio de valores entre filas)
            if (!str_ends_with($plain, (string) $c['cuenta_ultimos4'])) {
                throw new RuntimeException('El valor cifrado no corresponde a la cuenta.');
            }
        } catch (RuntimeException) {
            $this->audit->record('cuentas.revelar_fallido', 'cuentas_bancarias', $cuentaId, null, null,
                ['empleado_id' => $empleadoId, 'key_id' => $c['key_id']]);
            throw new ValidationException(['cuenta' => 'La cuenta no puede descifrarse con las llaves configuradas.']);
        }
        $this->audit->record('cuentas.revelar', 'cuentas_bancarias', $cuentaId, null, null,
            ['empleado_id' => $empleadoId, 'uso' => $c['uso'], 'ultimos4' => $c['cuenta_ultimos4']]);
        return $plain;
    }

    /** Desactiva una cuenta (no se borra ni se reactiva: para volver a usarla hay que confirmarla de nuevo). */
    public function deactivateAccount(CurrentUser $actor, int $empleadoId, int $cuentaId, string $motivo): void
    {
        $motivo = PeriodService::requiredReason($motivo);
        $this->db->transaction(function () use ($actor, $empleadoId, $cuentaId, $motivo): void {
            $c = $this->db->fetchOne('SELECT id, estado, uso, cuenta_ultimos4 FROM cuentas_bancarias
                                        WHERE id = :c AND empleado_id IN (SELECT x.id FROM empleados x WHERE x.id = :e OR x.fusionado_en_id = :e2) FOR UPDATE',
                ['c' => $cuentaId, 'e' => $empleadoId, 'e2' => $empleadoId]);
            if ($c === null) {
                throw HttpException::notFound();
            }
            if ($c['estado'] !== 'ACTIVA') {
                throw new ValidationException(['cuenta' => 'La cuenta ya está inactiva.']);
            }
            $this->db->execute(
                "UPDATE cuentas_bancarias SET estado = 'INACTIVA', desactivado_por = :u, desactivado_at = now(), desactivacion_motivo = :m
                  WHERE id = :c",
                ['u' => $actor->id, 'm' => $motivo, 'c' => $cuentaId]
            );
            $this->audit->record('cuentas.desactivar', 'cuentas_bancarias', $cuentaId, ['estado' => 'ACTIVA'], ['estado' => 'INACTIVA'],
                ['empleado_id' => $empleadoId, 'uso' => $c['uso'], 'ultimos4' => $c['cuenta_ultimos4'], 'motivo' => $motivo]);
        });
    }

    /** Enmascara RFC/CURP/ISSEMYM para quien no tiene `empleados.ver_sensible`. @param array<string,mixed> $r @return array<string,mixed> */
    public function mask(CurrentUser $user, array $r): array
    {
        if ($user->can('empleados.ver_sensible')) {
            return $r;
        }
        foreach (['rfc', 'curp', 'issemym', 'rfc_original', 'curp_original'] as $k) {
            if (array_key_exists($k, $r)) {
                $r[$k] = $r[$k] === null ? null : AccountVault::maskId((string) $r[$k]);
            }
        }
        return $r;
    }
}
