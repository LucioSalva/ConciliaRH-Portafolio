<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\Paginator;
use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Identity\BeneficiaryService;
use ConciliaRH\Domain\ValidationException;

/** Beneficiarios de pensión (FASE 6). */
final class BeneficiaryController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(private readonly BeneficiaryService $ben)
    {
    }

    public function index(Request $request): Response
    {
        $f = ['q' => mb_substr($request->string('q'), 0, 80), 'estado' => $request->string('estado'), 'origen' => $request->string('origen'),
              'banco' => $request->string('banco')];
        $error = null;
        try {
            $res = $this->ben->paginate($f, Paginator::pageFrom($request), self::PER_PAGE);
        } catch (ValidationException $e) {
            $error = implode(' ', $e->errors());
            $res = ['rows' => [], 'total' => 0, 'page' => 1];
        }
        return $this->render('beneficiaries/index', [
            'title' => 'Beneficiarios', 'breadcrumbs' => [['Personas', null], ['Beneficiarios', null]],
            'rows' => $res['rows'], 'filters' => $f, 'searchError' => $error,
            'paginator' => new Paginator($res['page'], self::PER_PAGE, $res['total'], '/beneficiarios', array_filter($f)),
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        return $this->detail($request, $id, null);
    }

    public function notes(Request $request, int $id): Response
    {
        try {
            $this->ben->updateNotes($this->user($request), $id, $request->string('observaciones'));
        } catch (ValidationException $e) {
            return $this->redirectWith('/beneficiarios/' . $id, 'error', implode(' ', $e->errors()));
        }
        return $this->redirectWith('/beneficiarios/' . $id, 'success', 'Observaciones guardadas.');
    }

    public function deactivateRelation(Request $request, int $id, int $relacion): Response
    {
        try {
            $this->ben->deactivateRelation($this->user($request), $id, $relacion, $request->string('motivo'));
        } catch (ValidationException $e) {
            return $this->redirectWith('/beneficiarios/' . $id . '?tab=trabajadores', 'error', implode(' ', $e->errors()));
        }
        return $this->redirectWith('/beneficiarios/' . $id . '?tab=trabajadores', 'success', 'Relación desactivada (se conserva en el historial).');
    }

    public function revealAccount(Request $request, int $id, int $cuenta): Response
    {
        try {
            $plain = $this->ben->revealAccount($this->user($request), $id, $cuenta);
        } catch (ValidationException $e) {
            return $this->redirectWith('/beneficiarios/' . $id . '?tab=cuentas', 'error', implode(' ', $e->errors()));
        }
        return $this->detail($request, $id, 'cuentas', [$cuenta => $plain])
            ->withHeader('Cache-Control', 'no-store, max-age=0')->withHeader('Pragma', 'no-cache');
    }

    /** @param array<int,string> $revealed */
    private function detail(Request $request, int $id, ?string $tab, array $revealed = []): Response
    {
        $tab ??= $request->string('tab', 'resumen');
        if (!in_array($tab, BeneficiaryService::TABS, true)) {
            $tab = 'resumen';
        }
        if ($tab === 'auditoria' && !$this->user($request)->can('auditoria.ver')) {
            $tab = 'resumen';
        }
        $b = $this->ben->find($id, $tab);
        return $this->render('beneficiaries/show', [
            'title' => 'Beneficiario', 'breadcrumbs' => [['Personas', null], ['Beneficiarios', '/beneficiarios'], ['#' . $id, null]],
            'b' => $b, 'tab' => $tab, 'revealed' => $revealed,
        ]);
    }
}
