<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Middleware;

use ConciliaRH\Core\HttpException;
use ConciliaRH\Core\Logger;
use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Auth\CurrentUser;

/** Verifica un permiso en el backend. La UI oculta opciones, pero la autorización real ocurre aquí. */
final class RequirePermission implements Middleware
{
    public function __construct(private readonly string $permission, private readonly Logger $logger)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $user = $request->attribute('user');
        if (!$user instanceof CurrentUser) {
            throw HttpException::unauthenticated();
        }
        if (!$user->can($this->permission)) {
            $this->logger->security('Acceso denegado', [
                'usuario_id' => $user->id, 'permiso' => $this->permission, 'path' => $request->path(), 'method' => $request->method(),
            ]);
            throw HttpException::forbidden();
        }
        return $next($request);
    }
}
