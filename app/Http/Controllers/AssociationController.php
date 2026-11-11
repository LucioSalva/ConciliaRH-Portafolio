<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\Paginator;
use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Identity\AssociationService;
use ConciliaRH\Domain\Imports\ImportException;
use ConciliaRH\Domain\ValidationException;

final class AssociationController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly AssociationService $assoc)
    {
    }

    public function index(Request $request): Response
    {
        $id = static fn (string $v): ?int => ctype_digit($v) && $v !== '0' && strlen($v) < 19 ? (int) $v : null;
        $estado = $request->string('estado', 'pendientes');
        $f = [
            'estado' => in_array($estado, AssociationService::FILTERS, true) ? $estado : 'pendientes',
            'origen' => in_array($request->string('origen'), ['RRHH', 'TESORERIA'], true) ? $request->string('origen') : '',
            'banco' => in_array($request->string('banco'), ['SANTANDER', 'BBVA', 'INTERBANCARIA', 'PENSION'], true) ? $request->string('banco') : '',
            'periodo' => $id($request->string('periodo')),
            'lote' => $id($request->string('lote')),
            'archivo' => $id($request->string('archivo')),
            'q' => mb_substr($request->string('q'), 0, 80),
        ];
        $result = $this->assoc->paginate($f, Paginator::pageFrom($request), self::PER_PAGE);
        return $this->render('associations/index', [
            'title' => 'Asociaciones',
            'breadcrumbs' => [['Avanzado', null], ['Asociaciones', null]],
            'rows' => $result['rows'],
            'filters' => $f,
            'counts' => $this->assoc->counts(),
            'paginator' => new Paginator($result['page'], self::PER_PAGE, $result['total'], '/asociaciones',
                array_filter($f, static fn ($v) => $v !== null && $v !== '')),
        ]);
    }

    public function showRrhh(Request $request, int $id): Response
    {
        return $this->show($request, 'RRHH', $id);
    }

    public function showTesoreria(Request $request, int $id): Response
    {
        return $this->show($request, 'TESORERIA', $id);
    }

    public function associateRrhh(Request $request, int $id): Response
    {
        return $this->associate($request, 'RRHH', $id);
    }

    public function associateTesoreria(Request $request, int $id): Response
    {
        return $this->associate($request, 'TESORERIA', $id);
    }

    public function rejectRrhh(Request $request, int $id): Response
    {
        return $this->reject($request, 'RRHH', $id);
    }

    public function rejectTesoreria(Request $request, int $id): Response
    {
        return $this->reject($request, 'TESORERIA', $id);
    }

    public function unidentifiedRrhh(Request $request, int $id): Response
    {
        return $this->unidentified($request, 'RRHH', $id);
    }

    public function unidentifiedTesoreria(Request $request, int $id): Response
    {
        return $this->unidentified($request, 'TESORERIA', $id);
    }

    public function incidents(Request $request): Response
    {
        $f = ['estado' => $request->string('estado', 'ABIERTA'), 'tipo' => $request->string('tipo'), 'severidad' => $request->string('severidad')];
        $result = $this->assoc->incidents($f, Paginator::pageFrom($request), self::PER_PAGE);
        return $this->render('associations/incidents', [
            'title' => 'Incidencias de identidad',
            'breadcrumbs' => [['Avanzado', null], ['Incidencias de identidad', null]],
            'rows' => $result['rows'],
            'filters' => $f,
            'types' => $this->assoc->incidentTypes(),
            'paginator' => new Paginator($result['page'], self::PER_PAGE, $result['total'], '/incidencias-identidad', array_filter($f)),
        ]);
    }

    public function closeIncident(Request $request, int $id): Response
    {
        try {
            $this->assoc->closeIncident($this->user($request), $id, $request->string('estado'), $request->string('motivo'));
        } catch (ValidationException $e) {
            return $this->redirectWith('/incidencias-identidad', 'error', implode(' ', $e->errors()));
        }
        return $this->redirectWith('/incidencias-identidad', 'success', 'Incidencia cerrada.');
    }

    public function normalize(Request $request, int $id): Response
    {
        try {
            $this->assoc->requestNormalization($this->user($request), $id, $request->string('modo') === 'completa');
        } catch (ImportException|ValidationException $e) {
            $msg = $e instanceof ValidationException ? implode(' ', $e->errors()) : $e->getMessage();
            return $this->redirectWith('/importaciones/' . $id . '#normalizacion', 'error', $msg);
        }
        return $this->redirectWith('/importaciones/' . $id . '#normalizacion', 'success', 'Normalización encolada.');
    }

    public function api(Request $request, int $id): Response
    {
        $n = $this->assoc->normalization($id);
        if ($n === null) {
            return Response::apiOk(['normalizacion' => null]);
        }
        $m = $n['metricas'];
        return Response::apiOk(['normalizacion' => [
            'importacion' => (int) $n['numero'], 'estado' => $n['estado'], 'tipo_registro' => $n['tipo_registro'], 'perfil' => $n['perfil'],
            'registros' => (int) $n['registros'], 'puede_actualizar_maestro' => (bool) $n['puede_actualizar_maestro'],
            'motivo_sin_maestro' => $n['motivo_sin_maestro'], 'por_estado' => $m['por_estado'] ?? new \stdClass(),
            'por_metodo' => $m['por_metodo'] ?? new \stdClass(), 'altas' => $m['altas'] ?? 0, 'cuentas_nuevas' => $m['cuentas_nuevas'] ?? 0,
            'incidencias_abiertas' => $n['incidencias'], 'matching_version' => (int) $n['matching_version'],
        ]]);
    }

    private function show(Request $request, string $tipo, int $id): Response
    {
        $r = $this->assoc->find($this->user($request), $tipo, $id);
        return $this->render('associations/show', [
            'title' => 'Registro ' . $tipo . ' #' . $id,
            'breadcrumbs' => [['Avanzado', null], ['Asociaciones', '/asociaciones'], [$tipo . ' #' . $id, null]],
            'r' => $r,
            'base' => '/asociaciones/' . strtolower($tipo) . '/' . $id,
        ]);
    }

    private function associate(Request $request, string $tipo, int $id): Response
    {
        return $this->act($request, $tipo, $id, function () use ($request, $tipo, $id): string {
            $emp = $request->string('numero_empleado') !== '' ? $this->assoc->employeeIdByNumber($request->string('numero_empleado'))
                : $this->intInput($request, 'empleado_id');
            $this->assoc->associate($this->user($request), $tipo, $id, $emp, $this->intInput($request, 'version', true),
                $request->string('motivo'), $request->string('confirmar_cuenta') === '1');
            return 'Registro asociado manualmente.';
        });
    }

    private function reject(Request $request, string $tipo, int $id): Response
    {
        return $this->act($request, $tipo, $id, function () use ($request, $tipo, $id): string {
            $this->assoc->reject($this->user($request), $tipo, $id, $this->intInput($request, 'empleado_id'), $this->intInput($request, 'version', true),
                $request->string('motivo'));
            return 'Sugerencia rechazada: no se volverá a proponer para este registro.';
        });
    }

    private function unidentified(Request $request, string $tipo, int $id): Response
    {
        return $this->act($request, $tipo, $id, function () use ($request, $tipo, $id): string {
            $this->assoc->markUnidentified($this->user($request), $tipo, $id, $this->intInput($request, 'version', true), $request->string('motivo'));
            return 'Registro marcado como no identificado.';
        });
    }

    private function intInput(Request $request, string $key, bool $allowZero = false): int
    {
        $v = $request->string($key);
        if (!ctype_digit($v) || strlen($v) > 18 || (!$allowZero && (int) $v === 0)) {
            throw new ValidationException([$key => 'Valor numérico inválido.']);
        }
        return (int) $v;
    }

    private function act(Request $request, string $tipo, int $id, callable $fn): Response
    {
        $to = '/asociaciones/' . strtolower($tipo) . '/' . $id;
        try {
            $msg = $fn();
        } catch (ValidationException $e) {
            return $this->redirectWith($to, 'error', implode(' ', $e->errors()));
        }
        return $this->redirectWith($to, 'success', $msg);
    }
}
