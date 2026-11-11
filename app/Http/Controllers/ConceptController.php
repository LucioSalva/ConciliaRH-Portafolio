<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Payroll\ConceptService;
use ConciliaRH\Domain\ValidationException;

/** Catálogo de conceptos de nómina (tipo y marca de pensión por código). */
final class ConceptController extends Controller
{
    public function __construct(private readonly ConceptService $concepts)
    {
    }

    public function index(Request $request): Response
    {
        return $this->render('concepts/index', [
            'title' => 'Conceptos de nómina',
            'breadcrumbs' => [['Administración', null], ['Conceptos de nómina', null]],
            'rows' => $this->concepts->all(),
        ]);
    }

    public function update(Request $request, int $id): Response
    {
        try {
            $this->concepts->update($this->user($request), $id, $request->string('tipo'), $request->string('es_pension') === '1', $request->string('motivo'));
        } catch (ValidationException $e) {
            return $this->redirectWith('/conceptos', 'error', implode(' ', $e->errors()));
        }
        return $this->redirectWith('/conceptos', 'success', 'Concepto actualizado (clasificación CONFIGURADA).');
    }
}
