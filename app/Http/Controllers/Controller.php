<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\HttpException;
use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Core\Session\Session;
use ConciliaRH\Core\View;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\ValidationException;

abstract class Controller
{
    protected View $view;
    protected Session $session;

    public function boot(View $view, Session $session): void
    {
        $this->view = $view;
        $this->session = $session;
    }

    /** @param array<string,mixed> $data */
    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        $errors = $this->session->get('_errors', []);
        $this->session->remove('_errors');
        return Response::html($this->view->render($template, [
            'flashes' => $this->session->consumeFlashes(),
            'old' => $this->session->consumeOldInput(),
            'errors' => is_array($errors) ? $errors : [],
            ...$data,
        ]), $status);
    }

    protected function user(Request $request): CurrentUser
    {
        $user = $request->attribute('user');
        if (!$user instanceof CurrentUser) {
            throw HttpException::unauthenticated();
        }
        return $user;
    }

    protected function redirectWith(string $to, string $type, string $message): Response
    {
        $this->session->flash($type, $message);
        return Response::redirect($to);
    }

    /** Devuelve al formulario con errores por campo y la entrada previa (sin contraseñas). */
    protected function backWithErrors(string $to, Request $request, ValidationException $e): Response
    {
        $this->session->set('_errors', $e->errors());
        $this->session->flashInput($request->all());
        $this->session->flash('error', 'Revise los datos marcados.');
        return Response::redirect($to);
    }
}
