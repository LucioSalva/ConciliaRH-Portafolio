<?php

declare(strict_types=1);

namespace ConciliaRH\Core\Session;

interface SessionStore
{
    public function start(): void;

    public function id(): string;

    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /** Nuevo identificador conservando datos (protección contra fijación de sesión). */
    public function regenerate(): void;

    /** Elimina todos los datos y emite un identificador nuevo. */
    public function invalidate(): void;
}
