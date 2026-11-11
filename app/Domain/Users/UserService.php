<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Users;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Auth\PasswordHasher;
use ConciliaRH\Domain\Auth\PasswordPolicy;
use ConciliaRH\Domain\Settings\SettingsService;
use ConciliaRH\Domain\ValidationException;

/**
 * Reglas de gobierno de usuarios:
 *  - El rol GOD nunca se asigna ni se retira desde la web (sólo CLI).
 *  - Roles `protegido` (GOD, ADMIN) sólo los asigna GOD.
 *  - Un usuario no-GOD no puede gestionar a usuarios con roles protegidos.
 *  - Un actor no-GOD sólo asigna roles cuyos permisos ya posee (evita escalar con roles personalizados) y sólo administra
 *    (contraseña, activación, sesiones, borrado) a usuarios sin permisos que él no tenga (evita tomar una cuenta superior).
 *  - Nadie puede desactivarse, eliminarse ni cambiar sus propios roles.
 *  - Desactivar, eliminar o restablecer contraseña revoca sus sesiones.
 *  - Toda acción queda auditada (sin contraseñas ni hashes).
 */
final class UserService
{
    public const USERNAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{2,49}$/';

    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {
    }

    public function policy(): PasswordPolicy
    {
        return new PasswordPolicy($this->settings->int('seguridad.password_longitud_minima', 12));
    }

    /**
     * Roles que el actor puede asignar: nunca GOD; los protegidos sólo si el actor es GOD;
     * y, para no-GOD, sólo roles cuyos permisos sean un subconjunto de los del actor.
     * @return list<array<string,mixed>>
     */
    public function assignableRoles(CurrentUser $actor): array
    {
        $perms = array_values(array_filter($actor->permissions(), static fn (string $p) => preg_match('/^[a-z_]+\.[a-z_]+$/', $p) === 1));
        return $this->db->fetchAll(
            "SELECT r.id, r.codigo, r.nombre, r.descripcion, r.protegido FROM roles r
              WHERE r.activo AND r.codigo <> 'GOD'
                AND (:god1 OR NOT r.protegido)
                AND (:god2 OR NOT EXISTS (
                        SELECT 1 FROM rol_permiso rp JOIN permisos p ON p.id = rp.permiso_id
                         WHERE rp.rol_id = r.id AND NOT (p.codigo = ANY (CAST(:perms AS text[])))))
              ORDER BY r.protegido DESC, r.codigo",
            ['god1' => $actor->isGod(), 'god2' => $actor->isGod(), 'perms' => '{' . implode(',', $perms) . '}']
        );
    }

    /** @param array<string,mixed> $target */
    public function canManage(CurrentUser $actor, array $target): bool
    {
        $targetRoles = $target['roles'] ?? [];
        if (in_array('GOD', $targetRoles, true)) {
            return $actor->isGod() && $actor->id === (int) $target['id'];
        }
        if ($actor->isGod()) {
            return true;
        }
        $protected = array_column($this->db->fetchAll('SELECT codigo FROM roles WHERE protegido'), 'codigo');
        if (array_intersect($targetRoles, $protected) !== []) {
            return false;
        }
        // Misma regla que assignableRoles(): sólo se administra (restablecer contraseña, desactivar, eliminar, cerrar sesiones)
        // a quien NO tenga permisos que el actor no posee. Si no, restablecerle la contraseña sería tomar su cuenta y sus permisos.
        $perms = array_values(array_filter($actor->permissions(), static fn (string $p) => preg_match('/^[a-z_]+\.[a-z_]+$/', $p) === 1));
        return !(bool) $this->db->fetchValue(
            "SELECT EXISTS (SELECT 1 FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id AND r.activo
                              JOIN rol_permiso rp ON rp.rol_id = r.id JOIN permisos p ON p.id = rp.permiso_id
                             WHERE ur.usuario_id = :u AND NOT (p.codigo = ANY (CAST(:perms AS text[]))))",
            ['u' => (int) $target['id'], 'perms' => '{' . implode(',', $perms) . '}']);
    }

    /** @return array<string,mixed> */
    public function findManageable(CurrentUser $actor, int $id): array
    {
        $target = $this->users->find($id);
        if ($target === null) {
            throw HttpException::notFound();
        }
        if (!$this->canManage($actor, $target)) {
            throw HttpException::forbidden();
        }
        return $target;
    }

    /** @param list<string> $roleCodes */
    public function create(CurrentUser $actor, string $username, string $nombre, string $password, array $roleCodes): int
    {
        $username = mb_strtolower(trim($username));
        $nombre = self::cleanName($nombre);
        $errors = [];
        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            $errors['username'] = 'Use de 3 a 50 caracteres: minúsculas, números, punto, guion o guion bajo; debe iniciar con letra o número.';
        } elseif ($this->users->usernameExists($username)) {
            $errors['username'] = 'El nombre de usuario ya existe.';
        }
        if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 150) {
            $errors['nombre'] = 'El nombre debe tener entre 3 y 150 caracteres.';
        }
        if (($pwError = $this->policy()->validate($password, $username)) !== null) {
            $errors['password'] = $pwError;
        }
        $roleIds = $this->resolveAssignableRoles($actor, $roleCodes, $errors);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->db->transaction(function () use ($actor, $username, $nombre, $password, $roleIds, $roleCodes): int {
            try {
                $id = (int) $this->db->fetchValue(
                    'INSERT INTO usuarios (username, nombre, password_hash, debe_cambiar_password, creado_por)
                     VALUES (:u, :n, :h, true, :by) RETURNING id',
                    ['u' => $username, 'n' => $nombre, 'h' => $this->hasher->hash($password), 'by' => $actor->id]
                );
            } catch (\PDOException $e) {
                // Alta simultánea con el mismo username (entre la verificación y el INSERT)
                if ($e->getCode() === '23505') {
                    throw new ValidationException(['username' => 'El nombre de usuario ya existe.']);
                }
                throw $e;
            }
            $this->syncRoles($actor, $id, $roleIds);
            $this->audit->record('usuarios.crear', 'usuarios', $id, null, [
                'username' => $username, 'nombre' => $nombre, 'roles' => $roleCodes, 'debe_cambiar_password' => true,
            ]);
            return $id;
        });
    }

    /** @param list<string> $roleCodes */
    public function update(CurrentUser $actor, int $id, string $nombre, array $roleCodes): void
    {
        $target = $this->findManageable($actor, $id);
        $this->assertNotDeleted($target);
        $nombre = self::cleanName($nombre);
        $errors = [];
        if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 150) {
            $errors['nombre'] = 'El nombre debe tener entre 3 y 150 caracteres.';
        }
        $isGodTarget = in_array('GOD', $target['roles'], true);
        $isSelf = $actor->id === $id;
        if ($isSelf && !$isGodTarget) {
            $requested = array_values(array_unique($roleCodes));
            sort($requested);
            $current = $target['roles'];
            sort($current);
            if ($requested !== $current) {
                $errors['roles'] = 'No puede modificar sus propios roles.';
            }
        }
        // Los roles de la cuenta GOD y los propios no se modifican desde la web.
        $keepRoles = $isGodTarget || $isSelf;
        $roleIds = $keepRoles ? [] : $this->resolveAssignableRoles($actor, $roleCodes, $errors);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->db->transaction(function () use ($actor, $id, $nombre, $roleIds, $roleCodes, $target, $keepRoles): void {
            $this->db->execute('UPDATE usuarios SET nombre = :n WHERE id = :id', ['n' => $nombre, 'id' => $id]);
            if (!$keepRoles) {
                $this->syncRoles($actor, $id, $roleIds);
            }
            // Relectura desde la BD y ordenamiento en PHP en ambos lados (independiente de la collation)
            $oldRoles = $target['roles'];
            sort($oldRoles);
            $newRoles = (new UserRepository($this->db))->roleCodes($id);
            sort($newRoles);
            $before = ['nombre' => $target['nombre'], 'roles' => $oldRoles];
            $after = ['nombre' => $nombre, 'roles' => $newRoles];
            if ($before !== $after) {
                $this->audit->record('usuarios.editar', 'usuarios', $id, $before, $after);
            }
            if ($oldRoles !== $newRoles) {
                // Cambió su nivel de privilegio: obligar a re-autenticarse.
                $this->users->revokeSessions($id, $actor->id, 'CAMBIO_DE_ROLES');
            }
        });
    }

    public function setActive(CurrentUser $actor, int $id, bool $active): void
    {
        $target = $this->findManageable($actor, $id);
        $this->assertNotDeleted($target);
        if ($actor->id === $id) {
            throw new ValidationException(['activo' => 'No puede desactivar su propia cuenta.']);
        }
        if ($target['activo'] === $active) {
            return;
        }
        $this->db->transaction(function () use ($actor, $id, $active): void {
            $this->db->execute('UPDATE usuarios SET activo = :a WHERE id = :id', ['a' => $active, 'id' => $id]);
            if (!$active) {
                $this->users->revokeSessions($id, $actor->id, 'USUARIO_DESACTIVADO');
            }
            $this->audit->record($active ? 'usuarios.activar' : 'usuarios.desactivar', 'usuarios', $id, ['activo' => !$active], ['activo' => $active]);
        });
    }

    public function delete(CurrentUser $actor, int $id, string $reason): void
    {
        $target = $this->findManageable($actor, $id);
        $this->assertNotDeleted($target);
        $reason = trim($reason);
        if ($actor->id === $id) {
            throw new ValidationException(['motivo' => 'No puede eliminar su propia cuenta.']);
        }
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new ValidationException(['motivo' => 'Indique el motivo de la eliminación (5 a 500 caracteres).']);
        }
        $this->db->transaction(function () use ($actor, $id, $reason, $target): void {
            $this->db->execute(
                'UPDATE usuarios SET activo = false, deleted_at = now(), deleted_by = :by WHERE id = :id',
                ['by' => $actor->id, 'id' => $id]
            );
            $this->users->revokeSessions($id, $actor->id, 'USUARIO_ELIMINADO');
            $this->audit->record('usuarios.eliminar', 'usuarios', $id, ['activo' => $target['activo'], 'deleted_at' => null], ['activo' => false, 'eliminado' => true], ['motivo' => $reason]);
        });
    }

    public function resetPassword(CurrentUser $actor, int $id, string $password): void
    {
        $target = $this->findManageable($actor, $id);
        $this->assertNotDeleted($target);
        if ($actor->id === $id) {
            throw new ValidationException(['password' => 'Para su propia cuenta use "Cambiar mi contraseña".']);
        }
        if (($err = $this->policy()->validate($password, (string) $target['username'])) !== null) {
            throw new ValidationException(['password' => $err]);
        }
        $this->db->transaction(function () use ($actor, $id, $password): void {
            $this->db->execute(
                'UPDATE usuarios SET password_hash = :h, debe_cambiar_password = true, password_cambiado_at = now() WHERE id = :id',
                ['h' => $this->hasher->hash($password), 'id' => $id]
            );
            $this->users->revokeSessions($id, $actor->id, 'PASSWORD_RESTABLECIDO');
            $this->audit->record('usuarios.restablecer_password', 'usuarios', $id, null, ['debe_cambiar_password' => true]);
        });
    }

    public function revokeSessions(CurrentUser $actor, int $id): int
    {
        $target = $this->findManageable($actor, $id);
        $count = $this->users->revokeSessions($id, $actor->id, 'REVOCACION_MANUAL');
        $this->audit->record('usuarios.cerrar_sesiones', 'usuarios', $id, null, null, ['sesiones_revocadas' => $count, 'username' => $target['username']]);
        return $count;
    }

    public function changeOwnPassword(CurrentUser $user, string $current, string $new, string $confirm, string $currentSessionHash): void
    {
        $hash = (string) $this->db->fetchValue('SELECT password_hash FROM usuarios WHERE id = :id', ['id' => $user->id]);
        if (!$this->hasher->verify($current, $hash)) {
            throw new ValidationException(['password_actual' => 'La contraseña actual no es correcta.']);
        }
        if ($new !== $confirm) {
            throw new ValidationException(['password_confirmacion' => 'La confirmación no coincide.']);
        }
        if ($this->hasher->verify($new, $hash)) {
            throw new ValidationException(['password_nueva' => 'La nueva contraseña debe ser distinta de la actual.']);
        }
        if (($err = $this->policy()->validate($new, $user->username)) !== null) {
            throw new ValidationException(['password_nueva' => $err]);
        }
        $this->db->transaction(function () use ($user, $new, $currentSessionHash): void {
            $this->db->execute(
                'UPDATE usuarios SET password_hash = :h, debe_cambiar_password = false, password_cambiado_at = now() WHERE id = :id',
                ['h' => $this->hasher->hash($new), 'id' => $user->id]
            );
            // Cierra cualquier otra sesión abierta con la contraseña anterior.
            $this->users->revokeSessions($user->id, $user->id, 'CAMBIO_PASSWORD', $currentSessionHash);
            $this->audit->record('usuarios.cambiar_password_propio', 'usuarios', $user->id);
        });
    }

    /**
     * @param list<string> $roleCodes
     * @param array<string,string> $errors
     * @return list<int>
     */
    private function resolveAssignableRoles(CurrentUser $actor, array $roleCodes, array &$errors): array
    {
        $roleCodes = array_values(array_unique($roleCodes));
        if ($roleCodes === []) {
            $errors['roles'] = 'Asigne al menos un rol.';
            return [];
        }
        $assignable = [];
        foreach ($this->assignableRoles($actor) as $r) {
            $assignable[$r['codigo']] = (int) $r['id'];
        }
        $ids = [];
        foreach ($roleCodes as $code) {
            if (!isset($assignable[$code])) {
                $errors['roles'] = 'Uno o más roles no pueden ser asignados por usted.';
                return [];
            }
            $ids[] = $assignable[$code];
        }
        return $ids;
    }

    /**
     * Sincroniza SÓLO los roles que el actor puede asignar: los roles fuera de su alcance
     * (GOD, protegidos para no-GOD, inactivos o con permisos que el actor no tiene) se conservan.
     * @param list<int> $roleIds
     */
    private function syncRoles(CurrentUser $actor, int $userId, array $roleIds): void
    {
        $scope = array_map(static fn (array $r) => (int) $r['id'], $this->assignableRoles($actor));
        foreach (array_diff($scope, $roleIds) as $rid) {
            $this->db->execute('DELETE FROM usuario_rol WHERE usuario_id = :u AND rol_id = :r', ['u' => $userId, 'r' => $rid]);
        }
        foreach ($roleIds as $rid) {
            $this->db->execute(
                'INSERT INTO usuario_rol (usuario_id, rol_id, asignado_por) VALUES (:u, :r, :by) ON CONFLICT DO NOTHING',
                ['u' => $userId, 'r' => $rid, 'by' => $actor->id]
            );
        }
    }

    /** @param array<string,mixed> $target */
    private function assertNotDeleted(array $target): void
    {
        if ($target['deleted_at'] !== null) {
            throw new ValidationException(['usuario' => 'El usuario fue eliminado y no puede modificarse.']);
        }
    }

    public static function cleanName(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }
}
