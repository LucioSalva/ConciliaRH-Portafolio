<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Reports;

/** Falla al descifrar/verificar un reporte. El mensaje es un CÓDIGO seguro (sin rutas, llaves ni contenido). */
final class ReportCipherException extends \RuntimeException
{
    public function __construct(public readonly string $codigo)
    {
        parent::__construct($codigo);
    }
}
