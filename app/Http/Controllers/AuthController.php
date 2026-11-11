<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Auth\AuthService;

final class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function showLogin(Request $request): Response
    {
        return $this->render('auth/login', ['title' => 'Iniciar sesión']);
    }

    public function login(Request $request): Response
    {
        $username = $request->string('username');
        $password = is_string($request->input('password')) ? (string) $request->input('password') : '';
        $result = $this->auth->attempt($username, $password, $request->ip());
        if (!$result['ok']) {
            $this->session->flashInput(['username' => mb_substr($username, 0, 50)]);
            return $this->redirectWith('/login', 'error', (string) $result['message']);
        }
        return Response::redirect('/');
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout($this->user($request));
        return $this->redirectWith('/login', 'success', 'Sesión cerrada correctamente.');
    }
}
