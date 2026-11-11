<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Conciliation;

final class State
{
    /** @var array<int,FileRole> */
    public array $roles = [];
    /** @var list<LevelControl> */
    public array $controles = [];
    /** @var list<Incident> */
    public array $incs = [];
    /** @var list<Source> */
    public array $fuentes = [];
    public readonly string $tol;

    /** @param array<string,mixed> $inp */
    public function __construct(public readonly array $inp)
    {
        $this->tol = (string) $inp['tolerancia'];
    }

    /** @param array<string,mixed> $detalle */
    public function inc(string $clave, string $tipo, string $severidad, int $nivel, string $mensaje, ?int $empleadoId = null, ?string $grupo = null,
                        ?string $banco = null, ?string $importe = null, array $detalle = []): void
    {
        $this->incs[] = new Incident($clave, $tipo, $severidad, $nivel, $mensaje, $empleadoId, $grupo, $banco, $importe, $detalle);
    }

    /** @return array<string,mixed> */
    public function employee(int $id): array
    {
        return $this->inp['empleados'][$id];
    }
}
