<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Middleware;

use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;

interface Middleware
{
    /** @param callable(Request):Response $next */
    public function process(Request $request, callable $next): Response;
}
