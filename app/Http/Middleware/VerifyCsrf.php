<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Middleware;

use ConciliaRH\Core\Config;
use ConciliaRH\Core\HttpException;
use ConciliaRH\Core\Logger;
use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Core\Session\Session;

/**
 * Protección CSRF para todo método no seguro:
 *  1) token sincronizador (campo _token o encabezado X-CSRF-Token);
 *  2) si el navegador envía Origin, debe coincidir con el host de la petición o APP_URL.
 */
final class VerifyCsrf implements Middleware
{
    public function __construct(
        private readonly Session $session,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }
        $origin = $request->header('origin');
        if ($origin !== null && $origin !== 'null' && !$this->originAllowed($origin, $request)) {
            $this->logger->security('Origin no permitido en petición mutante', ['origin' => $origin, 'path' => $request->path()]);
            throw HttpException::csrf();
        }
        $token = $request->header('x-csrf-token') ?? (is_string($request->input('_token')) ? $request->input('_token') : null);
        if (!$this->session->isValidCsrfToken($token)) {
            $this->logger->security('Token CSRF inválido', ['path' => $request->path(), 'ip' => $request->ip()]);
            throw HttpException::csrf();
        }
        return $next($request);
    }

    private function originAllowed(string $origin, Request $request): bool
    {
        $originHost = parse_url($origin, PHP_URL_HOST) . (($p = parse_url($origin, PHP_URL_PORT)) ? ':' . $p : '');
        $appUrl = (string) $this->config->get('app.url');
        $appHost = parse_url($appUrl, PHP_URL_HOST) . (($p = parse_url($appUrl, PHP_URL_PORT)) ? ':' . $p : '');
        return $originHost === $request->header('host') || $originHost === $appHost;
    }
}
