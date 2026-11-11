<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Worker;

use RuntimeException;

final class JobFailure extends RuntimeException
{
    private function __construct(
        public readonly string $failureCode,
        public readonly string $userMessage,
        public readonly bool $retriable,
        public readonly ?string $detail = null,
    ) {
        parent::__construct($userMessage);
    }

    public static function permanent(string $message, string $code, ?string $detail = null): self
    {
        return new self($code, $message, false, $detail);
    }

    public static function retriable(string $message, string $code, ?string $detail = null): self
    {
        return new self($code, $message, true, $detail);
    }

    public static function timeout(): self
    {
        return new self('TIEMPO_EXCEDIDO', 'El trabajo excedió el tiempo máximo permitido.', true);
    }

    public static function contract(string $detail): self
    {
        return new self('CONTRATO_INVALIDO', 'El trabajo no cumple el contrato de la cola.', false, $detail);
    }

    public static function invalidParameters(string $message): self
    {
        return new self('PARAMETROS_INVALIDOS', $message, false);
    }

    public function isTimeout(): bool
    {
        return $this->failureCode === 'TIEMPO_EXCEDIDO';
    }
}
