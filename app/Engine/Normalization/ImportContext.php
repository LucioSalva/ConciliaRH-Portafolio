<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Normalization;

use ConciliaRH\Core\Database;
use ConciliaRH\Engine\Identity\MatchResult;
use ConciliaRH\Engine\Identity\PersonIndex;
use ConciliaRH\Engine\Worker\JobContext;

final class ImportContext
{
    /** @var list<IdentityIncident> */
    public array $incs = [];
    /** @var list<list<mixed>> */
    public array $hist = [];

    /**
     * @param array<string,mixed> $file
     * @param list<array<string,mixed>> $staging
     * @param array<int,array<string,mixed>> $emps
     * @param array<string,list<array<string,mixed>>> $carry
     */
    public function __construct(
        public readonly Database $db,
        public readonly JobContext $job,
        public readonly int $importacionId,
        public readonly array $file,
        public readonly array $staging,
        public PersonIndex $idx,
        public array $emps,
        public array $carry,
        public bool $carryByPosition = false,
        public bool $carryTreasury = false,
    ) {
    }

    public function jobId(): int
    {
        return $this->job->job->id;
    }

    /** @return array{0:?array<string,mixed>,1:list<int>} */
    public function popCarry(string $hash, ?array $s = null, ?object $ident = null): array
    {
        if ($this->carryByPosition) {
            $tes = $this->carryTreasury && $s !== null;
            $hash = Carry::positionKey($s['hoja_indice'] ?? null, $s['fila_origen'] ?? null, $ident?->numero ?? null, $ident?->nombreKey ?? null,
                $tes ? $s['importe'] : null, $tes ? $s['cuenta_ultimos4'] : null);
        }
        if (empty($this->carry[$hash])) {
            return [null, []];
        }
        $d = array_shift($this->carry[$hash]);
        return [$d, \ConciliaRH\Engine\Identity\Beneficiaries::intArray($d['rechazados'] ?? null)];
    }

    /** @param array<string,mixed>|null $d @return array{0:MatchResult,1:?array<string,mixed>} */
    public function applyCarry(?array $d, MatchResult $m): array
    {
        if ($d === null) {
            return [$m, null];
        }
        if ($d['asociacion_estado'] === 'ASOCIADO_MANUAL' && $d['empleado_id'] !== null && isset($this->emps[(int) $d['empleado_id']])) {
            return [new MatchResult('ASOCIADO_MANUAL', (int) $d['empleado_id'], 'MANUAL', 'MANUAL', ['trasladado' => true], []), $d];
        }
        if ($d['asociacion_estado'] === 'NO_IDENTIFICADO_CONFIRMADO') {
            return [new MatchResult('NO_IDENTIFICADO_CONFIRMADO', null, 'MANUAL', 'MANUAL', ['trasladado' => true], []), $d];
        }
        return [$m, null];
    }

    /** @param array<string,mixed>|null $manual */
    public function histAuto(string $rtipo, int $rid, MatchResult $m, ?array $manual): void
    {
        $this->hist[] = [$rtipo, $rid, $manual !== null ? 'TRASLADO' : 'AUTOMATICA', null, $m->estado, null, $m->empleadoId, $m->metodo, $m->confianza,
            $manual !== null ? $manual['asociado_por'] : null, $this->jobId(),
            $manual !== null ? 'Decisión manual trasladada desde la importación anterior del mismo archivo.' : null];
    }
}
