<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Analysis\AnalysisService;
use ConciliaRH\Domain\Imports\ImportException;
use ConciliaRH\Domain\Imports\ImportService;
use ConciliaRH\Domain\ValidationException;

final class AnalysisController extends Controller
{
    public function __construct(
        private readonly AnalysisService $analysis,
        private readonly ImportService $imports,
    ) {
    }

    public function reanalyze(Request $request, int $id): Response
    {
        return $this->act($id, fn () => $this->analysis->reanalyze($this->user($request), $id), 'Análisis encolado nuevamente.');
    }

    public function selectSheet(Request $request, int $id): Response
    {
        $raw = $request->string('hoja');
        $indice = $raw === 'auto' ? null : (ctype_digit($raw) && strlen($raw) < 5 ? (int) $raw : -1);
        return $this->act($id, function () use ($request, $id, $indice): void {
            if ($indice === -1) {
                throw new ValidationException(['hoja' => 'Elija una hoja del análisis.']);
            }
            $this->analysis->selectSheet($this->user($request), $id, $indice, $request->string('motivo'));
        }, 'Hoja seleccionada. El análisis se ejecuta de nuevo con la decisión manual.');
    }

    public function selectProfile(Request $request, int $id): Response
    {
        $raw = $request->string('perfil');
        $perfil = $raw === 'auto' ? null : (ctype_digit($raw) && strlen($raw) < 19 ? (int) $raw : -1);
        return $this->act($id, function () use ($request, $id, $perfil): void {
            if ($perfil === -1) {
                throw new ValidationException(['perfil' => 'Elija un perfil.']);
            }
            $this->analysis->selectProfile($this->user($request), $id, $perfil, $request->string('motivo'));
        }, 'Perfil seleccionado. El análisis se ejecuta de nuevo con la decisión manual.');
    }

    public function compare(Request $request, int $id): Response
    {
        $other = $request->string('con');
        $file = $this->imports->find($id);
        $data = null;
        $error = null;
        if ($other !== '') {
            if (!ctype_digit($other) || strlen($other) > 18 || $other === '0') {
                $error = 'Indique el número (entero positivo) del archivo a comparar.';
            } else {
                try {
                    $data = $this->analysis->compare($id, (int) $other);
                } catch (ValidationException $e) {
                    $error = implode(' ', $e->errors());
                }
            }
        }
        return $this->render('imports/compare', [
            'title' => 'Comparar archivo #' . $id,
            'breadcrumbs' => [['Avanzado', null], ['Archivos cargados', '/importaciones'], ['#' . $id, '/importaciones/' . $id], ['Comparar', null]],
            'file' => $file,
            'data' => $data,
            'other' => $other,
            'error' => $error,
            'related' => $this->analysis->related($id),
        ]);
    }

    public function api(Request $request, int $id): Response
    {
        $this->imports->find($id);
        $an = $this->analysis->current($id);
        if ($an === null) {
            return Response::apiOk(['analisis' => null]);
        }
        $controls = array_map(static fn (array $c): array => [
            'codigo' => $c['codigo'], 'estado' => $c['estado'], 'hoja' => $c['hoja_indice'],
            'declarado' => $c['valor_declarado'], 'calculado' => $c['valor_calculado'], 'diferencia' => $c['diferencia'],
        ], $this->analysis->controls((int) $an['id']));
        return Response::apiOk(['analisis' => [
            'numero' => (int) $an['numero'], 'resultado' => $an['resultado'], 'perfil' => $an['perfil_codigo'],
            'confianza' => $an['perfil_confianza'], 'perfil_origen' => $an['perfil_origen'], 'hoja' => $an['hoja_indice'],
            'hoja_origen' => $an['hoja_origen'], 'filas_detalle' => (int) $an['filas_detalle'],
            'huella_version' => $an['huella_version'] !== null ? (int) $an['huella_version'] : null,
            'advertencias' => array_map(static fn (array $w): array => ['codigo' => $w['codigo'], 'severidad' => $w['severidad']], $an['advertencias'] ?? []),
            'controles' => $controls,
        ]]);
    }

    private function act(int $id, callable $fn, string $ok): Response
    {
        try {
            $fn();
        } catch (ImportException|ValidationException $e) {
            $msg = $e instanceof ValidationException ? implode(' ', $e->errors()) : $e->getMessage();
            return $this->redirectWith('/importaciones/' . $id . '#analisis', 'error', $msg);
        }
        return $this->redirectWith('/importaciones/' . $id . '#analisis', 'success', $ok);
    }
}
