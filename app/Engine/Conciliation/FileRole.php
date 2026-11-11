<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Conciliation;

final class FileRole
{
    public function __construct(
        public int $archivoId,
        public ?int $importacionId,
        public ?string $tipoRegistro,
        public string $rol,
        public ?string $grupo = null,
        public ?string $banco = null,
        public ?string $categoria = null,
        public int $registros = 0,
        public ?string $totalDetalle = null,
        public ?string $totalDeclarado = null,
        public ?string $motivo = null,
    ) {
    }
}
