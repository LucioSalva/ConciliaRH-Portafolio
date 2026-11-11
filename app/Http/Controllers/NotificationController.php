<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\Paginator;
use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Notifications\NotificationService;

/** Centro de notificaciones personales (docs/NOTIFICATIONS.md). Sin permiso especial: cada usuario sólo ve las suyas. */
final class NotificationController extends Controller
{
    private const PER_PAGE = 30;

    public function __construct(private readonly NotificationService $svc)
    {
    }

    public function index(Request $request): Response
    {
        $f = ['estado' => $request->string('estado'), 'tipo' => $request->string('tipo')];
        $res = $this->svc->paginate($this->user($request), $f, Paginator::pageFrom($request), self::PER_PAGE);
        return $this->render('notifications/index', [
            'title' => 'Notificaciones', 'breadcrumbs' => [['Notificaciones', null]],
            'rows' => $res['rows'], 'filters' => $f, 'tipos' => NotificationService::TIPOS,
            'paginator' => new Paginator($res['page'], self::PER_PAGE, $res['total'], '/notificaciones', array_filter($f)),
        ]);
    }

    /** Abre una notificación: la marca leída y lleva a su destino interno. */
    public function open(Request $request, int $id): Response
    {
        $url = $this->svc->markRead($this->user($request), $id);
        return Response::redirect($url ?? '/notificaciones');
    }

    public function readAll(Request $request): Response
    {
        $n = $this->svc->markAllRead($this->user($request));
        return $this->redirectWith('/notificaciones', 'success', $n === 0 ? 'No había notificaciones sin leer.' : "$n notificación(es) marcadas como leídas.");
    }

    public function delete(Request $request, int $id): Response
    {
        $this->svc->delete($this->user($request), $id);
        return $this->redirectWith('/notificaciones', 'success', 'Notificación eliminada.');
    }

    public function deleteRead(Request $request): Response
    {
        $n = $this->svc->deleteRead($this->user($request));
        return $this->redirectWith('/notificaciones', 'success', "$n notificación(es) leídas eliminadas.");
    }

    /** API para la campana del encabezado: sólo títulos y mensajes propios (sin datos personales por diseño). */
    public function summary(Request $request): Response
    {
        return Response::apiOk($this->svc->summary($this->user($request)))->withHeader('Cache-Control', 'no-store');
    }
}
