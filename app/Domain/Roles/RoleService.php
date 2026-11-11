<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Roles;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\ValidationException;

/**
 * Roles y matriz de permisos. Crear roles y modificar permisos es exclusivo de GOD
 * (permiso solo_god `roles.administrar`). El rol GOD no se edita: siempre tiene todo.
 * Los permisos solo_god nunca pueden asignarse a otro rol (lo impide también un trigger).
 */
final class RoleService
{
    public function __construct(private readonly Database $db, private readonly AuditLogger $audit)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->fetchAll(
            "SELECT r.*, (SELECT count(*) FROM rol_permiso rp WHERE rp.rol_id = r.id) AS total_permisos,
                    (SELECT count(*) FROM usuario_rol ur JOIN usuarios u ON u.id = ur.usuario_id
                      WHERE ur.rol_id = r.id AND u.deleted_at IS NULL) AS total_usuarios
               FROM roles r ORDER BY r.es_sistema DESC, r.protegido DESC, r.codigo"
        );
    }

    /** @return array<string,mixed> */
    public function find(int $id): array
    {
        $role = $this->db->fetchOne('SELECT * FROM roles WHERE id = :id', ['id' => $id]);
        if ($role === null) {
            throw HttpException::notFound();
        }
        $role['permisos'] = array_map('intval', array_column(
            $this->db->fetchAll('SELECT permiso_id FROM rol_permiso WHERE rol_id = :id', ['id' => $id]),
            'permiso_id'
        ));
        return $role;
    }

    /** @return array<string,list<array<string,mixed>>> permisos agrupados por módulo */
    public function permissionsByModule(): array
    {
        $grouped = [];
        foreach ($this->db->fetchAll('SELECT * FROM permisos ORDER BY modulo, codigo') as $p) {
            $grouped[$p['modulo']][] = $p;
        }
        return $grouped;
    }

    public function create(CurrentUser $actor, string $codigo, string $nombre, string $descripcion, bool $protegido = false): int
    {
        $this->assertGod($actor);
        $codigo = strtoupper(trim($codigo));
        $nombre = trim($nombre);
        $descripcion = trim($descripcion);
        $errors = [];
        if (!preg_match('/^[A-Z][A-Z0-9_]{1,39}$/', $codigo)) {
            $errors['codigo'] = 'Use de 2 a 40 caracteres: mayúsculas, números y guion bajo.';
        } elseif ($this->db->fetchValue('SELECT 1 FROM roles WHERE codigo = :c', ['c' => $codigo])) {
            $errors['codigo'] = 'El código de rol ya existe.';
        }
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 80) {
            $errors['nombre'] = 'El nombre debe tener entre 2 y 80 caracteres.';
        }
        if (mb_strlen($descripcion) > 300) {
            $errors['descripcion'] = 'Máximo 300 caracteres.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $this->db->transaction(function () use ($codigo, $nombre, $descripcion, $protegido): int {
            try {
                $id = (int) $this->db->fetchValue(
                    'INSERT INTO roles (codigo, nombre, descripcion, protegido) VALUES (:c, :n, :d, :p) RETURNING id',
                    ['c' => $codigo, 'n' => $nombre, 'd' => $descripcion === '' ? null : $descripcion, 'p' => $protegido]
                );
            } catch (\PDOException $e) {
                if ($e->getCode() === '23505') {
                    throw new ValidationException(['codigo' => 'El código de rol ya existe.']);
                }
                throw $e;
            }
            $this->audit->record('roles.crear', 'roles', $id, null, ['codigo' => $codigo, 'nombre' => $nombre, 'protegido' => $protegido]);
            return $id;
        });
    }

    /** @param list<int> $permissionIds */
    public function updatePermissions(CurrentUser $actor, int $roleId, array $permissionIds): void
    {
        $this->assertGod($actor);
        $role = $this->find($roleId);
        if ($role['codigo'] === 'GOD') {
            throw new ValidationException(['permisos' => 'El rol GOD siempre conserva todos los permisos.']);
        }
        $valid = [];
        foreach ($this->db->fetchAll('SELECT id, codigo, solo_god FROM permisos') as $p) {
            $valid[(int) $p['id']] = $p;
        }
        $permissionIds = array_values(array_unique(array_map('intval', $permissionIds)));
        foreach ($permissionIds as $pid) {
            if (!isset($valid[$pid])) {
                throw new ValidationException(['permisos' => 'Permiso inexistente.']);
            }
            if ($valid[$pid]['solo_god']) {
                throw new ValidationException(['permisos' => 'El permiso ' . $valid[$pid]['codigo'] . ' es exclusivo de GOD.']);
            }
        }
        $before = $role['permisos'];
        sort($before);
        sort($permissionIds);
        if ($before === $permissionIds) {
            return;
        }
        $codes = static fn (array $ids): array => array_values(array_map(static fn (int $id) => $valid[$id]['codigo'], $ids));

        $this->db->transaction(function () use ($actor, $roleId, $permissionIds, $before, $codes, $role): void {
            $removed = array_values(array_diff($before, $permissionIds));
            $added = array_values(array_diff($permissionIds, $before));
            foreach ($removed as $pid) {
                $this->db->execute('DELETE FROM rol_permiso WHERE rol_id = :r AND permiso_id = :p', ['r' => $roleId, 'p' => $pid]);
            }
            foreach ($added as $pid) {
                $this->db->execute(
                    'INSERT INTO rol_permiso (rol_id, permiso_id, asignado_por) VALUES (:r, :p, :by)',
                    ['r' => $roleId, 'p' => $pid, 'by' => $actor->id]
                );
            }
            $this->audit->record('roles.editar_permisos', 'roles', $roleId,
                ['permisos' => $codes($before)],
                ['permisos' => $codes($permissionIds)],
                ['rol' => $role['codigo'], 'agregados' => $codes($added), 'retirados' => $codes($removed)]
            );
        });
    }

    private function assertGod(CurrentUser $actor): void
    {
        if (!$actor->can('roles.administrar')) {
            throw HttpException::forbidden();
        }
    }
}
