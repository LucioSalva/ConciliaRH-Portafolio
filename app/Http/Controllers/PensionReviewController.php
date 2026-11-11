<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\Paginator;
use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Identity\PensionReviewService;
use ConciliaRH\Domain\ValidationException;

final class PensionReviewController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly PensionReviewService $svc)
    {
    }

    public function index(Request $request): Response
    {
        $f = ['q' => mb_substr($request->string('q'), 0, 80), 'banco' => $request->string('banco'), 'revision' => $request->string('revision')];
        $res = $this->svc->search($f, Paginator::pageFrom($request), self::PER_PAGE);
        $u = $this->user($request);
        return $this->render('pensions/review_index', [
            'title' => 'Revisión de pensiones', 'breadcrumbs' => [['Avanzado', null], ['Revisión de pensiones', null]],
            'rows' => $res['rows'], 'total' => $res['total'], 'importeTotal' => $res['importe_total'], 'filters' => $f,
            'paginator' => new Paginator($res['page'], self::PER_PAGE, $res['total'], '/revision-pensiones', array_filter($f)),
            'puedeDecidir' => $u->can('asociaciones.resolver'),
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $u = $this->user($request);
        $c = $this->svc->find($id);
        return $this->render('pensions/review_show', [
            'title' => 'Pensión #' . $id, 'breadcrumbs' => [['Avanzado', null], ['Revisión de pensiones', '/revision-pensiones'], ['#' . $id, null]],
            'c' => $c, 'puedeDecidir' => $u->can('asociaciones.resolver'),
            'puedeConfirmar' => $u->can('asociaciones.resolver') && $u->can('beneficiarios.confirmar') && $u->can('cuentas.confirmar'),
        ]);
    }

    public function confirm(Request $request, int $id): Response
    {
        return $this->decide($request, $id, fn (int $v, string $m) => $this->svc->confirm($this->user($request), $id, $v, $m),
            'Pensión confirmada: el pago quedó asociado al trabajador y la CLABE del beneficiario se registró.', true);
    }

    public function reject(Request $request, int $id): Response
    {
        return $this->decide($request, $id, fn (int $v, string $m) => $this->svc->reject($this->user($request), $id, $v, $m),
            'Sugerencia rechazada: ese trabajador no se volverá a proponer para este pago.', true);
    }

    public function pending(Request $request, int $id): Response
    {
        return $this->decide($request, $id, fn (int $v, string $m) => $this->svc->leavePending($this->user($request), $id, $v, $m),
            'El caso queda pendiente con su motivo registrado.', false);
    }

    private function decide(Request $request, int $id, callable $fn, string $ok, bool $toList): Response
    {
        $v = $request->string('version');
        if (!ctype_digit($v) || strlen($v) > 9) {
            return $this->redirectWith("/revision-pensiones/$id", 'error', 'Versión del caso inválida: recargue la página.');
        }
        try {
            $fn((int) $v, $request->string('motivo'));
        } catch (ValidationException $e) {
            return $this->redirectWith("/revision-pensiones/$id", 'error', implode(' ', $e->errors()));
        }
        return $this->redirectWith($toList ? '/revision-pensiones' : "/revision-pensiones/$id", 'success', $ok);
    }
}
