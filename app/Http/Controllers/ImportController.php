<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\FileResponse;
use ConciliaRH\Domain\Analysis\AnalysisService;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Core\Paginator;
use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Identity\AssociationService;
use ConciliaRH\Domain\Imports\FilenameSanitizer;
use ConciliaRH\Domain\Imports\ImportException;
use ConciliaRH\Domain\Imports\ImportService;
use ConciliaRH\Domain\Imports\UploadedFile;
use ConciliaRH\Domain\Jobs\JobQueue;
use ConciliaRH\Domain\Lots\LotService;
use ConciliaRH\Domain\ValidationException;

final class ImportController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly ImportService $imports,
        private readonly LotService $lots,
        private readonly JobQueue $jobs,
        private readonly AnalysisService $analysis,
        private readonly AssociationService $associations,
    ) {
    }

    public function index(Request $request): Response
    {
        $id = static fn (string $v): ?int => ctype_digit($v) && $v !== '0' && strlen($v) < 19 ? (int) $v : null;
        $f = [
            'periodo' => $id($request->string('periodo')),
            'lote' => $id($request->string('lote')),
            'tipo' => $id($request->string('tipo')),
            'estado' => $request->string('estado'),
            'duplicidad' => $request->string('duplicidad'),
            'q' => mb_substr($request->string('q'), 0, 80),
        ];
        $page = Paginator::pageFrom($request);
        $result = $this->imports->paginate($f, $page, self::PER_PAGE);
        return $this->render('imports/index', [
            'title' => 'Archivos cargados',
            'breadcrumbs' => [['Avanzado', null], ['Archivos cargados', null]],
            'rows' => $result['rows'],
            'filters' => $f,
            'types' => $this->imports->activeTypes(),
            'paginator' => new Paginator($result['page'], self::PER_PAGE, $result['total'], '/importaciones',
                array_filter($f, static fn ($v) => $v !== null && $v !== '')),
        ]);
    }

    public function create(Request $request): Response
    {
        $lote = $request->string('lote');
        $uploadError = $this->session->get('_upload_error');
        $this->session->remove('_upload_error');
        return $this->render('imports/form', [
            'title' => 'Cargar archivo',
            'breadcrumbs' => [['Avanzado', null], ['Archivos cargados', '/importaciones'], ['Cargar archivo', null]],
            'lots' => $this->lots->openForUpload(),
            'types' => $this->imports->activeTypes(),
            'origins' => $this->imports->activeOrigins(),
            'defaultOrigin' => $this->imports->defaultOriginId(),
            'selectedLot' => ctype_digit($lote) ? (int) $lote : (int) $this->session->get('_ultimo_lote', 0),
            'maxBytes' => $this->imports->maxUploadSize(),
            'uploadError' => is_array($uploadError) ? $uploadError : null,
        ]);
    }

    public function store(Request $request): Response
    {
        $this->session->remove('_upload_error');
        $loteId = (int) $request->string('lote_id');
        $this->session->set('_ultimo_lote', $loteId);
        try {
            $id = $this->imports->upload(
                $this->user($request),
                $loteId,
                (int) $request->string('tipo_documento_id'),
                (int) $request->string('origen_administrativo_id'),
                $request->string('observaciones'),
                UploadedFile::fromArray($request->file('archivo')),
                $request->string('confirmar_duplicado') === '1',
            );
        } catch (ImportException $e) {
            $this->session->set('_upload_error', [
                'code' => $e->errorCode(),
                'message' => $e->getMessage(),
                'donde' => $e->context()['donde'] ?? null,
            ]);
            $this->session->flashInput(['lote_id' => $loteId, 'tipo_documento_id' => $request->string('tipo_documento_id'),
                'origen_administrativo_id' => $request->string('origen_administrativo_id'), 'observaciones' => $request->string('observaciones')]);
            return Response::redirect('/importaciones/nueva');
        } catch (ValidationException $e) {
            return $this->backWithErrors('/importaciones/nueva', $request, $e);
        }
        return $this->redirectWith('/importaciones/' . $id, 'success', 'Archivo recibido y almacenado. La verificación de seguridad está en curso.');
    }

    public function show(Request $request, int $id): Response
    {
        $file = $this->imports->find($id);
        $user = $this->user($request);
        return $this->render('imports/show', [
            'title' => 'Archivo #' . $id,
            'breadcrumbs' => [['Quincenas', $user->can('periodos.ver') ? '/periodos' : null],
                [(string) $file['periodo_descripcion'], $user->can('periodos.ver') ? '/periodos/' . (int) $file['periodo_id'] : null],
                ['Lote ' . sprintf('%03d', (int) $file['lote_numero']), $user->can('lotes.ver') ? '/lotes/' . (int) $file['lote_id'] : null], ['Archivo #' . $id, null]],
            'file' => $file,
            'pipeline' => $this->imports->pipeline($id),
            'job' => $file['proceso_id'] !== null && $user->can('procesos.ver')
                ? $this->jobs->find((int) $file['proceso_id'], $user->can('procesos.ver_detalle_tecnico')) : null,
            'types' => $this->imports->activeTypes(),
            'origins' => $this->imports->activeOrigins(),
            'normalization' => $user->can('asociaciones.ver') ? $this->associations->normalization($id) : null,
            'normJob' => $user->can('asociaciones.ver') ? $this->associations->lastNormalizationJob($id) : null,
        ] + $this->analysisData($user, $id));
    }

    private function analysisData(\ConciliaRH\Domain\Auth\CurrentUser $user, int $id): array
    {
        if (!$user->can('analisis.ver')) {
            return ['analysis' => null];
        }
        $an = $this->analysis->current($id);
        $diag = $user->can('analisis.ver_diagnostico');
        $sheets = $an && $diag ? $this->analysis->sheets((int) $an['id']) : [];
        $selected = null;
        foreach ($sheets as $s) {
            if ($s['seleccionada']) {
                $selected = $s;
            }
        }
        return [
            'analysis' => $an,
            'controls' => $an ? $this->analysis->controls((int) $an['id']) : [],
            'sheets' => $sheets,
            'columns' => $selected ? $this->analysis->columns((int) $selected['id']) : [],
            'related' => $this->analysis->related($id),
            'history' => $diag ? $this->analysis->history($id) : [],
            'profiles' => $user->can('analisis.seleccionar_perfil') ? $this->analysis->selectableProfiles() : [],
        ];
    }

    public function reclassify(Request $request, int $id): Response
    {
        return $this->act($id, fn () => $this->imports->reclassify($this->user($request), $id,
            (int) $request->string('tipo_documento_id'), (int) $request->string('origen_administrativo_id')), 'Clasificación actualizada.');
    }

    public function reprocess(Request $request, int $id): Response
    {
        return $this->act($id, fn () => $this->imports->reprocess($this->user($request), $id), 'Verificación encolada nuevamente.');
    }

    public function annul(Request $request, int $id): Response
    {
        return $this->act($id, fn () => $this->imports->annul($this->user($request), $id, $request->string('motivo')), 'Archivo anulado. El original se conserva.');
    }

    public function download(Request $request, int $id): Response
    {
        try {
            $d = $this->imports->prepareDownload($this->user($request), $id, $request->string('confirmar_macros') === '1');
        } catch (ImportException $e) {
            if ($e->errorCode() === 'INTEGRIDAD_ERROR') {
                throw new HttpException(409, $e->errorCode(), $e->getMessage());
            }
            return $this->redirectWith('/importaciones/' . $id, 'error', $e->getMessage());
        }
        return FileResponse::download($d['path'], FilenameSanitizer::asciiFallback($d['nombre']), $d['nombre'], $d['tamano']);
    }

    public function status(Request $request, int $id): Response
    {
        $f = $this->imports->find($id);
        return Response::apiOk([
            'id' => (int) $f['id'],
            'estado' => $f['estado'],
            'clasificacion_duplicidad' => $f['clasificacion_duplicidad'],
            'error_codigo' => $f['error_codigo'],
            'error_resumen' => $f['error_resumen'],
            'proceso' => $f['proceso_id'] === null ? null : [
                'id' => (int) $f['proceso_id'], 'estado' => $f['proceso_estado'], 'etapa' => $f['proceso_etapa'], 'progreso' => $f['proceso_progreso'],
            ],
        ]);
    }

    private function act(int $id, callable $fn, string $ok): Response
    {
        try {
            $fn();
        } catch (ImportException|ValidationException $e) {
            return $this->redirectWith('/importaciones/' . $id, 'error', $e->getMessage());
        }
        return $this->redirectWith('/importaciones/' . $id, 'success', $ok);
    }
}
