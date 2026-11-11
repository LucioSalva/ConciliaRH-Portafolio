<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Analysis;

final class SheetResult
{
    public ?int $headerRow = null;
    public ?int $headerRows = null;
    public ?string $perfil = null;
    public ?string $familia = null;
    public int $puntaje = 0;
    public string $estado = 'DESCARTADA';
    public bool $seleccionada = false;
    public string $motivo = '';
    public int $filasDetalle = 0;
    public int $filasTotal = 0;
    /** @var list<Advertencia> */
    public array $advertencias = [];
    /** @var array<string,mixed> */
    public array $metadata = [];
    /** @var list<ColumnStats> */
    public array $columns = [];
    public ?Mapping $mapping = null;
    /** @var list<RowInfo> */
    public array $rows = [];
    public ?GlobalData $global = null;
    public ?HeaderInfo $header = null;
    public float $reqCov = 0.0;
    /** @var list<string> */
    public array $titles = [];

    public function __construct(
        public readonly int $index,
        public readonly string $name,
        public readonly string $visibility,
        public readonly int $filasDetectadas,
        public readonly int $columnasDetectadas,
        public readonly ?string $rangoUsado,
        public readonly ?string $dimension,
        public readonly int $cellsNonempty,
        public readonly bool $tieneFiltros,
        public readonly int $filasOcultas,
        public readonly bool $tieneColumnasOcultas,
        public readonly ?int $formulas,
        public readonly ?int $formulasSinCache,
        public readonly int $merges,
    ) {
    }
}
