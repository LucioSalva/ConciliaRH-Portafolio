<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Auth;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\Logger;
use ConciliaRH\Core\Session\Session;
use ConciliaRH\Domain\Audit\AuditContext;
use ConciliaRH\Domain\Audit\AuditLogger;

/**
 * Autenticación por usuario + contraseña (sin correo).
 * Mensajes genéricos: no revela si el usuario existe, está inactivo o la contraseña es incorrecta.
 */
final class AuthService
{
    public const GENERIC_ERROR = 'Usuario o contraseña incorrectos.';

    public function __construct(
        private readonly Database $db,
        private readonly PasswordHasher $hasher,
        private readonly LoginThrottle $throttle,
        private readonly Session $session,
        private readonly AuditLogger $audit,
        private readonly AuditContext $auditContext,
        private readonly Logger $logger,
        /** true salvo en APP_ENV=development: las cuentas de desarrollo sólo funcionan en desarrollo. */
        private readonly bool $blockDevAccounts = false,
    ) {
    }

    /** @return array{ok:bool,message:?string,user_id:?int} */
    public function attempt(string $username, string $password, ?string $ip): array
    {
        $username = mb_strtolower(trim($username));
        if ($username === '' || $password === '' || mb_strlen($username) > 50 || strlen($password) > 1024) {
            return ['ok' => false, 'message' => self::GENERIC_ERROR, 'user_id' => null];
        }

        // Comprobación + verificación + registro serializados por usuario (evita ráfagas en paralelo
        // que superen el límite por condición de carrera).
        $outcome = $this->db->transaction(function () use ($username, $password, $ip): array {
            $this->throttle->lockSubject($username);
            if ($this->throttle->isLocked($username, $ip)) {
                // Bloqueado: se responde sin ejecutar Argon2 (el bloqueo no debe costar CPU/RAM).
                return ['locked' => true, 'user' => null];
            }
            $user = $this->db->fetchOne(
                'SELECT id, username, password_hash, activo, deleted_at, es_desarrollo FROM usuarios WHERE username = :u',
                ['u' => $username]
            );
            $valid = false;
            if ($user === null) {
                $this->hasher->dummyVerify($password);
            } else {
                $valid = $this->hasher->verify($password, (string) $user['password_hash'])
                    && $user['activo'] === true && $user['deleted_at'] === null
                    // Las cuentas de desarrollo sólo funcionan con APP_ENV=development (lista de permitidos, no de prohibidos)
                    && !($user['es_desarrollo'] === true && $this->blockDevAccounts);
            }
            if (!$valid) {
                $reachedLock = $this->throttle->record($username, $ip, false);
                return ['locked' => false, 'user' => null, 'failed_user' => $user, 'reached_lock' => $reachedLock];
            }
            $this->throttle->record($username, $ip, true);
            return ['locked' => false, 'user' => $user];
        });

        if ($outcome['locked']) {
            $this->logger->security('Intento de login durante bloqueo', ['ip' => $ip, 'usuario_hmac' => substr($this->throttle->key($username), 0, 12)]);
            return [
                'ok' => false,
                'message' => sprintf('Demasiados intentos fallidos. Espere %d minutos e intente de nuevo.', $this->throttle->windowMinutes()),
                'user_id' => null,
            ];
        }
        $user = $outcome['user'];
        if ($user === null) {
            $failed = $outcome['failed_user'];
            $this->logger->security('Login fallido', ['ip' => $ip, 'usuario_hmac' => substr($this->throttle->key($username), 0, 12)]);
            if ($outcome['reached_lock']) {
                // Evento anónimo: el usuario afectado es la ENTIDAD, no el actor (nadie se autenticó)
                $this->auditContext->setActor(null, null, null);
                $this->audit->record('auth.bloqueo', 'usuarios', $failed['id'] ?? null, null, null, [
                    'ventana_minutos' => $this->throttle->windowMinutes(),
                    'usuario_existe' => $failed !== null,
                    'usuario' => $failed === null ? null : $username,
                ]);
            }
            return ['ok' => false, 'message' => self::GENERIC_ERROR, 'user_id' => null];
        }

        $userId = (int) $user['id'];
        $this->db->execute('UPDATE usuarios SET ultimo_acceso_at = now() WHERE id = :id', ['id' => $userId]);
        $this->session->login($userId);
        $this->bindSession($userId, $ip);
        // Rehash DESPUÉS de vincular la sesión: el actor en la BD ya es el propio usuario (las cuentas GOD sólo cambian
        // su contraseña a sí mismas; 0023) y el cambio queda auditado por la BD como de esa persona.
        if ($this->hasher->needsRehash((string) $user['password_hash'])) {
            $this->db->execute('UPDATE usuarios SET password_hash = :h WHERE id = :id', [
                'h' => $this->hasher->hash($password), 'id' => $userId,
            ]);
        }

        $current = $this->loadUser($userId);
        $this->auditContext->setActor($userId, $username, $current?->rolesLabel());
        $this->audit->record('auth.login', 'usuarios', $userId);

        return ['ok' => true, 'message' => null, 'user_id' => $userId];
    }

    /**
     * Vincula en la BD la sesión ACTUAL (recién creada o regenerada) con el usuario autenticado y deja la
     * conexión con ese actor. Es la única vía para fijar sesiones.usuario_id (la BD lo audita) y sólo se usa
     * tras verificar credenciales o al regenerar el identificador de una sesión ya autenticada.
     */
    public function bindSession(int $userId, ?string $ip): void
    {
        $this->db->query('SELECT fn_sesion_vincular(:t, :u, CAST(:ip AS inet), :ua)', [
            't' => $this->session->store()->id(), 'u' => $userId, 'ip' => $ip, 'ua' => $this->auditContext->userAgent ?? '',
        ]);
    }

    public function logout(?CurrentUser $user): void
    {
        if ($user !== null) {
            $this->audit->record('auth.logout', 'usuarios', $user->id);
        }
        $this->session->logout();
        $this->db->bindActor(null);
    }

    /** Carga el usuario activo con roles y permisos efectivos; null si no existe o está inactivo. */
    public function loadUser(int $id): ?CurrentUser
    {
        $row = $this->db->fetchOne(
            'SELECT id, username, nombre, debe_cambiar_password FROM usuarios
              WHERE id = :id AND activo AND deleted_at IS NULL AND NOT (es_desarrollo AND :prod)',
            ['id' => $id, 'prod' => $this->blockDevAccounts]
        );
        if ($row === null) {
            return null;
        }
        $roles = array_column($this->db->fetchAll(
            'SELECT r.codigo FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id
              WHERE ur.usuario_id = :id AND r.activo ORDER BY r.codigo',
            ['id' => $id]
        ), 'codigo');
        $permissions = array_column($this->db->fetchAll(
            'SELECT DISTINCT p.codigo FROM usuario_rol ur
               JOIN roles r ON r.id = ur.rol_id AND r.activo
               JOIN rol_permiso rp ON rp.rol_id = r.id
               JOIN permisos p ON p.id = rp.permiso_id
              WHERE ur.usuario_id = :id ORDER BY p.codigo',
            ['id' => $id]
        ), 'codigo');

        return new CurrentUser(
            (int) $row['id'],
            (string) $row['username'],
            (string) $row['nombre'],
            (bool) $row['debe_cambiar_password'],
            array_values(array_map('strval', $roles)),
            array_values(array_map('strval', $permissions)),
        );
    }
}
