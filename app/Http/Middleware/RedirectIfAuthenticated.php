<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Middleware;

use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Core\Session\Session;

final class RedirectIfAuthenticated implements Middleware
{
    public function __construct(private readonly Session $session)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if ($this->session->userId() !== null && $request->method() === 'GET') {
            return Response::redirect('/');
        }
        return $next($request);
    }
}
