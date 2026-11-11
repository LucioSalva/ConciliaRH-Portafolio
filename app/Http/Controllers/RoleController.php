<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Roles\RoleService;
use ConciliaRH\Domain\ValidationException;

final class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles)
    {
    }

    public function index(Request $request): Response
    {
        return $this->render('roles/index', [
            'title' => 'Roles y permisos',
            'breadcrumbs' => [['Administración', null], ['Roles y permisos', null]],
            'roles' => $this->roles->all(),
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $role = $this->roles->find($id);
        return $this->render('roles/show', [
            'title' => 'Rol ' . $role['codigo'],
            'breadcrumbs' => [['Administración', null], ['Roles y permisos', '/roles'], [$role['codigo'], null]],
            'role' => $role,
            'modules' => $this->roles->permissionsByModule(),
            'editable' => $this->user($request)->can('roles.administrar') && $role['codigo'] !== 'GOD',
        ]);
    }

    public function store(Request $request): Response
    {
        try {
            $id = $this->roles->create($this->user($request), $request->string('codigo'), $request->string('nombre'), $request->string('descripcion'), $request->string('protegido') === '1');
        } catch (ValidationException $e) {
            return $this->backWithErrors('/roles', $request, $e);
        }
        return $this->redirectWith('/roles/' . $id, 'success', 'Rol creado. Asigne sus permisos.');
    }

    public function updatePermissions(Request $request, int $id): Response
    {
        $ids = array_map('intval', array_filter($request->stringList('permisos'), static fn ($v) => ctype_digit($v)));
        try {
            $this->roles->updatePermissions($this->user($request), $id, $ids);
        } catch (ValidationException $e) {
            return $this->redirectWith('/roles/' . $id, 'error', $e->getMessage());
        }
        return $this->redirectWith('/roles/' . $id, 'success', 'Permisos actualizados. Los usuarios con este rol los reciben en su siguiente petición.');
    }
}
