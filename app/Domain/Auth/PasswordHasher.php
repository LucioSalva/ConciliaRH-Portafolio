<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Auth;

/**
 * Hash de contraseñas: Argon2id cuando está disponible; bcrypt (coste 12) como respaldo.
 */
final class PasswordHasher
{
    /**
     * Hashes ficticios CONSTANTES (de cadenas aleatorias descartadas) con los mismos parámetros
     * que los reales. dummyVerify() sólo ejecuta password_verify(), exactamente el mismo coste que
     * verificar a un usuario existente: el tiempo de respuesta no revela si el usuario existe.
     * No son secretos.
     */
    private const DUMMY_ARGON2ID = '$argon2id$v=19$m=65536,t=4,p=1$WG1DRndLYWJlNmlzalNWWQ$lz7GKacMl/u5OkwvuEJv7/mFYkP1H9v2RdXpklJb/Nw';
    private const DUMMY_BCRYPT = '$2y$12$dE9sNR5BxH.8/aP4stFbaOreqd2NsmbFHK1abWDenXWMCifjW.GdK';

    public function algorithm(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    /** @return array<string,int> */
    private function options(): array
    {
        return $this->algorithm() === PASSWORD_BCRYPT
            ? ['cost' => 12]
            : ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1];
    }

    public function hash(string $password): string
    {
        return password_hash($password, $this->algorithm(), $this->options());
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm(), $this->options());
    }

    /**
     * Verificación contra un hash ficticio cuando el usuario no existe,
     * para que el tiempo de respuesta no revele si el usuario existe.
     */
    public function dummyVerify(string $password): void
    {
        password_verify($password, $this->algorithm() === PASSWORD_BCRYPT ? self::DUMMY_BCRYPT : self::DUMMY_ARGON2ID);
    }
}
