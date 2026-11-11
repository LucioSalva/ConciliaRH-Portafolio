<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\Paginator;
use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Jobs\JobQueue;
use ConciliaRH\Domain\Jobs\JobTypes;
use ConciliaRH\Domain\ValidationException;

final class ProcessController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly JobQueue $jobs)
    {
    }

    public function index(Request $request): Response
    {
        $f = ['estado' => $request->string('estado'), 'tipo' => $request->string('tipo')];
        $page = Paginator::pageFrom($request);
        $result = $this->jobs->paginate($f, $page, self::PER_PAGE);
        return $this->render('processes/index', [
            'title' => 'Procesos',
            'breadcrumbs' => [['Sistema', null], ['Procesos en segundo plano', null]],
            'rows' => $result['rows'],
            'filters' => $f,
            'types' => JobTypes::all(),
            'paginator' => new Paginator($result['page'], self::PER_PAGE, $result['total'], '/procesos', $f),
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $user = $this->user($request);
        $job = $this->jobs->find($id, $user->can('procesos.ver_detalle_tecnico'));
        return $this->render('processes/show', [
            'title' => 'Proceso #' . $id,
            'breadcrumbs' => [['Sistema', null], ['Procesos', '/procesos'], ['#' . $id, null]],
            'job' => $job,
            'events' => $this->jobs->events($id),
        ]);
    }

    public function diagnostic(Request $request): Response
    {
        $id = $this->jobs->enqueue($this->user($request), 'DIAGNOSTICO', ['eco' => 'Prueba desde la interfaz'], 50);
        return $this->redirectWith('/procesos/' . $id, 'info', 'Trabajo de diagnóstico encolado.');
    }

    public function cancel(Request $request, int $id): Response
    {
        try {
            $this->jobs->cancel($this->user($request), $id);
        } catch (ValidationException $e) {
            return $this->redirectWith('/procesos/' . $id, 'error', $e->getMessage());
        }
        return $this->redirectWith('/procesos/' . $id, 'success', 'Trabajo cancelado.');
    }

    public function status(Request $request, int $id): Response
    {
        $job = $this->jobs->find($id, $this->user($request)->can('procesos.ver_detalle_tecnico'));
        unset($job['parametros']);
        return Response::apiOk($job);
    }
}
