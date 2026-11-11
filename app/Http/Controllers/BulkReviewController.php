<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\Paginator;
use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Identity\AssociationService;
use ConciliaRH\Domain\Identity\BulkReviewService;
use ConciliaRH\Domain\ValidationException;

final class BulkReviewController extends Controller
{
    private const PER_PAGE = 100;

    public function __construct(private readonly BulkReviewService $bulk)
    {
    }

    public function index(Request $request): Response
    {
        $f = $this->filters($request);
        $res = $this->bulk->search($f, Paginator::pageFrom($request), self::PER_PAGE);
        return $this->render('bulk/index', [
            'title' => 'Revisión masiva',
            'breadcrumbs' => [['Avanzado', null], ['Revisión masiva', null]],
            'rows' => $res['rows'], 'total' => $res['total'], 'importeTotal' => $res['importe_total'], 'filters' => $f,
            'counts' => $this->bulk->counts(), 'recent' => $this->bulk->recentActions(8),
            'paginator' => new Paginator($res['page'], self::PER_PAGE, $res['total'], '/revision-masiva',
                array_filter(array_map(static fn ($v) => $v === null ? '' : (string) $v, $f))),
        ]);
    }

    public function preview(Request $request): Response
    {
        try {
            $p = $this->bulk->preview($this->user($request), $request->string('accion'), $request->stringList('items'), $this->options($request));
        } catch (ValidationException $e) {
            return $this->redirectWith('/revision-masiva', 'error', implode(' ', $e->errors()));
        }
        return $this->render('bulk/preview', [
            'title' => 'Previsualización',
            'breadcrumbs' => [['Avanzado', null], ['Revisión masiva', '/revision-masiva'], ['Previsualización', null]],
            'p' => $p,
        ]);
    }

    public function execute(Request $request): Response
    {
        try {
            $id = $this->bulk->execute($this->user($request), $request->string('accion'), $request->stringList('items'), $this->options($request),
                $request->string('token'), $request->string('motivo'));
        } catch (ValidationException $e) {
            return $this->redirectWith('/revision-masiva', 'error', implode(' ', $e->errors()));
        }
        return $this->redirectWith('/revision-masiva/acciones/' . $id, 'success', 'Acción masiva aplicada. Cada registro quedó en el historial.');
    }

    public function action(Request $request, int $id): Response
    {
        return $this->render('bulk/action', [
            'title' => 'Acción masiva #' . $id,
            'breadcrumbs' => [['Avanzado', null], ['Revisión masiva', '/revision-masiva'], ['Acción #' . $id, null]],
            'a' => $this->bulk->action($id),
        ]);
    }

    /** @return array<string,mixed> */
    private function filters(Request $request): array
    {
        $int = static fn (string $v): ?int => ctype_digit($v) && strlen($v) < 19 ? (int) $v : null;
        $dec = static fn (string $v): ?string => preg_match('/^\d{1,12}(\.\d{1,2})?$/', $v) ? $v : null;
        $pick = static fn (string $v, array $ok): string => in_array($v, $ok, true) ? $v : '';
        return [
            'categoria' => $pick($request->string('categoria'), ['NOMINA', 'PENSION']),
            'estado' => $pick($request->string('estado'), AssociationService::PENDING),
            'banco' => $pick($request->string('banco'), ['SANTANDER', 'BBVA', 'INTERBANCARIA']),
            'grupo' => $pick($request->string('grupo'), ['POLICIA', 'BOMBEROS']), 'periodo' => $int($request->string('periodo')), 'lote' => $int($request->string('lote')),
            'min' => $dec($request->string('min')), 'max' => $dec($request->string('max')), 'q' => mb_substr($request->string('q'), 0, 80),
            'grupo_cuenta' => preg_match('/^[0-9a-f]{12}$/', $request->string('grupo_cuenta')) ? $request->string('grupo_cuenta') : '',
            'confianza' => $pick($request->string('confianza'), ['MEDIA', 'BAJA', 'NINGUNA']), 'orden' => $request->string('orden'),
        ];
    }

    /** @return array{confirmar_cuenta:bool,registrar_beneficiario:bool} */
    private function options(Request $request): array
    {
        return ['confirmar_cuenta' => $request->string('confirmar_cuenta') === '1', 'registrar_beneficiario' => $request->string('registrar_beneficiario') === '1'];
    }
}
