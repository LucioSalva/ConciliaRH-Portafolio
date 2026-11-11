<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Audit;

use ConciliaRH\Core\Database;
use DateTimeImmutable;

/** Consultas de sólo lectura sobre la auditoría. */
final class AuditRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Acciones que registran un intento FALLIDO o una operación rechazada por el sistema (la auditoría no tiene columna de
     * resultado). Lista cerrada y exacta: una decisión de negocio que «rechaza» (p. ej. asociaciones.rechazar_sugerencia,
     * empleados.fusion_rechazar) fue EXITOSA y no aparece aquí. Toda acción de falla nueva debe agregarse a esta lista.
     */
    public const FALLOS = [
        'archivos.integridad_fallida', 'archivos.rechazar_carga', 'auth.bloqueo', 'cuentas.revelar_fallido',
        'cuentas.rotacion_error', 'cuentas.rotacion_hmac_error', 'cuentas.rotacion_hmac_rechazada', 'cuentas.rotacion_rechazada',
        'reportes.generar_error', 'reportes.integridad_fallida', 'reportes.rotacion_llave_parcial',
    ];

    public static function failed(string $accion): bool
    {
        return in_array($accion, self::FALLOS, true);
    }

    /**
     * Módulos (prefijo de la acción) para el filtro. «Salto de índice» sobre auditoria_accion_idx: una lectura por acción
     * distinta (decenas), no un recorrido de toda la bitácora en cada carga de /auditoria.
     * @return list<string>
     */
    public function modules(): array
    {
        $mods = [];
        foreach ($this->distinct('accion') as $a) {
            $mods[explode('.', $a, 2)[0]] = true;
        }
        $mods = array_keys($mods);
        sort($mods);
        return array_map('strval', $mods);
    }

    /** @return list<string> entidades auditadas (salto de índice sobre auditoria_entidad_idx) */
    public function entities(): array
    {
        return array_slice($this->distinct('entidad'), 0, 200);
    }

    /** Valores distintos de una columna indexada de auditoria sin recorrerla completa (CTE recursiva). @return list<string> */
    private function distinct(string $col): array
    {
        $col = match ($col) { 'accion' => 'accion', 'entidad' => 'entidad' };   // lista cerrada: nunca texto del usuario
        return array_values(array_map('strval', array_column($this->db->fetchAll(
            "WITH RECURSIVE t(v) AS (
                 (SELECT $col FROM auditoria WHERE $col IS NOT NULL ORDER BY $col LIMIT 1)
                 UNION ALL
                 SELECT (SELECT a.$col FROM auditoria a WHERE a.$col > t.v ORDER BY a.$col LIMIT 1) FROM t WHERE t.v IS NOT NULL)
             SELECT v FROM t WHERE v IS NOT NULL LIMIT 1000"), 'v')));
    }

    /**
     * @param array{q?:string,accion?:string,usuario?:string,desde?:string,hasta?:string,entidad?:string,modulo?:string,origen?:string,resultado?:string} $f
     * @return array{rows:list<array<string,mixed>>,total:int,page:int}
     */
    public function paginate(array $f, int $page, int $perPage): array
    {
        $where = ['TRUE'];
        $p = [];
        if (($f['accion'] ?? '') !== '') {
            $where[] = 'a.accion LIKE :accion';
            $p['accion'] = addcslashes($f['accion'], '%_\\') . '%';
        }
        if (($f['usuario'] ?? '') !== '') {
            $where[] = 'a.username ILIKE :usuario';
            $p['usuario'] = '%' . addcslashes($f['usuario'], '%_\\') . '%';
        }
        if (($f['entidad'] ?? '') !== '') {
            $where[] = 'a.entidad = :entidad';
            $p['entidad'] = $f['entidad'];
        }
        if (($f['modulo'] ?? '') !== '' && preg_match('/^[a-z_]{1,40}$/', $f['modulo'])) {
            $where[] = 'a.accion LIKE :modulo';
            $p['modulo'] = $f['modulo'] . '.%';
        }
        if (in_array($f['origen'] ?? '', ['WEB', 'CLI', 'WORKER', 'SISTEMA'], true)) {
            $where[] = 'a.origen = :origen';
            $p['origen'] = $f['origen'];
        }
        if (in_array($f['resultado'] ?? '', ['EXITOSO', 'FALLIDO'], true)) {
            $where[] = ($f['resultado'] === 'FALLIDO' ? '' : 'NOT ') . "a.accion = ANY(string_to_array(:fallos, ','))";
            $p['fallos'] = implode(',', self::FALLOS);
        }
        if (self::isDate($f['desde'] ?? '')) {
            $where[] = 'a.creado_at >= CAST(:desde AS date)';
            $p['desde'] = $f['desde'];
        }
        if (self::isDate($f['hasta'] ?? '')) {
            $where[] = "a.creado_at < CAST(:hasta AS date) + interval '1 day'";
            $p['hasta'] = $f['hasta'];
        }
        if (($f['q'] ?? '') !== '') {
            $where[] = '(a.entidad_id = :q1 OR a.contexto::text ILIKE :q2 OR a.valor_nuevo::text ILIKE :q3)';
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $p += ['q1' => $f['q'], 'q2' => $like, 'q3' => $like];
        }
        $sql = implode(' AND ', $where);
        $total = (int) $this->db->fetchValue("SELECT count(*) FROM auditoria a WHERE $sql", $p);
        $page = min($page, max(1, (int) ceil($total / $perPage)));
        $rows = $this->db->fetchAll(
            "SELECT a.id, a.secuencia, a.creado_at, a.username, a.roles, a.origen, host(a.ip) AS ip, a.accion,
                    a.entidad, a.entidad_id
               FROM auditoria a WHERE $sql ORDER BY a.secuencia DESC LIMIT :limit OFFSET :offset",
            [...$p, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage]
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page];
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT a.*, host(a.ip) AS ip_texto FROM auditoria a WHERE a.id = :id',
            ['id' => $id]
        );
    }

    /** @return list<string> */
    public function actions(): array
    {
        return array_values(array_map('strval', array_column(
            $this->db->fetchAll('SELECT DISTINCT accion FROM auditoria ORDER BY accion'),
            'accion'
        )));
    }

    /** @return array{integra:bool,registros:int,primer_secuencia_invalida:?int} */
    public function verify(): array
    {
        $r = $this->db->fetchOne('SELECT * FROM fn_auditoria_verificar()');
        return [
            'integra' => (bool) ($r['integra'] ?? false),
            'registros' => (int) ($r['registros'] ?? 0),
            'primer_secuencia_invalida' => isset($r['primer_secuencia_invalida']) ? (int) $r['primer_secuencia_invalida'] : null,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 8): array
    {
        return $this->db->fetchAll(
            'SELECT id, creado_at, username, accion, entidad, entidad_id FROM auditoria ORDER BY secuencia DESC LIMIT :l',
            ['l' => $limit]
        );
    }

    private static function isDate(string $v): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
