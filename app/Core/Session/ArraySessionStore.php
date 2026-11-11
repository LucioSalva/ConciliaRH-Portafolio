<?php

declare(strict_types=1);

namespace ConciliaRH\Core\Session;

/** Almacén en memoria para pruebas automatizadas. */
final class ArraySessionStore implements SessionStore
{
    /** @var array<string,mixed> */
    private array $data = [];
    private string $id;
    public int $regenerations = 0;

    public function __construct()
    {
        $this->id = bin2hex(random_bytes(32));
    }

    public function start(): void
    {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(): void
    {
        $this->id = bin2hex(random_bytes(32));
        $this->regenerations++;
    }

    public function invalidate(): void
    {
        $this->data = [];
        $this->regenerate();
    }
}
