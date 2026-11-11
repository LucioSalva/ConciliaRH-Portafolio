<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Reports\ReportService;
use ConciliaRH\Domain\Reports\ScheduledReportService;
use ConciliaRH\Domain\ValidationException;

/**
 * Reportes programados y generados (FASE 7, docs/SCHEDULED_REPORTS.md). Todo cambio es POST + CSRF; la descarga pasa por
 * este controlador (nunca una URL de storage), con los permisos de quien descarga y la huella verificada.
 */
final class ScheduledReportController extends Controller
{
    private const MIME = ['xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'csv' => 'text/csv; charset=UTF-8',
                          'pdf' => 'application/pdf'];

    public function __construct(private readonly ScheduledReportService $svc, private readonly Database $db,
                                private readonly \ConciliaRH\Domain\Reports\ReportCipher $cipher)
    {
    }

    public function index(Request $request): Response
    {
        $u = $this->user($request);
        $todos = $request->string('todos') === '1' && ScheduledReportService::canAdmin($u);
        return $this->render('reports/scheduled_index', [
            'title' => 'Reportes programados', 'breadcrumbs' => [['Avanzado', null], ['Reportes', '/reportes'], ['Programados', null]],
            'rows' => $this->svc->list($u, $todos), 'todos' => $todos, 'admin' => ScheduledReportService::canAdmin($u),
            'tipos' => ReportService::TIPOS,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form($request, null, ['nombre' => '', 'tipo' => 'resumen', 'formato' => 'PDF', 'frecuencia' => 'QUINCENAL', 'hora' => 7,
            'dia_semana' => null, 'dia_mes' => null, 'alcance' => 'RECIENTE', 'lote_id' => null, 'sensible' => false, 'retencion_dias' => 30, 'version' => 0]);
    }

    public function store(Request $request): Response
    {
        try {
            $id = $this->svc->create($this->user($request), $this->input($request));
        } catch (ValidationException $e) {
            return $this->form($request, null, [...$this->input($request), 'version' => 0], $e->errors(), 422);
        }
        return $this->redirectWith('/reportes/programados', 'success', "Programación #$id creada.");
    }

    public function edit(Request $request, int $id): Response
    {
        return $this->form($request, $id, $this->svc->find($this->user($request), $id));
    }

    public function update(Request $request, int $id): Response
    {
        $version = ctype_digit($request->string('version')) ? (int) $request->string('version') : -1;
        try {
            $this->svc->update($this->user($request), $id, $this->input($request), $version);
        } catch (ValidationException $e) {
            return $this->form($request, $id, [...$this->svc->find($this->user($request), $id), ...$this->input($request)], $e->errors(), 422);
        }
        return $this->redirectWith('/reportes/programados', 'success', 'Programación actualizada.');
    }

    public function pause(Request $request, int $id): Response
    {
        $this->svc->setActive($this->user($request), $id, false, $request->string('motivo'));
        return $this->redirectWith('/reportes/programados', 'success', 'Programación pausada.');
    }

    public function activate(Request $request, int $id): Response
    {
        try {
            $this->svc->setActive($this->user($request), $id, true);
        } catch (ValidationException $e) {
            return $this->redirectWith('/reportes/programados', 'error', implode(' ', $e->errors()));
        }
        return $this->redirectWith('/reportes/programados', 'success', 'Programación reactivada.');
    }

    public function generate(Request $request, int $id): Response
    {
        try {
            $gid = $this->svc->generateNow($this->user($request), $id);
        } catch (ValidationException $e) {
            return $this->redirectWith('/reportes/programados', 'error', implode(' ', $e->errors()));
        }
        return $this->redirectWith('/reportes/generados', $gid === null ? 'warning' : 'success',
            $gid === null ? 'Ya hay una generación en curso para esta programación.' : "Generación #$gid encolada; aparecerá aquí al terminar.");
    }

    public function generated(Request $request): Response
    {
        $u = $this->user($request);
        $f = ['tipo' => $request->string('tipo'), 'estado' => $request->string('estado'), 'formato' => $request->string('formato'),
              'periodo' => $request->string('periodo'), 'desde' => $request->string('desde'), 'hasta' => $request->string('hasta')];
        $todos = $request->string('todos') === '1' && ScheduledReportService::canAdmin($u);
        return $this->render('reports/generated', [
            'title' => 'Reportes generados', 'breadcrumbs' => [['Avanzado', null], ['Reportes', '/reportes'], ['Generados', null]],
            'rows' => $this->svc->generated($u, $f, $todos), 'filters' => $f, 'todos' => $todos, 'admin' => ScheduledReportService::canAdmin($u),
            'tipos' => ReportService::TIPOS, 'estados' => ScheduledReportService::ESTADOS_GENERADO,
            'periodos' => $this->db->fetchAll('SELECT id, descripcion FROM periodos_nomina ORDER BY anio DESC, mes DESC, quincena DESC LIMIT 60'),
            'puedeDescargar' => $u->can('reportes.exportar'),
        ]);
    }

    public function download(Request $request, int $id): Response
    {
        try {
            [$tmp, $name, $ext, $cifrado] = $this->svc->download($this->user($request), $id);
        } catch (ValidationException $e) {
            return $this->redirectWith('/reportes/generados', 'error', implode(' ', $e->errors()));
        }
        $headers = ['Content-Type' => self::MIME[$ext], 'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Content-Length' => (string) ($cifrado['bytes'] ?? filesize($tmp)), 'Cache-Control' => 'no-store, max-age=0', 'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff'];
        if ($cifrado === null) {
            return (new Response('', 200, $headers))->withTempStream($tmp);
        }
        // Ya verificado por completo: se descifra segmento a segmento directo a la respuesta y se borra la copia cifrada
        $cipher = $this->cipher;
        register_shutdown_function(static function () use ($tmp): void {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        });
        return (new Response('', 200, $headers))->withStreamWriter(static function (callable $emit) use ($cipher, $tmp, $cifrado): void {
            try {
                $cipher->decrypt($tmp, $cifrado['contexto'], $emit);
            } catch (\Throwable $e) {
                // Ya se enviaron cabeceras: se corta la transmisión (el cliente recibe un archivo incompleto y el Content-Length no
                // cuadra); nunca se agrega HTML de error al cuerpo. Sólo el código, sin contenido.
                error_log('conciliarh: descarga de reporte interrumpida: ' . ($e instanceof \ConciliaRH\Domain\Reports\ReportCipherException ? $e->codigo : $e::class));
            } finally {
                @unlink($tmp);
            }
        });
    }

    /** @return array<string,mixed> sólo los campos del formulario (la lista blanca definitiva la aplica el servicio) */
    private function input(Request $request): array
    {
        $out = [];
        foreach (['nombre', 'tipo', 'formato', 'frecuencia', 'hora', 'dia_semana', 'dia_mes', 'alcance', 'lote_id', 'sensible', 'retencion_dias'] as $k) {
            $out[$k] = $request->string($k);
        }
        return $out;
    }

    /** @param array<string,mixed> $v @param array<string,string> $errors */
    private function form(Request $request, ?int $id, array $v, array $errors = [], int $status = 200): Response
    {
        return $this->render('reports/scheduled_form', [
            'title' => $id === null ? 'Nueva programación' : 'Programación #' . $id,
            'breadcrumbs' => [['Avanzado', null], ['Reportes programados', '/reportes/programados'], [$id === null ? 'Nueva' : '#' . $id, null]],
            'id' => $id, 'v' => $v, 'errors' => $errors, 'tipos' => ScheduledReportService::tipos(),
            'lotes' => $this->db->fetchAll("SELECT l.id, l.numero, p.descripcion AS periodo FROM lotes_importacion l JOIN periodos_nomina p ON p.id = l.periodo_id
                                             WHERE l.estado <> 'ANULADO' ORDER BY p.anio DESC, p.mes DESC, p.quincena DESC, l.numero LIMIT 100"),
            'puedeSensible' => ReportService::canExportIds($this->user($request)),
        ], $status);
    }
}
