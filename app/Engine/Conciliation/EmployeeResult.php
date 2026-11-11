<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Conciliation;

use ConciliaRH\Engine\Support\Dec;

final class EmployeeResult
{
    /**
     * @param array<string,string> $pagado
     * @param array<string,mixed> $detalle
     */
    public function __construct(
        public int $empleadoId,
        public string $grupo,
        public string $numero,
        public ?string $area,
        public ?int $registroRrhhId,
        public ?string $percepciones,
        public ?string $deducciones,
        public string $netoEsperado,
        public array $pagado,
        public int $pagos,
        public string $estado,
        public ?string $alerta,
        public string $posiblePendiente,
        public bool $conPension,
        public array $detalle = [],
    ) {
    }

    public function pagadoTotal(): string
    {
        return Dec::sum(array_values($this->pagado));
    }

    public function diferencia(): string
    {
        return Dec::sub($this->pagadoTotal(), $this->netoEsperado);
    }
}
