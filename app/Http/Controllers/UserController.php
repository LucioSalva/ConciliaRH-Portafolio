<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\Paginator;
use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Users\UserRepository;
use ConciliaRH\Domain\Users\UserService;
use ConciliaRH\Domain\ValidationException;

final class UserController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly UserService $service,
        private readonly UserRepository $repo,
        private readonly \ConciliaRH\Core\Config $config,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = [
            'q' => mb_substr($request->string('q'), 0, 80),
            'rol' => preg_match('/^[A-Z][A-Z0-9_]{1,39}$/', $request->string('rol')) ? $request->string('rol') : '',
            'estado' => in_array($request->string('estado'), ['vigentes', 'activos', 'inactivos', 'eliminados', 'todos'], true)
                ? $request->string('estado') : 'vigentes',
        ];
        $page = Paginator::pageFrom($request);
        $result = $this->repo->paginate($filters, $page, self::PER_PAGE, (int) $this->config->get('session.idle_minutes', 30));
        return $this->render('users/index', [
            'title' => 'Usuarios',
            'breadcrumbs' => [['Administración', null], ['Usuarios', null]],
            'users' => $result['rows'],
            'filters' => $filters,
            'roles' => $this->service->assignableRoles($this->user($request)),
            'paginator' => new Paginator($result['page'], self::PER_PAGE, $result['total'], '/usuarios', $filters),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->render('users/form', [
            'title' => 'Nuevo usuario',
            'breadcrumbs' => [['Administración', null], ['Usuarios', '/usuarios'], ['Nuevo', null]],
            'target' => null,
            'roles' => $this->service->assignableRoles($this->user($request)),
            'minLength' => $this->service->policy()->minLength(),
        ]);
    }

    public function store(Request $request): Response
    {
        try {
            $id = $this->service->create(
                $this->user($request),
                $request->string('username'),
                $request->string('nombre'),
                is_string($request->input('password')) ? (string) $request->input('password') : '',
                $request->stringList('roles'),
            );
        } catch (ValidationException $e) {
            return $this->backWithErrors('/usuarios/nuevo', $request, $e);
        }
        return $this->redirectWith('/usuarios/' . $id, 'success', 'Usuario creado. Deberá cambiar su contraseña temporal al iniciar sesión.');
    }

    public function edit(Request $request, int $id): Response
    {
        $actor = $this->user($request);
        $target = $this->repo->find($id);
        if ($target === null) {
            throw \ConciliaRH\Core\HttpException::notFound();
        }
        return $this->render('users/form', [
            'title' => 'Usuario ' . $target['username'],
            'breadcrumbs' => [['Administración', null], ['Usuarios', '/usuarios'], [$target['username'], null]],
            'target' => $target,
            'canManage' => $this->service->canManage($actor, $target),
            'isSelf' => $actor->id === $id,
            'roles' => $this->service->assignableRoles($actor),
            'minLength' => $this->service->policy()->minLength(),
        ]);
    }

    public function update(Request $request, int $id): Response
    {
        try {
            $this->service->update($this->user($request), $id, $request->string('nombre'), $request->stringList('roles'));
        } catch (ValidationException $e) {
            return $this->backWithErrors('/usuarios/' . $id, $request, $e);
        }
        return $this->redirectWith('/usuarios/' . $id, 'success', 'Cambios guardados.');
    }

    public function toggleActive(Request $request, int $id): Response
    {
        $active = $request->string('activo') === '1';
        try {
            $this->service->setActive($this->user($request), $id, $active);
        } catch (ValidationException $e) {
            return $this->redirectWith('/usuarios/' . $id, 'error', $e->getMessage());
        }
        return $this->redirectWith('/usuarios/' . $id, 'success', $active ? 'Usuario activado.' : 'Usuario desactivado y sesiones cerradas.');
    }

    public function destroy(Request $request, int $id): Response
    {
        try {
            $this->service->delete($this->user($request), $id, $request->string('motivo'));
        } catch (ValidationException $e) {
            return $this->redirectWith('/usuarios/' . $id, 'error', $e->getMessage());
        }
        return $this->redirectWith('/usuarios', 'success', 'Usuario eliminado lógicamente. Su historial se conserva.');
    }

    public function resetPassword(Request $request, int $id): Response
    {
        try {
            $this->service->resetPassword($this->user($request), $id, is_string($request->input('password')) ? (string) $request->input('password') : '');
        } catch (ValidationException $e) {
            return $this->redirectWith('/usuarios/' . $id, 'error', $e->getMessage());
        }
        return $this->redirectWith('/usuarios/' . $id, 'success', 'Contraseña temporal asignada. Sus sesiones fueron cerradas.');
    }

    public function revokeSessions(Request $request, int $id): Response
    {
        $n = $this->service->revokeSessions($this->user($request), $id);
        return $this->redirectWith('/usuarios/' . $id, 'success', sprintf('Se cerraron %d sesiones.', $n));
    }
}
