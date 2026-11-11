<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use Throwable;

/** Verificación de salud pública y mínima: no revela versiones ni detalles internos. */
final class HealthController
{
    public function __construct(private readonly Database $db, private readonly \ConciliaRH\Core\Maintenance $maintenance)
    {
    }

    public function show(Request $request): Response
    {
        // Mantenimiento ANTES que la BD: durante una restauración la BD puede no responder y el monitoreo debe saber que es planeado
        if ($this->maintenance->current() !== null) {
            return Response::apiError('MANTENIMIENTO', 'En mantenimiento programado.', 503);
        }
        try {
            $ok = (int) $this->db->fetchValue('SELECT 1') === 1;
        } catch (Throwable) {
            $ok = false;
        }
        return $ok
            ? Response::apiOk(['estado' => 'ok'])
            : Response::apiError('SERVICIO_NO_DISPONIBLE', 'Servicio no disponible.', 503);
    }
}
