<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Imports\ImportException;
use ConciliaRH\Domain\Imports\ImportService;
use ConciliaRH\Domain\Imports\UploadedFile;
use ConciliaRH\Domain\Lots\LotService;
use ConciliaRH\Domain\Periods\PeriodCalendar;
use ConciliaRH\Domain\Periods\PendingIdentityService;
use ConciliaRH\Domain\Periods\PeriodService;
use ConciliaRH\Domain\Periods\QuincenaService;
use ConciliaRH\Domain\ValidationException;

final class QuincenaController extends Controller
{
    public function __construct(
        private readonly PeriodService $periods,
        private readonly QuincenaService $quincena,
        private readonly LotService $lots,
        private readonly ImportService $imports,
        private readonly PendingIdentityService $pending,
    ) {
    }

    public function show(Request $request, int $id): Response
    {
        $period = $this->periods->find($id);
        $dup = $this->session->get('_quincena_duplicado');
        $this->session->remove('_quincena_duplicado');
        $user = $this->user($request);
        return $this->render('periods/show', [
            'title' => (string) $period['descripcion'],
            'breadcrumbs' => [['Quincenas', '/periodos'], [(string) $period['descripcion'], null]],
            'period' => $period,
            'q' => $this->quincena->overview($id),
            'lots' => $user->can('lotes.declarar_oficial') ? $this->lots->byPeriod($id) : [],
            'meses' => PeriodCalendar::MESES,
            'poblaciones' => QuincenaService::POBLACIONES,
            'columnas' => QuincenaService::COLUMNAS,
            'opcionales' => QuincenaService::OPCIONALES,
            'duplicado' => is_string($dup) ? $dup : null,
            'maxUpload' => $this->imports->maxUploadSize(),
        ]);
    }

    public function upload(Request $request, int $id): Response
    {
        $back = '/periodos/' . $id;
        $columna = $request->string('columna');
        $pob = $request->string('poblacion');
        $poblacion = isset(QuincenaService::POBLACIONES[$pob]) ? $pob : null;
        if ($request->exceededPostMaxSize()) {
            return $this->redirectWith($back, 'error', 'Los archivos superan el tamaño máximo permitido. Cárguelos de uno en uno.');
        }
        try {
            $r = $this->quincena->upload($this->user($request), $id, $columna, $poblacion, UploadedFile::fromMultiple($request->file('archivos')),
                $request->string('confirmar_duplicado') === '1');
        } catch (ValidationException $e) {
            return $this->redirectWith($back, 'error', implode(' ', $e->errors()));
        } catch (ImportException $e) {
            return $this->redirectWith($back, 'error', $e->getMessage());
        }
        foreach ($r['errores'] as $err) {
            $e = $err['error'];
            $msg = $e instanceof ValidationException ? implode(' ', $e->errors()) : $e->getMessage();
            if ($e instanceof ImportException && $e->errorCode() === 'DUPLICADO_EXACTO_REQUIERE_CONFIRMACION') {
                $this->session->set('_quincena_duplicado', $columna . ':' . ($poblacion ?? ''));
            }
            $this->session->flash('error', '«' . $err['archivo'] . '»: ' . $msg);
        }
        if ($r['cargados'] > 0) {
            $this->session->flash('success', $r['cargados'] === 1
                ? 'Archivo recibido. El sistema lo revisa y lo lee; en unos momentos verá su resultado aquí.'
                : $r['cargados'] . ' archivos recibidos. El sistema los revisa y los lee; en unos momentos verá su resultado aquí.');
        }
        return Response::redirect($back);
    }

    public function conciliate(Request $request, int $id): Response
    {
        try {
            $cid = $this->quincena->cotejar($this->user($request), $id, $request->string('motivo'));
        } catch (ValidationException $e) {
            return $this->redirectWith('/periodos/' . $id, 'error', implode(' ', $e->errors()));
        }
        return $this->redirectWith('/conciliaciones/' . $cid, 'success', 'Cotejo en curso. Los resultados aparecerán aquí al terminar.');
    }

    public function population(Request $request, int $archivo): Response
    {
        $periodo = (int) $request->string('periodo_id');
        $back = $periodo > 0 ? '/periodos/' . $periodo : '/importaciones/' . $archivo;
        try {
            $this->imports->setPopulation($this->user($request), $archivo, $request->string('poblacion'));
        } catch (ValidationException $e) {
            return $this->redirectWith($back, 'error', implode(' ', $e->errors()));
        } catch (ImportException $e) {
            return $this->redirectWith($back, 'error', $e->getMessage());
        }
        return $this->redirectWith($back, 'success', 'Población del archivo actualizada. Vuelva a cotejar para aplicarla.');
    }

    public function pendingList(Request $request, int $id): Response
    {
        $period = $this->periods->find($id);
        $lot = $this->quincena->workingLot($id);
        $loteId = $lot !== null ? (int) $lot['id'] : 0;
        $bloque = $loteId > 0 ? $this->pending->bulkPreview($this->user($request), $loteId) : ['preview' => null, 'items' => []];
        return $this->render('periods/pending', [
            'title' => 'Pendientes de identificar',
            'breadcrumbs' => [['Quincenas', '/periodos'], [(string) $period['descripcion'], '/periodos/' . $id], ['Pendientes de identificar', null]],
            'period' => $period,
            'exactos' => $loteId > 0 ? $this->pending->exactMatches($loteId) : [],
            'bloque' => $bloque,
            'tesoreria' => $loteId > 0 ? $this->pending->pendingTreasury($loteId) : [],
            'rrhh' => $loteId > 0 ? $this->pending->pendingRrhh($loteId) : [],
            'motivo' => PendingIdentityService::MOTIVO_BLOQUE,
        ]);
    }

    public function confirmBulk(Request $request, int $id): Response
    {
        $back = '/periodos/' . $id . '/pendientes';
        $items = $request->all()['items'] ?? [];
        try {
            $this->pending->confirmBulk($this->user($request), is_array($items) ? array_values(array_filter($items, 'is_string')) : [],
                $request->string('token'), $request->string('motivo'));
        } catch (ValidationException $e) {
            return $this->redirectWith($back, 'error', implode(' ', $e->errors()));
        }
        $n = count(is_array($items) ? $items : []);
        return $this->redirectWith($back, 'success', $n === 1 ? 'Se confirmó 1 pago. Vuelva a cotejar para ver el resultado.'
            : "Se confirmaron $n pagos. Su cuenta queda recordada para las próximas quincenas. Vuelva a cotejar para ver el resultado.");
    }
}
