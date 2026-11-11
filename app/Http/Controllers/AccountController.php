<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Core\Session\DatabaseSessionHandler;
use ConciliaRH\Domain\Auth\AuthService;
use ConciliaRH\Domain\Auth\LoginThrottle;
use ConciliaRH\Domain\Users\UserRepository;
use ConciliaRH\Domain\Users\UserService;
use ConciliaRH\Domain\ValidationException;

final class AccountController extends Controller
{
    public function __construct(
        private readonly UserService $users,
        private readonly LoginThrottle $throttle,
        private readonly UserRepository $repo,
        private readonly AuthService $auth,
    ) {
    }

    public function editPassword(Request $request): Response
    {
        $user = $this->user($request);
        return $this->render('account/password', [
            'title' => 'Cambiar contraseña',
            'breadcrumbs' => [['Mi cuenta', null], ['Cambiar contraseña', null]],
            'forced' => $user->mustChangePassword,
            'minLength' => $this->users->policy()->minLength(),
        ]);
    }

    public function updatePassword(Request $request): Response
    {
        $user = $this->user($request);
        $str = static fn (string $k): string => is_string($request->input($k)) ? (string) $request->input($k) : '';
        $subject = (string) $user->id;
        if ($this->throttle->isLocked($subject, $request->ip(), 'cambio_password')) {
            return $this->lockOut($user);
        }
        try {
            $this->users->changeOwnPassword(
                $user,
                $str('password_actual'),
                $str('password_nueva'),
                $str('password_confirmacion'),
                DatabaseSessionHandler::hashId($this->session->store()->id()),
            );
        } catch (ValidationException $e) {
            if (isset($e->errors()['password_actual'])) {
                // Quien secuestre una sesión no puede probar contraseñas sin límite.
                $this->throttle->record($subject, $request->ip(), false, 'cambio_password');
                if ($this->throttle->isLocked($subject, $request->ip(), 'cambio_password')) {
                    return $this->lockOut($user);
                }
            }
            return $this->backWithErrors('/cuenta/password', $request, $e);
        }
        $this->throttle->record($subject, $request->ip(), true, 'cambio_password');
        // Nuevo identificador de sesión tras cambiar credenciales (se vincula de nuevo al usuario en la BD).
        $this->session->store()->regenerate();
        $this->auth->bindSession($user->id, $request->ip());
        return $this->redirectWith('/', 'success', 'Su contraseña se actualizó. Las demás sesiones abiertas fueron cerradas.');
    }

    private function lockOut(\ConciliaRH\Domain\Auth\CurrentUser $user): Response
    {
        // Se revocan las DEMÁS sesiones con el actor todavía vigente; la actual se destruye al cerrar sesión.
        $this->repo->revokeSessions($user->id, $user->id, 'INTENTOS_CAMBIO_PASSWORD',
            DatabaseSessionHandler::hashId($this->session->store()->id()));
        $this->auth->logout($user);
        return $this->redirectWith('/login', 'warning', 'Por seguridad se cerraron sus sesiones tras varios intentos fallidos. Inicie sesión nuevamente.');
    }

    /** Información mínima de la sesión para el cliente (JS). */
    public function sessionInfo(Request $request): Response
    {
        $user = $this->user($request);
        return Response::apiOk([
            'usuario' => $user->username,
            'nombre' => $user->nombre,
            'roles' => $user->roles,
        ]);
    }
}
