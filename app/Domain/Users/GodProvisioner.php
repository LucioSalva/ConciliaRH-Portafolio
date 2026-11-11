<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Users;

use ConciliaRH\Core\Database;
use ConciliaRH\Domain\Audit\AuditContext;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\PasswordHasher;
use ConciliaRH\Domain\Auth\PasswordPolicy;
use ConciliaRH\Domain\ValidationException;
use RuntimeException;

/**
 * Alta del usuario GOD. Sólo se invoca desde la CLI (bin/create-god.php).
 * - No existe ruta web que asigne el rol GOD.
 * - Se niega a crear un segundo GOD activo.
 * - La acción queda auditada con origen CLI.
 */
final class GodProvisioner
{
    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly AuditLogger $audit,
        private readonly AuditContext $ctx,
    ) {
    }

    public function create(string $username, string $nombre, string $password, int $minLength = 12, string $osUser = ''): int
    {
        $username = mb_strtolower(trim($username));
        $nombre = UserService::cleanName($nombre);

        return $this->db->transaction(function () use ($username, $nombre, $password, $minLength, $osUser): int {
            // Serializa altas concurrentes de GOD.
            $this->db->execute("SELECT pg_advisory_xact_lock(hashtext('conciliarh.create_god'))");
            if ($this->users->countActiveGods() > 0) {
                throw new RuntimeException('Ya existe un usuario GOD activo. No se crea otro.');
            }
            $errors = [];
            if (!preg_match(UserService::USERNAME_PATTERN, $username)) {
                $errors['username'] = 'Formato inválido (3-50: minúsculas, números, . _ -).';
            } elseif ($this->users->usernameExists($username)) {
                $errors['username'] = 'El nombre de usuario ya existe.';
            }
            if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 150) {
                $errors['nombre'] = 'El nombre debe tener entre 3 y 150 caracteres.';
            }
            if (($pw = (new PasswordPolicy($minLength))->validate($password, $username)) !== null) {
                $errors['password'] = $pw;
            }
            if ($errors !== []) {
                throw new ValidationException($errors);
            }

            $id = (int) $this->db->fetchValue(
                'INSERT INTO usuarios (username, nombre, password_hash, debe_cambiar_password, password_cambiado_at)
                 VALUES (:u, :n, :h, false, now()) RETURNING id',
                ['u' => $username, 'n' => $nombre, 'h' => $this->hasher->hash($password)]
            );
            $this->db->execute(
                "INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = 'GOD'",
                ['u' => $id]
            );
            $this->ctx->origin = 'CLI';
            $this->ctx->setActor($id, $username, 'GOD');
            $this->audit->record('usuarios.crear_god', 'usuarios', $id, null, ['username' => $username, 'nombre' => $nombre, 'roles' => ['GOD']], [
                'usuario_so' => $osUser,
                'host' => gethostname() ?: null,
            ]);
            return $id;
        });
    }
}
