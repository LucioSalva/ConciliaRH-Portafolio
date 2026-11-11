<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Conciliation\ConciliationService;
use ConciliaRH\Domain\Imports\ImportService;
use ConciliaRH\Domain\Lots\LotRelationService;
use ConciliaRH\Domain\Lots\LotService;
use ConciliaRH\Domain\ValidationException;

final class LotController extends Controller
{
    public function __construct(
        private readonly LotService $lots,
        private readonly ImportService $imports,
        private readonly LotRelationService $relations,
        private readonly ConciliationService $conciliations,
    ) {
    }

    public function store(Request $request, int $id): Response
    {
        try {
            $loteId = $this->lots->create($this->user($request), $id, $request->string('nombre'), $request->string('descripcion'),
                $request->string('origen'), $request->string('observaciones'));
        } catch (ValidationException $e) {
            return $this->redirectWith('/periodos/' . $id, 'error', $e->getMessage());
        }
        return $this->redirectWith('/lotes/' . $loteId, 'success', 'Lote creado. Ya puede cargar archivos.');
    }

    private const FILES_SHOWN = 200;

    public function show(Request $request, int $id): Response
    {
        $lot = $this->lots->find($id);
        $files = $this->imports->paginate(['lote' => $id], 1, self::FILES_SHOWN);
        return $this->render('lots/show', [
            'title' => sprintf('Lote %03d · %s', $lot['numero'], $lot['nombre']),
            'breadcrumbs' => [['Quincenas', '/periodos'], [(string) $lot['periodo_descripcion'], '/periodos/' . $lot['periodo_id']],
                [sprintf('Lote %03d', $lot['numero']), null]],
            'lot' => $lot,
            'files' => $files['rows'],
            'filesTotal' => $files['total'],
            'relations' => $this->relations->forLot($id),
            'relationCandidates' => $this->user($request)->can('lotes.relacionar') ? $this->relations->candidates($id) : [],
            'conciliations' => $this->user($request)->can('conciliaciones.ver') ? $this->conciliations->list($id, 20) : null,
            'policyRelations' => $this->user($request)->can('conciliaciones.ver') ? $this->conciliations->policyRelations($id) : [],
            'policyCandidates' => $this->user($request)->can('conciliaciones.relacionar') ? $this->conciliations->relationCandidates($id) : null,
        ]);
    }

    public function relate(Request $request, int $id): Response
    {
        $dest = $request->string('destino');
        return $this->act($id, fn () => $this->relations->create($this->user($request), $id,
            ctype_digit($dest) && strlen($dest) < 19 ? (int) $dest : 0, $request->string('tipo'), $request->string('motivo')),
            'Relación entre lotes registrada.');
    }

    public function annulRelation(Request $request, int $id, int $relacion): Response
    {
        return $this->act($id, fn () => $this->relations->annul($this->user($request), $id, $relacion, $request->string('motivo')),
            'Relación anulada (se conserva en el historial).');
    }

    public function update(Request $request, int $id): Response
    {
        return $this->act($id, fn () => $this->lots->update($this->user($request), $id, $request->string('nombre'), $request->string('descripcion'),
            $request->string('origen'), $request->string('observaciones')), 'Lote actualizado.');
    }

    public function close(Request $request, int $id): Response
    {
        return $this->act($id, fn () => $this->lots->close($this->user($request), $id, $request->string('motivo')), 'Lote cerrado: ya no recibe archivos.');
    }

    public function reopen(Request $request, int $id): Response
    {
        return $this->act($id, fn () => $this->lots->reopen($this->user($request), $id, $request->string('motivo')), 'Lote reabierto (acción excepcional auditada).');
    }

    public function annul(Request $request, int $id): Response
    {
        return $this->act($id, fn () => $this->lots->annul($this->user($request), $id, $request->string('motivo')), 'Lote anulado.');
    }

    public function declareOfficial(Request $request, int $id): Response
    {
        return $this->act($id, fn () => $this->lots->declareOfficial($this->user($request), $id, $request->string('motivo')), 'Lote declarado oficial del periodo.');
    }

    private function act(int $id, callable $fn, string $ok): Response
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $this->redirectWith('/lotes/' . $id, 'error', $e->getMessage());
        }
        return $this->redirectWith('/lotes/' . $id, 'success', $ok);
    }
}
