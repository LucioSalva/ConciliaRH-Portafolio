<?php

declare(strict_types=1);

namespace ConciliaRH\Domain;

use RuntimeException;

/** Error de validación con mensajes por campo, aptos para el usuario. */
final class ValidationException extends RuntimeException
{
    /** @param array<string,string> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
