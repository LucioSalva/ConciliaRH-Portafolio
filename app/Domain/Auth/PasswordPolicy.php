<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Auth;

/**
 * Política de contraseñas (orientada a NIST SP 800-63B: longitud sobre complejidad).
 */
final class PasswordPolicy
{
    public const MAX_LENGTH = 128;

    public function __construct(private readonly int $minLength = 12)
    {
    }

    public function minLength(): int
    {
        return $this->minLength;
    }

    /** @return string|null  mensaje de error o null si es válida */
    public function validate(string $password, string $username = ''): ?string
    {
        $len = mb_strlen($password);
        if ($len < $this->minLength) {
            return sprintf('La contraseña debe tener al menos %d caracteres.', $this->minLength);
        }
        if ($len > self::MAX_LENGTH) {
            return sprintf('La contraseña no debe exceder %d caracteres.', self::MAX_LENGTH);
        }
        if ($username !== '' && str_contains(mb_strtolower($password), mb_strtolower($username))) {
            return 'La contraseña no debe contener el nombre de usuario.';
        }
        if (preg_match('/^(.)\1+$/u', $password)) {
            return 'La contraseña no puede ser un solo carácter repetido.';
        }
        $classes = (int) preg_match('/[a-z]/', $password) + (int) preg_match('/[A-Z]/', $password)
            + (int) preg_match('/\d/', $password) + (int) preg_match('/[^a-zA-Z\d]/', $password);
        if ($classes < 2) {
            return 'Combine al menos dos tipos de caracteres (minúsculas, mayúsculas, números o símbolos).';
        }
        return null;
    }
}
