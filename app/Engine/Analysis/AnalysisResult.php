<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Analysis;

final class AnalysisResult
{
    public const MOTOR_VERSION = '1.0';
    public const REGLAS_VERSION = 1;

    /**
     * @param list<SheetResult> $sheets
     * @param list<Control> $controls
     * @param list<StagingRow> $staging
     * @param list<Advertencia> $advertencias
     * @param array<string,mixed> $evidencia
     * @param array<string,mixed> $metricas
     * @param list<string> $encabezados
     */
    public function __construct(
        public string $resultado,
        public ?string $perfil,
        public ?string $tipoDocumento,
        public string $confianza,
        public string $perfilOrigen,
        public ?int $puntaje,
        public ?int $hoja,
        public string $hojaOrigen,
        public array $sheets,
        public array $controls,
        public array $staging,
        public ?string $huella,
        public array $advertencias,
        public array $evidencia,
        public array $metricas,
        public array $encabezados,
        public int $filasDetalle,
        public ?int $huellaVersion = null,
        public string $motorVersion = self::MOTOR_VERSION,
        public int $reglasVersion = self::REGLAS_VERSION,
    ) {
    }
}
