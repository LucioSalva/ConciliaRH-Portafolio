<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Users;

use ConciliaRH\Core\Database;

final class UserRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $user = $this->db->fetchOne(
            'SELECT id, username, nombre, activo, debe_cambiar_password, ultimo_acceso_at, created_at,
                    updated_at, deleted_at, password_cambiado_at
               FROM usuarios WHERE id = :id',
            ['id' => $id]
        );
        if ($user !== null) {
            $user['roles'] = $this->roleCodes($id);
        }
        return $user;
    }

    public function usernameExists(string $username): bool
    {
        return (bool) $this->db->fetchValue('SELECT 1 FROM usuarios WHERE username = :u', ['u' => $username]);
    }

    /** @return list<string> */
    public function roleCodes(int $userId): array
    {
        return array_values(array_map('strval', array_column($this->db->fetchAll(
            'SELECT r.codigo FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = :id ORDER BY r.codigo',
            ['id' => $userId]
        ), 'codigo')));
    }

    /**
     * Listado paginado del lado del servidor.
     * @param array{q?:string,rol?:string,estado?:string} $filters
     * @return array{rows:list<array<string,mixed>>,total:int,page:int}
     */
    public function paginate(array $filters, int $page, int $perPage, int $onlineMinutes = 30): array
    {
        $where = ['1=1'];
        $params = [];
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(u.username ILIKE :q1 OR unaccent_simple(u.nombre) ILIKE unaccent_simple(:q2))';
            $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $params['q1'] = $like;
            $params['q2'] = $like;
        }
        if (($filters['rol'] ?? '') !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = u.id AND r.codigo = :rol)';
            $params['rol'] = $filters['rol'];
        }
        $estado = $filters['estado'] ?? 'vigentes';
        $where[] = match ($estado) {
            'activos' => 'u.activo AND u.deleted_at IS NULL',
            'inactivos' => 'NOT u.activo AND u.deleted_at IS NULL',
            'eliminados' => 'u.deleted_at IS NOT NULL',
            'todos' => 'TRUE',
            default => 'u.deleted_at IS NULL',
        };
        $sqlWhere = implode(' AND ', $where);

        $total = (int) $this->db->fetchValue("SELECT count(*) FROM usuarios u WHERE $sqlWhere", $params);
        $page = min($page, max(1, (int) ceil($total / $perPage)));
        $rows = $this->db->fetchAll(
            "SELECT u.id, u.username, u.nombre, u.activo, u.deleted_at, u.ultimo_acceso_at, u.debe_cambiar_password,
                    coalesce((SELECT string_agg(r.codigo, ',' ORDER BY r.codigo) FROM usuario_rol ur
                               JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = u.id), '') AS roles,
                    (SELECT count(*) FROM sesiones s WHERE s.usuario_id = u.id AND s.revocada_at IS NULL
                        AND s.ultima_actividad_at > now() - make_interval(mins => :online)) AS sesiones_activas
               FROM usuarios u WHERE $sqlWhere
              ORDER BY u.deleted_at NULLS FIRST, u.username
              LIMIT :limit OFFSET :offset",
            [...$params, 'online' => $onlineMinutes, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage]
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page];
    }

    public function countActiveGods(): int
    {
        return (int) $this->db->fetchValue(
            "SELECT count(*) FROM usuarios u JOIN usuario_rol ur ON ur.usuario_id = u.id
               JOIN roles r ON r.id = ur.rol_id
              WHERE r.codigo = 'GOD' AND u.activo AND u.deleted_at IS NULL"
        );
    }

    public function revokeSessions(int $userId, ?int $byUserId, string $reason, ?string $exceptSessionHash = null): int
    {
        return $this->db->execute(
            'UPDATE sesiones SET revocada_at = now(), revocada_por = :by, motivo_revocacion = :m
              WHERE usuario_id = :u AND revocada_at IS NULL AND (CAST(:except AS text) IS NULL OR id <> :except2)',
            ['by' => $byUserId, 'm' => $reason, 'u' => $userId, 'except' => $exceptSessionHash, 'except2' => $exceptSessionHash]
        );
    }
}
