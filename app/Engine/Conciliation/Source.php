<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Conciliation;

final class Source
{
    public function __construct(
        public readonly ?int $empleadoId,
        public readonly string $tipo,
        public readonly int $registroId,
        public readonly int $archivoId,
        public readonly ?string $hoja,
        public readonly ?int $fila,
        public readonly ?string $banco,
        public readonly ?string $codigoConcepto,
        public readonly ?string $importeOriginal,
        public readonly string $importe,
        public readonly ?string $asociacion,
    ) {
    }
}
