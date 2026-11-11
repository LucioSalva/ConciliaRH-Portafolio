<?php

declare(strict_types=1);

namespace ConciliaRH\Core;

use RuntimeException;

/** Error HTTP con mensaje apto para el usuario final (nunca detalles técnicos). */
final class HttpException extends RuntimeException
{
    public function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        string $userMessage,
    ) {
        parent::__construct($userMessage, $status);
    }

    public static function notFound(): self
    {
        return new self(404, 'NO_ENCONTRADO', 'El recurso solicitado no existe.');
    }

    public static function forbidden(): self
    {
        return new self(403, 'SIN_PERMISO', 'No tiene permiso para realizar esta acción.');
    }

    public static function csrf(): self
    {
        return new self(403, 'CSRF_INVALIDO', 'La sesión del formulario expiró. Recargue la página e intente de nuevo.');
    }

    public static function unauthenticated(): self
    {
        return new self(401, 'NO_AUTENTICADO', 'Debe iniciar sesión.');
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
