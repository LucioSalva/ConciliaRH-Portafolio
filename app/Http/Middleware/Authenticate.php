<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Middleware;

use ConciliaRH\Core\Config;
use ConciliaRH\Core\Database;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Core\Session\Session;
use ConciliaRH\Core\View;
use ConciliaRH\Domain\Audit\AuditContext;
use ConciliaRH\Domain\Auth\AuthService;

/**
 * Exige sesión autenticada y vigente:
 *  - expiración por inactividad y absoluta;
 *  - el usuario debe seguir activo (se valida en BD en cada petición);
 *  - si debe cambiar su contraseña, sólo puede acceder a esa pantalla y a salir.
 */
final class Authenticate implements Middleware
{
    private const ALLOWED_WHILE_PASSWORD_CHANGE = ['/cuenta/password', '/logout'];

    public function __construct(
        private readonly Session $session,
        private readonly AuthService $auth,
        private readonly Config $config,
        private readonly AuditContext $auditContext,
        private readonly View $view,
        private readonly Database $db,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $data = $this->session->auth();
        $now = time();
        $idle = (int) $this->config->get('session.idle_minutes', 30) * 60;
        $absolute = (int) $this->config->get('session.absolute_hours', 10) * 3600;

        $user = null;
        if ($data !== null && ($now - $data['last_activity']) <= $idle && ($now - $data['auth_at']) <= $absolute) {
            $user = $this->auth->loadUser($data['user_id']);
        }
        if ($user === null) {
            $expired = $data !== null;
            if ($expired) {
                // Sólo se invalida si había una sesión autenticada (no se rompe el token CSRF
                // de otras pestañas anónimas, p. ej. el formulario de login).
                $this->session->logout();
            }
            if ($request->wantsJson()) {
                throw HttpException::unauthenticated();
            }
            if ($expired) {
                $this->session->flash('warning', 'Su sesión expiró. Inicie sesión nuevamente.');
            }
            return Response::redirect('/login');
        }

        // Las consultas de sondeo de la API (GET /api/*) no renuevan la inactividad:
        // una pestaña abierta sondeando no debe mantener viva la sesión indefinidamente.
        if (!($request->method() === 'GET' && str_starts_with($request->path(), '/api/'))) {
            $this->session->touch();
        }
        $request->setAttribute('user', $user);
        // El actor de TODA escritura en la BD es el usuario de esta sesión (lo verifica PostgreSQL)
        $this->db->bindActor($this->session->store()->id());
        $this->auditContext->setActor($user->id, $user->username, $user->rolesLabel());
        $this->view->share('currentUser', $user);

        if ($user->mustChangePassword && !in_array($request->path(), self::ALLOWED_WHILE_PASSWORD_CHANGE, true)) {
            if ($request->wantsJson()) {
                throw new HttpException(403, 'CAMBIO_PASSWORD_REQUERIDO', 'Debe cambiar su contraseña antes de continuar.');
            }
            return Response::redirect('/cuenta/password');
        }
        return $next($request);
    }
}
