<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\HttpException;
use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Catalogs\CatalogService;
use ConciliaRH\Domain\ValidationException;

final class CatalogController extends Controller
{
    public function __construct(private readonly CatalogService $catalogs)
    {
    }

    public function index(Request $request): Response
    {
        return $this->render('catalogs/index', [
            'title' => 'Catálogos documentales',
            'breadcrumbs' => [['Administración', null], ['Catálogos', null]],
            'tipos' => $this->catalogs->all('tipos'),
            'origenes' => $this->catalogs->all('origenes'),
            'perfiles' => $this->catalogs->profiles(),
            'canAdmin' => $this->user($request)->can('catalogos.administrar'),
        ]);
    }

    public function store(Request $request): Response
    {
        $catalog = $this->catalog($request);
        try {
            $this->catalogs->create($this->user($request), $catalog, $request->string('codigo'), $request->string('nombre'), $request->string('descripcion'));
        } catch (ValidationException $e) {
            return $this->redirectWith('/catalogos', 'error', $e->getMessage());
        }
        return $this->redirectWith('/catalogos', 'success', 'Entrada de catálogo creada.');
    }

    public function toggle(Request $request, int $id): Response
    {
        $catalog = $this->catalog($request);
        try {
            $this->catalogs->setActive($this->user($request), $catalog, $id, $request->string('activo') === '1');
        } catch (ValidationException $e) {
            return $this->redirectWith('/catalogos', 'error', $e->getMessage());
        }
        return $this->redirectWith('/catalogos', 'success', 'Catálogo actualizado.');
    }

    private function catalog(Request $request): string
    {
        $c = $request->string('catalogo');
        if (!array_key_exists($c, CatalogService::CATALOGS)) {
            throw HttpException::notFound();
        }
        return $c;
    }
}
