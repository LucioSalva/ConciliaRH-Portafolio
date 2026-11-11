<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Conciliation;

final class Result
{
    /**
     * @param list<FileRole> $archivos
     * @param list<LevelControl> $controles
     * @param list<EmployeeResult> $empleados
     * @param list<PensionResult> $pensiones
     * @param list<Source> $fuentes
     * @param list<Incident> $incidencias
     * @param array<string,mixed> $totales
     * @param array<string,mixed> $resumen
     */
    public function __construct(
        public readonly string $estado,
        public readonly int $motorVersion,
        public readonly array $archivos,
        public readonly array $controles,
        public readonly array $empleados,
        public readonly array $pensiones,
        public readonly array $fuentes,
        public readonly array $incidencias,
        public readonly array $totales,
        public readonly array $resumen,
    ) {
    }
}
