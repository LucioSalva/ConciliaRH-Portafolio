<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\Database;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Reports\ReportRenderer;
use ConciliaRH\Domain\Reports\ReportService;
use ConciliaRH\Domain\Settings\SettingsService;
use ConciliaRH\Domain\ValidationException;

/**
 * Reportes (FASE 6, docs/REPORTS.md). Vista en pantalla (GET) y exportación (POST + CSRF + permiso + auditoría).
 * Los archivos se generan bajo demanda, se transmiten y se borran: nunca se persisten ni se sirven desde public/.
 */
final class ReportController extends Controller
{
    private const FORMATOS = ['xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'csv' => 'text/csv; charset=UTF-8',
                              'pdf' => 'application/pdf'];
    public const LISTAS = ['empleados' => 'Todas', 'diferencias' => 'Con diferencias', 'no_pagados' => 'No pagadas', 'pagados_mas' => 'Pagadas de más',
                           'pagados_menos' => 'Pagadas de menos', 'pendientes' => 'Pagos pendientes de identificar'];
    public const MAS = ['pensiones', 'grupos', 'bancos', 'incidencias'];

    public function __construct(
        private readonly ReportService $reports,
        private readonly ReportRenderer $renderer,
        private readonly AuditLogger $audit,
        private readonly Database $db,
        private readonly SettingsService $settings,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->render('reports/index', [
            'title' => 'Reportes', 'breadcrumbs' => [['Consulta', null], ['Reportes', null]],
            'conciliaciones' => $this->reports->conciliations(), 'tipos' => ReportService::TIPOS,
        ]);
    }

    public function view(Request $request): Response
    {
        [$tipo, $cid, $otra] = $this->params($request);
        $lockKey = $this->lock($this->user($request)->id);
        try {
            $data = $this->reports->build($this->user($request), $tipo, $cid, ['sensible' => false, 'otra' => $otra]);
        } catch (ValidationException $e) {
            return $this->redirectWith('/reportes', 'error', implode(' ', $e->errors()));
        } finally {
            $this->db->fetchValue('SELECT pg_advisory_unlock(:k)', ['k' => $lockKey]);
        }
        return $this->render('reports/view', [
            'title' => $data['titulo'], 'breadcrumbs' => [['Consulta', null], ['Reportes', '/reportes'], [$data['titulo'], null]],
            'r' => $data, 'otra' => $otra, 'puedeSensible' => ReportService::canExportIds($this->user($request)),
        ]);
    }

    public function export(Request $request): Response
    {
        $user = $this->user($request);
        [$tipo, $cid, $otra] = $this->params($request);
        $formato = $request->string('formato');
        if (!isset(self::FORMATOS[$formato])) {
            throw HttpException::notFound();
        }
        if ($formato === 'pdf' && !(ReportService::TIPOS[$tipo][2] ?? false)) {
            return $this->redirectWith('/reportes', 'error', 'Este reporte no tiene versión PDF: use XLSX o CSV.');
        }
        $sensible = $request->string('sensible') === '1';
        // Límite por usuario y minuto + una exportación a la vez por usuario (evita saturar el servidor)
        $recent = (int) $this->db->fetchValue(
            "SELECT count(*) FROM auditoria WHERE usuario_id = :u AND accion = 'reportes.exportar' AND creado_at > now() - interval '1 minute'",
            ['u' => $user->id]);
        if ($recent >= $this->settings->int('reportes.max_por_minuto', 10)) {
            throw new HttpException(429, 'DEMASIADAS_EXPORTACIONES', 'Alcanzó el máximo de exportaciones por minuto. Intente de nuevo en un momento.');
        }
        $lockKey = $this->lock($user->id);
        $this->renderer->sweep();
        $path = null;
        try {
            $data = $this->reports->build($user, $tipo, $cid, ['sensible' => $sensible, 'otra' => $otra]);
            $fecha = (new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get())))->format('d/m/Y H:i');
            $path = $this->renderer->reserve($formato);
            $path = match ($formato) {
                'xlsx' => $this->renderer->xlsx($data, $user->username, $fecha),
                'csv' => $this->renderer->csv($data, $user->username, $fecha),
                'pdf' => $this->renderer->pdf($data, $user->username, $fecha),
            };
        } catch (ValidationException $e) {
            return $this->redirectWith('/reportes', 'error', implode(' ', $e->errors()));
        } catch (\Throwable $e) {
            if ($path !== null && is_file($path)) {
                @unlink($path);                                  // nunca queda un archivo parcial con datos
            }
            throw $e;
        } finally {
            $this->db->fetchValue('SELECT pg_advisory_unlock(:k)', ['k' => $lockKey]);
        }
        try {
            $this->audit->record('reportes.exportar', 'conciliaciones', $cid, null, null, [
            'reporte' => $tipo, 'formato' => $formato, 'conciliacion_id' => $cid, 'numero' => $data['conciliacion']['numero'],
            'comparada_con' => $otra, 'filas' => $data['filas'], 'sensible' => $sensible, 'huella' => $data['huella'], 'bytes' => filesize($path)]);
        } catch (\Throwable $e) {
            @unlink($path);                                      // sin auditoría no hay descarga
            throw $e;
        }
        return (new Response('', 200, [
            'Content-Type' => self::FORMATOS[$formato],
            'Content-Disposition' => 'attachment; filename="' . ReportRenderer::filename($data, $formato) . '"',
            'Content-Length' => (string) filesize($path),
            'Cache-Control' => 'no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff',
        ]))->withTempStream($path);
    }

    /** Una generación de reporte a la vez por usuario (vista y exportación): 429 si ya hay una en curso. */
    private function lock(int $userId): int
    {
        $key = crc32('conciliarh:reporte:' . $userId);
        if (!(bool) $this->db->fetchValue('SELECT pg_try_advisory_lock(:k)', ['k' => $key])) {
            throw new HttpException(429, 'EXPORTACION_EN_CURSO', 'Ya tiene un reporte en generación. Espere a que termine.');
        }
        return $key;
    }

    /** @return array{0:string,1:int,2:?int} */
    private function params(Request $request): array
    {
        $tipo = $request->string('tipo');
        if ($tipo === 'lista') {
            $tipo = $request->string('lista');
            if (!isset(self::LISTAS[$tipo])) {
                throw HttpException::notFound();
            }
        }
        if (!isset(ReportService::TIPOS[$tipo])) {
            throw HttpException::notFound();
        }
        $int = static fn (string $v): ?int => ctype_digit($v) && strlen($v) < 19 ? (int) $v : null;
        $cid = $int($request->string('conciliacion'));
        if ($cid === null) {
            throw HttpException::notFound();
        }
        return [$tipo, $cid, $int($request->string('otra'))];
    }
}
