<?php

declare(strict_types=1);

namespace ConciliaRH\Core;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

final class Container
{
    /** @var array<string,Closure(self):object> */
    private array $factories = [];

    /** @var array<string,object> */
    private array $instances = [];

    /** @param Closure(self):object $factory */
    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function instance(string $id, object $object): void
    {
        $this->instances[$id] = $object;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        $object = isset($this->factories[$id]) ? ($this->factories[$id])($this) : $this->autowire($id);
        return $this->instances[$id] = $object;
    }

    private function autowire(string $class): object
    {
        if (!class_exists($class) || !str_starts_with($class, 'ConciliaRH\\')) {
            throw new RuntimeException('No se puede resolver la dependencia: ' . $class);
        }
        $ref = new ReflectionClass($class);
        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            return $ref->newInstance();
        }
        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->get($type->getName());
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                throw new RuntimeException(sprintf('Parámetro no resoluble $%s en %s', $param->getName(), $class));
            }
        }
        return $ref->newInstanceArgs($args);
    }
}
