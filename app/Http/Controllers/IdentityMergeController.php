<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\Paginator;
use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Identity\MergeService;
use ConciliaRH\Domain\ValidationException;

/** Posibles duplicados y fusión NO destructiva de empleados (FASE 7, docs/IDENTITY_MERGE.md). Uno a uno; nunca en bloque. */
final class IdentityMergeController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(private readonly MergeService $merge)
    {
    }

    public function duplicates(Request $request): Response
    {
        $f = ['estado' => $request->string('estado'), 'confianza' => $request->string('confianza'), 'q' => mb_substr($request->string('q'), 0, 80)];
        $error = null;
        try {
            $res = $this->merge->duplicates($f, Paginator::pageFrom($request), self::PER_PAGE);
        } catch (ValidationException $e) {
            $error = implode(' ', $e->errors());
            $res = ['rows' => [], 'total' => 0, 'page' => 1, 'conteos' => []];
        }
        return $this->render('identity/duplicates', [
            'title' => 'Posibles duplicados', 'breadcrumbs' => [['Personas', null], ['Empleados', '/empleados'], ['Posibles duplicados', null]],
            'rows' => $res['rows'], 'conteos' => $res['conteos'], 'filters' => $f, 'searchError' => $error,
            'paginator' => new Paginator($res['page'], self::PER_PAGE, $res['total'], '/empleados/duplicados', array_filter($f)),
            'puedeFusionar' => $this->user($request)->can('empleados.fusionar'),
        ]);
    }

    public function detect(Request $request): Response
    {
        try {
            $id = $this->merge->detectNow($this->user($request));
        } catch (ValidationException $e) {
            return $this->redirectWith('/empleados/duplicados', 'error', implode(' ', $e->errors()));
        }
        return $this->redirectWith('/empleados/duplicados', 'success', "Detección encolada (proceso #$id). Los resultados aparecerán al terminar.");
    }

    public function dismiss(Request $request, int $id): Response
    {
        try {
            $this->merge->dismiss($this->user($request), $id, $request->string('motivo'));
        } catch (ValidationException $e) {
            return $this->redirectWith('/empleados/duplicados', 'error', implode(' ', $e->errors()));
        }
        return $this->redirectWith('/empleados/duplicados', 'success', 'Par descartado. Sólo volverá a proponerse si aparece evidencia más fuerte.');
    }

    public function preview(Request $request): Response
    {
        $int = static fn (string $v): ?int => ctype_digit($v) && strlen($v) < 19 ? (int) $v : null;
        $o = $int($request->string('origen'));
        $d = $int($request->string('destino'));
        if ($o === null || $d === null) {
            return $this->redirectWith('/empleados/duplicados', 'error', 'Elija el empleado origen y el destino.');
        }
        try {
            $p = $this->merge->preview($this->user($request), $o, $d);
        } catch (ValidationException $e) {
            return $this->redirectWith('/empleados/duplicados', 'error', implode(' ', $e->errors()));
        }
        return $this->render('identity/merge_preview', [
            'title' => 'Previsualizar fusión', 'breadcrumbs' => [['Personas', null], ['Posibles duplicados', '/empleados/duplicados'], ['Fusión', null]],
            'p' => $p,
        ]);
    }

    public function merge(Request $request): Response
    {
        $int = static fn (string $v): int => ctype_digit($v) && strlen($v) < 19 ? (int) $v : 0;
        $o = $int($request->string('origen'));
        $d = $int($request->string('destino'));
        $aceptados = $request->stringList('aceptados');
        try {
            $r = $this->merge->merge($this->user($request), $o, $d, $request->string('motivo'), $aceptados, $request->string('huella'));
        } catch (ValidationException $e) {
            return $this->redirectWith("/empleados/fusion?origen=$o&destino=$d", 'error', implode(' ', $e->errors()));
        }
        if ($r['tipo'] === 'solicitud') {
            return $this->redirectWith('/empleados/solicitudes-fusion/' . $r['id'], 'success',
                'Solicitud registrada. Por tener conflictos graves, la fusión se aplicará sólo cuando otra persona autorizada la apruebe.');
        }
        return $this->redirectWith('/empleados/fusiones/' . $r['id'], 'success', 'Fusión aplicada. Las conciliaciones históricas no cambian; la próxima ejecución usará la identidad canónica.');
    }

    public function requests(Request $request): Response
    {
        $f = ['estado' => $request->string('estado')];
        return $this->render('identity/requests', [
            'title' => 'Solicitudes de fusión', 'breadcrumbs' => [['Personas', null], ['Posibles duplicados', '/empleados/duplicados'], ['Solicitudes de fusión', null]],
            'rows' => $this->merge->requests($f), 'filters' => $f,
        ]);
    }

    public function showRequest(Request $request, int $id): Response
    {
        return $this->render('identity/request', [
            'title' => 'Solicitud de fusión #' . $id,
            'breadcrumbs' => [['Personas', null], ['Solicitudes de fusión', '/empleados/solicitudes-fusion'], ['#' . $id, null]],
            's' => $this->merge->request($this->user($request), $id),
        ]);
    }

    public function approveRequest(Request $request, int $id): Response
    {
        $to = '/empleados/solicitudes-fusion/' . $id;
        try {
            $r = $this->merge->approve($this->user($request), $id, $request->string('motivo'));
        } catch (ValidationException $e) {
            return $this->redirectWith($to, 'error', implode(' ', $e->errors()));
        }
        return match ($r['estado']) {
            'EJECUTADA' => $this->redirectWith('/empleados/fusiones/' . $r['fusion_id'], 'success', 'Solicitud aprobada: la fusión se aplicó con doble firma.'),
            'EXPIRADA' => $this->redirectWith($to, 'warning', 'La solicitud expiró antes de aprobarse. Debe solicitarse de nuevo.'),
            default => ($r['motivo'] ?? '') === 'SOLICITANTE_SIN_PERMISO'
                ? $this->redirectWith($to, 'warning', 'Quien solicitó ya no está activo o ya no tiene permiso para solicitarla: se canceló sin aplicar nada.')
                : $this->redirectWith($to, 'warning', 'El análisis de los empleados cambió desde la solicitud: se canceló y debe solicitarse de nuevo.'),
        };
    }


    public function rejectRequest(Request $request, int $id): Response
    {
        return $this->decideRequest($request, $id, 'RECHAZADA', 'Solicitud rechazada. No se aplicó ninguna fusión.');
    }

    public function cancelRequest(Request $request, int $id): Response
    {
        return $this->decideRequest($request, $id, 'CANCELADA', 'Solicitud cancelada.');
    }

    private function decideRequest(Request $request, int $id, string $decision, string $ok): Response
    {
        try {
            $this->merge->decide($this->user($request), $id, $decision, $request->string('motivo'));
        } catch (ValidationException $e) {
            return $this->redirectWith('/empleados/solicitudes-fusion/' . $id, 'error', implode(' ', $e->errors()));
        }
        return $this->redirectWith('/empleados/solicitudes-fusion/' . $id, 'success', $ok);
    }

    public function fusions(Request $request): Response
    {
        return $this->render('identity/fusions', [
            'title' => 'Fusiones de empleados', 'breadcrumbs' => [['Personas', null], ['Posibles duplicados', '/empleados/duplicados'], ['Fusiones', null]],
            'rows' => $this->merge->fusions(),
        ]);
    }

    public function fusion(Request $request, int $id): Response
    {
        return $this->render('identity/fusion', [
            'title' => 'Fusión #' . $id, 'breadcrumbs' => [['Personas', null], ['Fusiones', '/empleados/fusiones'], ['#' . $id, null]],
            'f' => $this->merge->fusion($id), 'puedeSeparar' => $this->user($request)->can('empleados.separar'),
        ]);
    }

    public function separate(Request $request, int $id): Response
    {
        try {
            $this->merge->separate($this->user($request), $id, $request->string('motivo'));
        } catch (ValidationException $e) {
            return $this->redirectWith("/empleados/fusiones/$id", 'error', implode(' ', $e->errors()));
        }
        return $this->redirectWith("/empleados/fusiones/$id", 'success', 'Fusión separada: el empleado origen vuelve a ser independiente. La fusión queda en el historial.');
    }
}
