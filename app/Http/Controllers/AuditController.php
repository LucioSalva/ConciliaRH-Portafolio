<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\HttpException;
use ConciliaRH\Core\Paginator;
use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Audit\AuditLogger;
use ConciliaRH\Domain\Audit\AuditRepository;

final class AuditController extends Controller
{
    private const PER_PAGE = 30;

    public function __construct(private readonly AuditRepository $repo, private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request): Response
    {
        $f = [
            'q' => mb_substr($request->string('q'), 0, 80),
            'accion' => preg_match('/^[a-z_.]{0,60}$/', $request->string('accion')) ? $request->string('accion') : '',
            'usuario' => mb_substr($request->string('usuario'), 0, 50),
            // Fechas inválidas no filtran ni cuentan como «filtro activo»
            'desde' => self::date($request->string('desde')),
            'hasta' => self::date($request->string('hasta')),
            'modulo' => preg_match('/^[a-z_]{0,40}$/', $request->string('modulo')) ? $request->string('modulo') : '',
            'entidad' => preg_match('/^[a-z_]{0,60}$/', $request->string('entidad')) ? $request->string('entidad') : '',
            'origen' => in_array($request->string('origen'), ['WEB', 'CLI', 'WORKER', 'SISTEMA'], true) ? $request->string('origen') : '',
            'resultado' => in_array($request->string('resultado'), ['EXITOSO', 'FALLIDO'], true) ? $request->string('resultado') : '',
        ];
        $page = Paginator::pageFrom($request);
        $result = $this->repo->paginate($f, $page, self::PER_PAGE);
        return $this->render('audit/index', [
            'title' => 'Auditoría',
            'breadcrumbs' => [['Administración', null], ['Auditoría', null]],
            'rows' => $result['rows'],
            'filters' => $f,
            'actions' => $this->repo->actions(),
            'modules' => $this->repo->modules(),
            'entities' => $this->repo->entities(),
            'paginator' => new Paginator($result['page'], self::PER_PAGE, $result['total'], '/auditoria', $f),
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $row = $this->repo->find($id);
        if ($row === null) {
            throw HttpException::notFound();
        }
        return $this->render('audit/show', [
            'title' => 'Registro de auditoría #' . $row['secuencia'],
            'breadcrumbs' => [['Administración', null], ['Auditoría', '/auditoria'], ['#' . $row['secuencia'], null]],
            'row' => $row,
        ]);
    }

    public function verify(Request $request): Response
    {
        $result = $this->repo->verify();
        $this->audit->record('auditoria.verificar', 'auditoria', null, null, null, $result);
        if ($result['integra']) {
            return $this->redirectWith('/auditoria', 'success', sprintf('Cadena íntegra: %d registros verificados.', $result['registros']));
        }
        return $this->redirectWith('/auditoria', 'error', sprintf(
            'ALERTA: la cadena de auditoría presenta una alteración a partir de la secuencia %d.',
            (int) $result['primer_secuencia_invalida']
        ));
    }

    private static function date(string $v): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v ? $v : '';
    }
}
