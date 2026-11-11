<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\Paginator;
use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Identity\EmployeeService;
use ConciliaRH\Domain\ValidationException;

/** Empleado maestro (FASE 4): listado, búsqueda, detalle con pestañas, corrección manual y cuentas. */
final class EmployeeController extends Controller
{
    private const PER_PAGE = 25;
    public const TABS = ['resumen', 'identidad', 'rrhh', 'tesoreria', 'cuentas', 'incidencias', 'auditoria'];

    public function __construct(private readonly EmployeeService $employees)
    {
    }

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $f = [
            'q' => mb_substr($request->string('q'), 0, 80),
            'grupo' => $request->string('grupo'),
            'estado' => $request->string('estado'),
            'incidencias' => $request->string('incidencias') === '1',
        ];
        $error = null;
        try {
            $result = $this->employees->paginate($user, $f, Paginator::pageFrom($request), self::PER_PAGE);
        } catch (ValidationException $e) {
            $error = implode(' ', $e->errors());
            $result = ['rows' => [], 'total' => 0, 'page' => 1, 'criterio' => null];
        }
        return $this->render('employees/index', [
            'title' => 'Empleados',
            'breadcrumbs' => [['Personas', null], ['Empleados', null]],
            'rows' => $result['rows'],
            'filters' => $f,
            'criterio' => $result['criterio'],
            'searchError' => $error,
            'paginator' => new Paginator($result['page'], self::PER_PAGE, $result['total'], '/empleados',
                array_filter(['q' => $f['q'], 'grupo' => $f['grupo'], 'estado' => $f['estado'], 'incidencias' => $f['incidencias'] ? '1' : ''])),
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        return $this->detail($request, $id);
    }

    public function update(Request $request, int $id): Response
    {
        $input = [];
        foreach (['apellido_paterno', 'apellido_materno', 'nombres', 'issemym', 'rfc', 'curp', 'activo', 'observaciones'] as $k) {
            if ($request->input($k) !== null) {
                $input[$k] = $request->string($k);
            }
        }
        try {
            $n = $this->employees->update($this->user($request), $id, $input, $request->string('motivo'));
        } catch (ValidationException $e) {
            // RFC/CURP/ISSEMYM no se guardan en la sesión como "entrada previa"
            $this->session->set('_errors', $e->errors());
            $this->session->flashInput(array_diff_key($request->all(), array_flip(['rfc', 'curp', 'issemym', 'motivo'])));
            $this->session->flash('error', 'Revise los datos marcados.');
            return Response::redirect('/empleados/' . $id . '?tab=identidad');
        }
        return $this->redirectWith('/empleados/' . $id . '?tab=identidad', 'success', $n > 0 ? "Se actualizaron $n dato(s); el cambio quedó en el historial." : 'No hubo cambios.');
    }

    /** POST explícito: la respuesta muestra el número completo UNA vez (no se guarda en sesión ni en caché). */
    public function revealAccount(Request $request, int $id, int $cuenta): Response
    {
        try {
            $plain = $this->employees->revealAccount($this->user($request), $id, $cuenta);
        } catch (ValidationException $e) {
            return $this->redirectWith('/empleados/' . $id . '?tab=cuentas', 'error', implode(' ', $e->errors()));
        }
        return $this->detail($request, $id, 'cuentas', [$cuenta => $plain])
            ->withHeader('Cache-Control', 'no-store, max-age=0')->withHeader('Pragma', 'no-cache');
    }

    public function deactivateAccount(Request $request, int $id, int $cuenta): Response
    {
        try {
            $this->employees->deactivateAccount($this->user($request), $id, $cuenta, $request->string('motivo'));
        } catch (ValidationException $e) {
            return $this->redirectWith('/empleados/' . $id . '?tab=cuentas', 'error', implode(' ', $e->errors()));
        }
        return $this->redirectWith('/empleados/' . $id . '?tab=cuentas', 'success', 'Cuenta desactivada. Se conserva en el historial.');
    }

    /** @param array<int,string> $revealed */
    private function detail(Request $request, int $id, ?string $tab = null, array $revealed = []): Response
    {
        $user = $this->user($request);
        $emp = $this->employees->find($user, $id);
        $tab ??= $request->string('tab', 'resumen');
        if (!in_array($tab, self::TABS, true)) {
            $tab = 'resumen';
        }
        return $this->render('employees/show', [
            'title' => 'Empleado ' . $emp['numero_empleado'],
            'breadcrumbs' => [['Personas', null], ['Empleados', '/empleados'], [$emp['numero_empleado'], null]],
            'emp' => $emp,
            'tab' => $tab,
            'history' => $this->employees->history($user, $id),
            'rrhh' => $this->employees->rrhhRecords($user, $id),
            'treasury' => $this->employees->treasuryRecords($id),
            'accounts' => $this->employees->accounts($id),
            'incidents' => $this->employees->incidents($id),
            'audit' => $user->can('auditoria.ver') ? $this->employees->auditTrail($id) : [],
            'revealed' => $revealed,
        ]);
    }
}
