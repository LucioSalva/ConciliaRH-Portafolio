<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\Paginator;
use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Conciliation\ConciliationComparison;
use ConciliaRH\Domain\Conciliation\ConciliationService;
use ConciliaRH\Domain\Conciliation\Exporter;
use ConciliaRH\Domain\ValidationException;

final class ConciliationController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(private readonly ConciliationService $conc, private readonly ConciliationComparison $cmp)
    {
    }

    public function index(Request $request): Response
    {
        return $this->render('conciliations/index', [
            'title' => 'Cotejos',
            'breadcrumbs' => [['Avanzado', null], ['Cotejos', null]],
            'rows' => $this->conc->list(),
            'lots' => $this->user($request)->can('conciliaciones.ejecutar') ? $this->conc->lots() : [],
        ]);
    }

    public function store(Request $request): Response
    {
        $lote = $request->string('lote_id');
        try {
            $id = $this->conc->request($this->user($request), ctype_digit($lote) && strlen($lote) < 19 ? (int) $lote : 0, $request->string('motivo'));
        } catch (ValidationException $e) {
            return $this->redirectWith('/conciliaciones', 'error', implode(' ', $e->errors()));
        }
        return $this->redirectWith('/conciliaciones/' . $id, 'success', 'Cotejo solicitado. Los resultados aparecerán cuando el sistema termine.');
    }

    public function show(Request $request, int $id): Response
    {
        $c = $this->conc->find($id);
        return $this->render('conciliations/show', [
            'title' => 'Cotejo n.º ' . $c['numero'],
            'breadcrumbs' => $this->trail($request, $c),
            'c' => $c,
            'controls' => $this->conc->controls($id),
            'files' => $this->conc->files($id),
            'cambios' => $this->cmp->identityChangesSince($c),
            'siblings' => $this->cmp->siblings($c),
            'esUltima' => $this->conc->isLatestFinished($c),
        ]);
    }

    public function compare(Request $request, int $id, int $otra): Response
    {
        $a = $this->conc->find($id);
        $b = $this->conc->find($otra);
        if ((int) $a['lote_id'] !== (int) $b['lote_id']) {
            throw \ConciliaRH\Core\HttpException::notFound();
        }
        try {
            $cmp = $this->cmp->compare($a, $b);
        } catch (ValidationException $e) {
            return $this->redirectWith('/conciliaciones/' . $id, 'error', implode(' ', $e->errors()));
        }
        return $this->render('conciliations/compare', [
            'title' => 'Comparativo entre cotejos',
            'breadcrumbs' => [['Avanzado', null], ['Cotejos', '/conciliaciones'], ['n.º ' . $cmp['a']['numero'] . ' contra n.º ' . $cmp['b']['numero'], null]],
            'cmp' => $cmp,
        ]);
    }

    public function employees(Request $request, int $id): Response
    {
        $c = $this->conc->find($id);
        $dec = static fn (string $v): ?string => preg_match('/^\d{1,12}(\.\d{1,2})?$/', $v) ? $v : null;
        $f = [
            'estado' => $request->string('estado_detalle') !== '' ? $request->string('estado_detalle') : $request->string('estado'),
            'grupo' => $request->string('grupo'),
            'banco' => $request->string('banco'),
            'area' => mb_substr($request->string('area'), 0, 80),
            'pension' => $request->string('pension') === '1',
            'incidencia' => $request->string('incidencia') === '1',
            'min' => $dec($request->string('min')),
            'max' => $dec($request->string('max')),
            'q' => mb_substr($request->string('q'), 0, 80),
        ];
        $error = null;
        try {
            $res = $this->conc->employees($this->user($request), $id, $f, Paginator::pageFrom($request), self::PER_PAGE);
        } catch (ValidationException $e) {
            $error = implode(' ', $e->errors());
            $res = ['rows' => [], 'total' => 0, 'page' => 1];
        }
        return $this->render('conciliations/employees', [
            'title' => 'Resultados por persona',
            'breadcrumbs' => $this->trail($request, $c, [['Personas', null]]),
            'c' => $c,
            'rows' => $res['rows'],
            'filters' => $f,
            'searchError' => $error,
            'counts' => $this->conc->resultCounts($id),
            'paginator' => new Paginator($res['page'], self::PER_PAGE, $res['total'], '/conciliaciones/' . $id . '/empleados',
                array_filter(['estado' => $f['estado'], 'grupo' => $f['grupo'], 'banco' => $f['banco'], 'area' => $f['area'],
                              'pension' => $f['pension'] ? '1' : '', 'incidencia' => $f['incidencia'] ? '1' : '', 'min' => $f['min'] ?? '',
                              'max' => $f['max'] ?? '', 'q' => $f['q']])),
        ]);
    }

    public function pensions(Request $request, int $id): Response
    {
        $c = $this->conc->find($id);
        $grupo = $request->string('grupo');
        $estado = $request->string('estado');
        $res = $this->conc->pensions($id, $grupo, $estado, Paginator::pageFrom($request), self::PER_PAGE);
        return $this->render('conciliations/pensions', [
            'title' => 'Pensiones alimenticias',
            'breadcrumbs' => $this->trail($request, $c, [['Pensiones', null]]),
            'c' => $c,
            'rows' => $res['rows'],
            'filters' => ['grupo' => $grupo, 'estado' => $estado],
            'paginator' => new Paginator($res['page'], self::PER_PAGE, $res['total'], '/conciliaciones/' . $id . '/pensiones',
                array_filter(['grupo' => $grupo, 'estado' => $estado])),
        ]);
    }

    public function employee(Request $request, int $id, int $empleado): Response
    {
        $c = $this->conc->find($id);
        $d = $this->conc->employeeDetail($this->user($request), $id, $empleado);
        return $this->render('conciliations/employee', [
            'title' => 'Resultado de la persona',
            'breadcrumbs' => $this->trail($request, $c, [['Personas', '/conciliaciones/' . $id . '/empleados'], [(string) ($d['empleado']['numero_empleado'] ?? $empleado), null]]),
            'c' => $c,
            'd' => $d,
        ]);
    }

    public function incidents(Request $request, int $id): Response
    {
        $c = $this->conc->find($id);
        $f = ['estado' => $request->string('estado', 'PENDIENTES'), 'severidad' => $request->string('severidad'), 'tipo' => $request->string('tipo'),
              'banco' => $request->string('banco'), 'empleado' => mb_substr($request->string('empleado'), 0, 20)];
        $res = $this->conc->incidents($id, $f, Paginator::pageFrom($request), self::PER_PAGE);
        return $this->render('conciliations/incidents', [
            'title' => 'Incidencias del cotejo',
            'breadcrumbs' => $this->trail($request, $c, [['Incidencias', null]]),
            'c' => $c,
            'rows' => $res['rows'],
            'filters' => $f,
            'types' => $this->conc->incidentTypes($id),
            'paginator' => new Paginator($res['page'], self::PER_PAGE, $res['total'], '/conciliaciones/' . $id . '/incidencias', array_filter($f)),
        ]);
    }

    public function updateIncident(Request $request, int $id, int $incidencia): Response
    {
        try {
            $this->conc->updateIncident($this->user($request), $id, $incidencia, $request->string('estado'), $request->string('motivo'),
                $request->string('evidencia'));
        } catch (ValidationException $e) {
            return $this->redirectWith('/conciliaciones/' . $id . '/incidencias', 'error', implode(' ', $e->errors()));
        }
        return $this->redirectWith('/conciliaciones/' . $id . '/incidencias', 'success', 'Incidencia actualizada. Los importes de origen no cambian.');
    }

    public function close(Request $request, int $id): Response
    {
        return $this->act($id, fn () => $this->conc->close($this->user($request), $id, $request->string('motivo')), 'Cotejo cerrado.');
    }

    public function invalidate(Request $request, int $id): Response
    {
        return $this->act($id, fn () => $this->conc->invalidate($this->user($request), $id, $request->string('motivo')), 'Cotejo invalidado (se conserva en el historial).');
    }

    public function export(Request $request, int $id): Response
    {
        $formato = $request->string('formato') === 'xlsx' ? 'xlsx' : 'csv';
        $data = $this->conc->exportRows($this->user($request), $id, $formato);
        $name = 'conciliacion-' . $id . '.' . $formato;
        $body = $formato === 'xlsx' ? Exporter::xlsx($data['columns'], $data['rows'], $data['numeric'])
            : Exporter::csv($data['columns'], $data['rows'], $data['numeric']);
        return new Response($body, 200, [
            'Content-Type' => $formato === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control' => 'no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function relatePolicy(Request $request, int $id): Response
    {
        $int = static fn (string $v): int => ctype_digit($v) && strlen($v) < 19 ? (int) $v : 0;
        [$eg, $hoja] = array_pad(explode(':', $request->string('hoja')), 2, '');
        $hoja = ctype_digit($hoja) && strlen($hoja) <= 5 && (int) $hoja <= 32767 ? (int) $hoja : -1;
        try {
            $this->conc->relatePolicy($this->user($request), $id, $int($eg), $hoja, $int($request->string('dispersion')), $request->string('motivo'));
        } catch (ValidationException $e) {
            return $this->redirectWith('/lotes/' . $id . '#polizas', 'error', implode(' ', $e->errors()));
        }
        return $this->redirectWith('/lotes/' . $id . '#polizas', 'success', 'Relación registrada. Se aplicará en el siguiente cotejo.');
    }

    public function annulPolicyRelation(Request $request, int $id, int $relacion): Response
    {
        try {
            $this->conc->annulPolicyRelation($this->user($request), $id, $relacion, $request->string('motivo'));
        } catch (ValidationException $e) {
            return $this->redirectWith('/lotes/' . $id . '#polizas', 'error', implode(' ', $e->errors()));
        }
        return $this->redirectWith('/lotes/' . $id . '#polizas', 'success', 'Relación anulada.');
    }

    public function api(Request $request, int $id): Response
    {
        $c = $this->conc->find($id);
        return Response::apiOk(['conciliacion' => [
            'id' => (int) $c['id'], 'numero' => (int) $c['numero'], 'estado' => $c['estado'], 'lote_oficial' => (bool) $c['lote_oficial'],
            'motor_version' => $c['motor_version'] !== null ? (int) $c['motor_version'] : null, 'tolerancia' => $c['tolerancia'],
            'resumen' => $c['resumen'] ?: new \stdClass(), 'totales' => $c['totales'] ?: new \stdClass(),
            'incidencias_por_estado' => $this->user($request)->can('incidencias.ver') ? ($c['incidencias_por_estado'] ?: new \stdClass()) : null,
        ]]);
    }

    private function act(int $id, callable $fn, string $ok): Response
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $this->redirectWith('/conciliaciones/' . $id, 'error', implode(' ', $e->errors()));
        }
        return $this->redirectWith('/conciliaciones/' . $id, 'success', $ok);
    }

    /**
     * @param array<string,mixed> $c @param list<array{0:string,1:?string}> $tail
     * @return list<array{0:string,1:?string}>
     */
    private function trail(Request $request, array $c, array $tail = []): array
    {
        $u = $this->user($request);
        return [['Quincenas', $u->can('periodos.ver') ? '/periodos' : null],
            [(string) $c['periodo'], $u->can('periodos.ver') ? '/periodos/' . (int) $c['periodo_id'] : null],
            ['Cotejo n.º ' . $c['numero'], $tail === [] ? null : '/conciliaciones/' . (int) $c['id']], ...$tail];
    }
}
