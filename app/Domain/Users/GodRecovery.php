<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Users;

use ConciliaRH\Core\Database;
use ConciliaRH\Domain\Audit\AuditContext;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Auth\LoginThrottle;
use ConciliaRH\Domain\Auth\PasswordHasher;
use ConciliaRH\Domain\Auth\PasswordPolicy;
use ConciliaRH\Domain\Settings\SettingsService;
use ConciliaRH\Domain\ValidationException;
use RuntimeException;

/**
 * Recuperación de emergencia de la cuenta GOD (docs/BREAK_GLASS_RECOVERY.md). Sólo se invoca desde la CLI del servidor
 * (bin/god-recovery.php) con el rol PROPIETARIO de la BD; no existe ruta web, usuario oculto ni contraseña maestra.
 *
 * - Sólo opera sobre la cuenta que YA tiene el rol GOD: nunca sobre otro usuario y nunca crea un segundo GOD.
 * - La cuenta sobre la que se opera es la que el operador CONFIRMÓ: cada operación recibe el usuario esperado y se niega
 *   si, ya con los candados tomados, la cuenta GOD resulta ser otra.
 * - Cada operación queda en la auditoría como SYSTEM_BREAK_GLASS, con el usuario del sistema operativo y el motivo.
 *   Nunca se registran la contraseña, su hash ni secretos.
 * - Serializada con el alta de GOD (mismo candado) y con la fila de la cuenta bloqueada: dos recuperaciones no se cruzan.
 */
final class GodRecovery
{
    public const ACTOR = 'SYSTEM_BREAK_GLASS';
    public const OPERATIONS = ['status', 'unlock', 'reactivate', 'revoke-sessions', 'reset-password', 'repair'];

    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly LoginThrottle $throttle,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
        private readonly AuditContext $ctx,
    ) {
    }

    /**
     * Estado de la cuenta GOD e invariantes. Sólo lectura; no devuelve hashes ni datos de otros usuarios.
     * @return array<string,mixed>
     */
    public function status(?string $username = null): array
    {
        $cuentas = $this->godAccounts(false);
        $activas = array_values(array_filter($cuentas, static fn (array $c): bool => $c['activo'] && !$c['baja']));
        $problemas = [];
        if ($cuentas === []) {
            $problemas[] = 'Ninguna cuenta tiene el rol GOD.';
        }
        if (count($activas) > 1) {
            $problemas[] = 'Hay más de una cuenta GOD activa (debe existir exactamente una).';
        }
        if ($cuentas !== [] && $activas === []) {
            $problemas[] = 'No hay ninguna cuenta GOD activa.';
        }
        $dev = $this->devGods();
        if ($dev !== []) {
            $problemas[] = 'Hay ' . count($dev) . ' cuenta(s) de DESARROLLO con el rol GOD: no deben existir fuera de desarrollo.';
        }
        $candidata = $cuentas === [] ? $this->repairCandidate(false) : null;
        $out = ['cuentas_god' => count($cuentas), 'cuentas_god_activas' => count($activas), 'problemas' => $problemas, 'cuenta' => null,
                'candidata_repair' => $candidata['username'] ?? null];
        try {
            $c = $this->target($cuentas, $username);
        } catch (RuntimeException $e) {
            $out['problemas'][] = $e->getMessage();
            return $out;
        }
        $roles = $this->users->roleCodes((int) $c['id']);
        if ($roles !== ['GOD']) {
            $out['problemas'][] = 'La cuenta GOD tiene además otros roles: ' . implode(', ', array_diff($roles, ['GOD'])) . '.';
        }
        $out['cuenta'] = [
            'usuario' => $c['username'],
            'activa' => (bool) $c['activo'],
            'dada_de_baja' => (bool) $c['baja'],
            'debe_cambiar_password' => (bool) $c['debe_cambiar_password'],
            'bloqueada_por_intentos' => $this->lockedFromSomewhere((string) $c['username']),
            'intentos_fallidos_recientes' => $this->failedAttempts((string) $c['username']),
            'sesiones_activas' => (int) $this->db->fetchValue(
                'SELECT count(*) FROM sesiones WHERE usuario_id = :u AND revocada_at IS NULL', ['u' => $c['id']]
            ),
            'ultimo_acceso' => $c['ultimo_acceso_at'],
            'password_cambiado' => $c['password_cambiado_at'],
        ];
        return $out;
    }

    /** Borra los intentos fallidos recientes de la cuenta GOD (el bloqueo por IP de origen no se toca). */
    public function unlock(string $motivo, string $osUser, string $username): array
    {
        return $this->run('unlock', $motivo, $osUser, $username, function (array $c): array {
            return ['intentos_eliminados' => $this->clearAttempts((string) $c['username'])];
        });
    }

    /** Reactiva la cuenta GOD (activa y sin baja). Se niega si ya hay OTRA cuenta GOD activa. */
    public function reactivate(string $motivo, string $osUser, string $username): array
    {
        return $this->run('reactivate', $motivo, $osUser, $username, function (array $c): array {
            $this->db->execute('UPDATE usuarios SET activo = true, deleted_at = NULL, deleted_by = NULL WHERE id = :id', ['id' => $c['id']]);
            return ['antes' => ['activa' => (bool) $c['activo'], 'dada_de_baja' => (bool) $c['baja']], 'activa' => true];
        });
    }

    /** Revoca TODAS las sesiones de la cuenta GOD. */
    public function revokeSessions(string $motivo, string $osUser, string $username): array
    {
        return $this->run('revoke_sessions', $motivo, $osUser, $username, function (array $c): array {
            return ['sesiones_revocadas' => $this->users->revokeSessions((int) $c['id'], null, 'Recuperación de emergencia (CLI)')];
        });
    }

    /**
     * Restablece la contraseña de la cuenta GOD. Obliga a cambiarla en el siguiente acceso, revoca sus sesiones y limpia
     * los intentos fallidos. No reactiva la cuenta (es otra operación, auditada por separado).
     */
    public function resetPassword(string $password, int $minLength, string $motivo, string $osUser, string $username): array
    {
        return $this->run('reset_password', $motivo, $osUser, $username, function (array $c) use ($password, $minLength): array {
            if (($error = (new PasswordPolicy(max(12, $minLength)))->validate($password, (string) $c['username'])) !== null) {
                throw new ValidationException(['password' => $error]);
            }
            $this->db->execute(
                'UPDATE usuarios SET password_hash = :h, debe_cambiar_password = true, password_cambiado_at = now() WHERE id = :id',
                ['h' => $this->hasher->hash($password), 'id' => $c['id']]
            );
            return [
                'cambio_obligatorio' => true,
                'sesiones_revocadas' => $this->users->revokeSessions((int) $c['id'], null, 'Recuperación de emergencia (CLI): contraseña restablecida'),
                'intentos_eliminados' => $this->clearAttempts((string) $c['username']),
            ];
        });
    }

    /**
     * Repara el único invariante que se puede reparar sin decidir por nadie: si NINGUNA cuenta tiene el rol GOD, lo devuelve
     * a la cuenta que la auditoría registra como creada por `create-god` (la más reciente que siga existiendo).
     *
     * Mientras estuvo SIN el rol, esa cuenta no tuvo la protección de GOD: alguien con permiso de editar usuarios pudo
     * cambiarle la contraseña o darle otros roles. Por eso, al devolver el rol: se le quitan los demás roles, se cierran
     * sus sesiones y su contraseña queda INUTILIZADA. Para usarla hay que ejecutar después `reset-password`.
     * Nunca asigna el rol a otra cuenta y nunca deja dos GOD. $username es la cuenta que el operador confirmó.
     */
    public function repair(string $motivo, string $osUser, string $username): array
    {
        $this->assertMotivo($motivo);
        return $this->db->transaction(function () use ($motivo, $osUser, $username): array {
            $this->lock();
            $cuentas = $this->godAccounts(true);
            if ($cuentas !== []) {
                $c = $this->target($cuentas, $username);
                $extra = array_values(array_diff($this->users->roleCodes((int) $c['id']), ['GOD']));
                $this->record('repair', $c, $motivo, $osUser, ['cambios' => [], 'roles_adicionales' => $extra]);
                return ['usuario' => $c['username'], 'cambios' => [], 'roles_adicionales' => $extra];
            }
            $c = $this->repairCandidate(true);
            if ($c === null) {
                throw new RuntimeException('Ninguna cuenta tiene el rol GOD y la auditoría no registra ninguna creada por create-god. Use create-god.');
            }
            if ($c['username'] !== mb_strtolower(trim($username))) {
                throw new RuntimeException('La cuenta a reparar ya no es la que se confirmó. No se hizo ningún cambio.');
            }
            if ($this->devGods(true) !== []) {
                throw new RuntimeException('Hay una cuenta de desarrollo con el rol GOD activa: no se restituye el rol mientras exista.');
            }
            $quitados = $this->users->roleCodes((int) $c['id']);
            $this->db->execute('DELETE FROM usuario_rol WHERE usuario_id = :u', ['u' => $c['id']]);
            $this->db->execute("INSERT INTO usuario_rol (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = 'GOD'", ['u' => $c['id']]);
            // Contraseña inutilizada: el hash de un valor aleatorio que nadie conoce (se descarta aquí mismo)
            $this->db->execute(
                'UPDATE usuarios SET password_hash = :h, debe_cambiar_password = true WHERE id = :id',
                ['h' => $this->hasher->hash(bin2hex(random_bytes(32))), 'id' => $c['id']]
            );
            $result = [
                'cambios' => ['rol_god_restituido', 'credencial_inutilizada'],
                'roles_quitados' => $quitados,
                'sesiones_revocadas' => $this->users->revokeSessions((int) $c['id'], null, 'Recuperación de emergencia (CLI): rol GOD restituido'),
            ];
            $this->record('repair', $c, $motivo, $osUser, $result);
            return ['usuario' => $c['username'], 'roles_adicionales' => []] + $result;
        });
    }

    /**
     * @param callable(array<string,mixed>):array<string,mixed> $fn
     * @return array<string,mixed>
     */
    private function run(string $op, string $motivo, string $osUser, string $username, callable $fn): array
    {
        $this->assertMotivo($motivo);
        return $this->db->transaction(function () use ($op, $motivo, $osUser, $username, $fn): array {
            $this->lock();
            $c = $this->target($this->godAccounts(true), $username);
            if ($op === 'reactivate' && $this->devGods(true) !== []) {
                throw new RuntimeException('Hay una cuenta de desarrollo con el rol GOD activa: no se reactiva otra mientras exista.');
            }
            $result = $fn($c);
            $this->record($op, $c, $motivo, $osUser, $result);
            return ['usuario' => $c['username']] + $result;
        });
    }

    /** Mismo candado que el alta de GOD: alta y recuperación nunca corren a la vez. */
    private function lock(): void
    {
        $this->db->execute("SELECT pg_advisory_xact_lock(hashtext('conciliarh.create_god'))");
    }

    /**
     * Cuentas con el rol GOD (nunca las de desarrollo). Con $forUpdate se bloquean sus filas hasta el fin de la transacción.
     * @return list<array<string,mixed>>
     */
    private function godAccounts(bool $forUpdate): array
    {
        return $this->db->fetchAll(
            "SELECT u.id, u.username, u.activo, u.deleted_at IS NOT NULL AS baja, u.debe_cambiar_password, u.ultimo_acceso_at,
                    u.password_cambiado_at
               FROM usuarios u
              WHERE NOT u.es_desarrollo
                AND EXISTS (SELECT 1 FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = u.id AND r.codigo = 'GOD')
              ORDER BY u.id" . ($forUpdate ? ' FOR UPDATE OF u' : '')
        );
    }

    /**
     * La cuenta sobre la que se opera: la GOD activa; si no hay ninguna activa, la única que tenga el rol.
     * Con varias candidatas inactivas hay que nombrarla con --usuario, y debe ser una de ellas (nunca otro usuario).
     * @param list<array<string,mixed>> $cuentas
     * @return array<string,mixed>
     */
    private function target(array $cuentas, ?string $username): array
    {
        if ($cuentas === []) {
            throw new RuntimeException('Ninguna cuenta tiene el rol GOD: no hay nada que recuperar (vea «repair» o create-god).');
        }
        $activas = array_values(array_filter($cuentas, static fn (array $c): bool => $c['activo'] && !$c['baja']));
        if (count($activas) > 1) {
            throw new RuntimeException('Hay más de una cuenta GOD activa: la recuperación no elige entre ellas.');
        }
        if ($username !== null && $username !== '') {
            $username = mb_strtolower(trim($username));
            foreach ($cuentas as $c) {
                if ($c['username'] === $username) {
                    if ($activas !== [] && (int) $activas[0]['id'] !== (int) $c['id']) {
                        throw new RuntimeException('Esa cuenta no es la GOD activa.');
                    }
                    return $c;
                }
            }
            // Mismo mensaje exista o no el usuario: la CLI no sirve para enumerar ni para tocar otras cuentas
            throw new RuntimeException('El usuario indicado no tiene el rol GOD: la recuperación sólo opera sobre la cuenta GOD.');
        }
        if (count($activas) === 1) {
            return $activas[0];
        }
        if (count($cuentas) === 1) {
            return $cuentas[0];
        }
        throw new RuntimeException('Hay varias cuentas con el rol GOD y ninguna activa: indique cuál con --usuario=<usuario>.');
    }

    /**
     * Cuentas de DESARROLLO con el rol GOD (no deben existir fuera de desarrollo; la recuperación nunca opera sobre ellas).
     * @return list<array<string,mixed>>
     */
    private function devGods(bool $soloActivas = false): array
    {
        return $this->db->fetchAll(
            "SELECT u.id, u.username FROM usuarios u
              WHERE u.es_desarrollo" . ($soloActivas ? ' AND u.activo AND u.deleted_at IS NULL' : '') . "
                AND EXISTS (SELECT 1 FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = u.id AND r.codigo = 'GOD')
              ORDER BY u.id"
        );
    }

    /**
     * La cuenta a la que `repair` devolvería el rol: la más reciente creada por create-god que siga existiendo.
     * @return array<string,mixed>|null
     */
    private function repairCandidate(bool $forUpdate): ?array
    {
        return $this->db->fetchOne(
            "SELECT u.id, u.username, u.activo, u.deleted_at IS NOT NULL AS baja, u.debe_cambiar_password, u.ultimo_acceso_at,
                    u.password_cambiado_at
               FROM auditoria a JOIN usuarios u ON u.id::text = a.entidad_id
              WHERE a.accion = 'usuarios.crear_god' AND a.entidad = 'usuarios' AND a.origen = 'CLI' AND NOT u.es_desarrollo
              ORDER BY a.secuencia DESC LIMIT 1" . ($forUpdate ? ' FOR UPDATE OF u' : '')
        );
    }

    /**
     * ¿Está bloqueada desde ALGÚN origen? Igual que el login: fallos posteriores al último acceso correcto, dentro de la
     * ventana; bloquea una misma dirección que alcanza el umbral, o el total desde cualquier dirección.
     */
    private function lockedFromSomewhere(string $username): bool
    {
        $r = $this->db->fetchOne(
            "WITH ok AS (SELECT coalesce(max(creado_at), '-infinity') AS t FROM intentos_login WHERE username_hmac = :k1 AND exito),
                  f AS (SELECT ip, count(*) AS n FROM intentos_login, ok
                         WHERE username_hmac = :k2 AND NOT exito AND creado_at > now() - make_interval(mins => :w) AND creado_at > ok.t
                         GROUP BY ip)
             SELECT coalesce(max(n), 0) AS por_origen, coalesce(sum(n), 0) AS total FROM f",
            ['k1' => $this->throttle->key($username), 'k2' => $this->throttle->key($username), 'w' => $this->throttle->windowMinutes()]
        );
        return (int) $r['por_origen'] >= $this->settings->int('seguridad.login_max_intentos_usuario', 5)
            || (int) $r['total'] >= $this->settings->int('seguridad.login_max_intentos_usuario_global', 20);
    }

    private function assertMotivo(string $motivo): void
    {
        if (mb_strlen(trim($motivo)) < 10 || mb_strlen($motivo) > 500) {
            throw new ValidationException(['motivo' => 'Indique el motivo (de 10 a 500 caracteres).']);
        }
    }

    private function failedAttempts(string $username): int
    {
        return (int) $this->db->fetchValue(
            'SELECT count(*) FROM intentos_login WHERE username_hmac IN (:a, :b) AND NOT exito AND creado_at > now() - make_interval(mins => :w)',
            ['a' => $this->throttle->key($username), 'b' => $this->throttle->key($username, 'cambio_password'), 'w' => $this->throttle->windowMinutes()]
        );
    }

    private function clearAttempts(string $username): int
    {
        return $this->db->execute(
            'DELETE FROM intentos_login WHERE username_hmac IN (:a, :b) AND NOT exito',
            ['a' => $this->throttle->key($username), 'b' => $this->throttle->key($username, 'cambio_password')]
        );
    }

    /**
     * @param array<string,mixed> $c
     * @param array<string,mixed> $result
     */
    private function record(string $op, array $c, string $motivo, string $osUser, array $result): void
    {
        $this->ctx->origin = 'CLI';
        $this->ctx->setActor(null, self::ACTOR, null);
        $this->audit->record('emergencia.god_' . $op, 'usuarios', (int) $c['id'], null, ['usuario' => $c['username']] + $result, [
            'tipo' => self::ACTOR,
            'operacion' => $op,
            'motivo' => trim($motivo),
            'usuario_so' => $osUser,
            'host' => gethostname() ?: null,
        ]);
    }
}
