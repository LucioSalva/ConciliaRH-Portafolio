<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\Paginator;
use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Periods\PeriodCalendar;
use ConciliaRH\Domain\Periods\PeriodService;
use ConciliaRH\Domain\ValidationException;

final class PeriodController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private readonly PeriodService $periods)
    {
    }

    public function index(Request $request): Response
    {
        $int = static fn (string $v, int $min, int $max): ?int => ctype_digit($v) && (int) $v >= $min && (int) $v <= $max ? (int) $v : null;
        $f = [
            'anio' => $int($request->string('anio'), 2000, 2100),
            'mes' => $int($request->string('mes'), 1, 12),
            'quincena' => $int($request->string('quincena'), 1, 2),
            'estado' => in_array($request->string('estado'), ['ABIERTO', 'CERRADO', 'ANULADO'], true) ? $request->string('estado') : '',
        ];
        $page = Paginator::pageFrom($request);
        $result = $this->periods->paginate($f, $page, self::PER_PAGE);
        return $this->render('periods/index', [
            'title' => 'Quincenas',
            'breadcrumbs' => [['Quincenas', null]],
            'rows' => $result['rows'],
            'filters' => $f,
            'years' => $this->periods->years(),
            'meses' => PeriodCalendar::MESES,
            'paginator' => new Paginator($result['page'], self::PER_PAGE, $result['total'], '/periodos', array_filter($f, static fn ($v) => $v !== null && $v !== '')),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->render('periods/form', [
            'title' => 'Nueva quincena',
            'breadcrumbs' => [['Quincenas', '/periodos'], ['Nueva', null]],
            'period' => null,
            'meses' => PeriodCalendar::MESES,
            'currentYear' => (int) date('Y'),
        ]);
    }

    public function store(Request $request): Response
    {
        try {
            $id = $this->periods->create(
                $this->user($request),
                (int) $request->string('anio'),
                (int) $request->string('mes'),
                (int) $request->string('quincena'),
                $request->string('tipo', 'ORDINARIA'),
                $request->string('descripcion'),
                $request->string('fecha_inicio'),
                $request->string('fecha_fin'),
            );
        } catch (ValidationException $e) {
            return $this->backWithErrors('/periodos/nuevo', $request, $e);
        }
        return $this->redirectWith('/periodos/' . $id, 'success', 'Quincena creada. Cargue cada archivo en su lugar.');
    }

    public function update(Request $request, int $id): Response
    {
        try {
            $this->periods->update($this->user($request), $id, $request->string('descripcion'), $request->string('fecha_inicio'), $request->string('fecha_fin'));
        } catch (ValidationException $e) {
            return $this->backWithErrors('/periodos/' . $id, $request, $e);
        }
        return $this->redirectWith('/periodos/' . $id, 'success', 'Periodo actualizado.');
    }

    public function close(Request $request, int $id): Response
    {
        return $this->act($id, fn () => $this->periods->close($this->user($request), $id, $request->string('motivo')), 'Periodo cerrado: ya no admite nuevas cargas.');
    }

    public function reopen(Request $request, int $id): Response
    {
        return $this->act($id, fn () => $this->periods->reopen($this->user($request), $id, $request->string('motivo')), 'Periodo reabierto (acción excepcional auditada).');
    }

    public function annul(Request $request, int $id): Response
    {
        return $this->act($id, fn () => $this->periods->annul($this->user($request), $id, $request->string('motivo')), 'Periodo anulado.');
    }

    private function act(int $id, callable $fn, string $ok): Response
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $this->redirectWith('/periodos/' . $id, 'error', $e->getMessage());
        }
        return $this->redirectWith('/periodos/' . $id, 'success', $ok);
    }
}
