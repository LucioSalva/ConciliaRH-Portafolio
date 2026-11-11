<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\System\HealthService;

/** Salud del sistema y mantenimiento SEGURO (docs/SYSTEM_HEALTH.md). Nada destructivo ni arbitrario se ejecuta desde aquí. */
final class SystemController extends Controller
{
    public function __construct(private readonly HealthService $health)
    {
    }

    public function health(Request $request): Response
    {
        $checks = $this->health->checks();
        return $this->render('system/health', [
            'title' => 'Salud del sistema', 'breadcrumbs' => [['Sistema', null], ['Salud', null]],
            'checks' => $checks, 'general' => HealthService::overall($checks), 'workers' => $this->health->workerList(),
            'errores' => $this->health->recentErrors(), 'puedeMantener' => $this->user($request)->can('sistema.mantenimiento'),
        ]);
    }

    public function storageCheck(Request $request): Response
    {
        $r = $this->health->runStorageCheck($this->user($request));
        return $this->redirectWith('/sistema/salud', $r['resultado'] === 'OK' ? 'success' : 'warning', $r['resultado'] === 'OK'
            ? "Almacenamiento revisado: {$r['revisados']} archivo(s), sin hallazgos."
            : "Almacenamiento revisado con hallazgos: {$r['huerfanos']} huérfano(s), {$r['staging']} en staging vencido, {$r['faltantes']} original(es) ausentes o alterados. Use bin/verificar-almacenamiento.php para el detalle.");
    }

    /** Resumen autenticado para monitoreo interno: sólo estados (sin versiones ni conteos). */
    public function api(Request $request): Response
    {
        $checks = $this->health->checks();
        return Response::apiOk(['general' => HealthService::overall($checks), 'verificaciones' => array_map(static fn ($c) => $c['estado'], $checks)])
            ->withHeader('Cache-Control', 'no-store');
    }
}
