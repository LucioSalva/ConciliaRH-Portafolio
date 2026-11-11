<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Settings\SettingsService;
use ConciliaRH\Domain\ValidationException;

final class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function index(Request $request): Response
    {
        return $this->render('settings/index', [
            'title' => 'Configuración',
            'breadcrumbs' => [['Administración', null], ['Configuración', null]],
            'settings' => $this->settings->all(),
            'canEdit' => $this->user($request)->can('configuracion.editar'),
        ]);
    }

    public function update(Request $request): Response
    {
        $key = $request->string('clave');
        try {
            $this->settings->update($this->user($request), $key, $request->string('valor'));
        } catch (ValidationException $e) {
            return $this->redirectWith('/configuracion', 'error', $key . ': ' . $e->getMessage());
        }
        return $this->redirectWith('/configuracion', 'success', 'Parámetro actualizado.');
    }
}
