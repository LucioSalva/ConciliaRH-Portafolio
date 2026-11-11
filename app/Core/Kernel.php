<?php

declare(strict_types=1);

namespace ConciliaRH\Core;

use ConciliaRH\Core\Session\Session;
use ConciliaRH\Domain\Audit\AuditContext;
use ConciliaRH\Domain\ValidationException;
use ConciliaRH\Http\Controllers\Controller;
use ConciliaRH\Http\Middleware\Authenticate;
use ConciliaRH\Http\Middleware\Middleware;
use ConciliaRH\Http\Middleware\RedirectIfAuthenticated;
use ConciliaRH\Http\Middleware\RequirePermission;
use ConciliaRH\Http\Middleware\VerifyCsrf;
use Throwable;

/**
 * Ciclo de petición: sesión → enrutamiento → CSRF → middleware de ruta → controlador.
 * Todos los errores se convierten en respuestas sin detalles técnicos; las cabeceras
 * de seguridad se aplican a TODA respuesta, incluidas las de error.
 */
final class Kernel
{
    public function __construct(private readonly Container $c)
    {
    }

    public function handle(Request $request): Response
    {
        Logger::resetRequestId();
        try {
            $response = $this->dispatch($request);
        } catch (HttpException $e) {
            $response = $this->renderError($request, $e->status(), $e->errorCode(), $e->getMessage());
        } catch (ValidationException $e) {
            $response = $this->renderError($request, 422, 'VALIDACION', $e->getMessage());
        } catch (Throwable $e) {
            $ref = Logger::requestId();
            $this->c->get(Logger::class)->error('Excepción no controlada', [
                'exception' => $e, 'path' => $request->path(), 'method' => $request->method(),
            ]);
            $detail = $this->c->get(Config::class)->isDebug() ? ' [' . $e::class . ': ' . $e->getMessage() . ']' : '';
            $response = $this->renderError($request, 500, 'ERROR_INTERNO', 'Ocurrió un error inesperado. Referencia: ' . $ref . $detail);
        }
        return $this->secure($request, $response);
    }

    private function dispatch(Request $request): Response
    {
        if ($request->exceededPostMaxSize()) {
            throw new HttpException(413, 'UPLOAD_DEMASIADO_GRANDE', 'El archivo excede el tamaño máximo permitido por el servidor.');
        }
        // Modo mantenimiento (FASE 9): todo responde 503 salvo la salud pública, que lo informa al monitoreo
        if ($request->path() !== '/api/health' && $this->c->get(Maintenance::class)->current() !== null) {
            throw new HttpException(503, 'MANTENIMIENTO', 'ConciliaRH está en mantenimiento programado. Intente de nuevo en unos minutos.');
        }
        $match = $this->c->get(Router::class)->match($request->method(), $request->path());
        $route = $match['route'];
        $params = $match['params'];
        // Rutas 'stateless' (p. ej. /api/health, sólo GET) no abren sesión ni escriben en BD.
        $stateless = in_array('stateless', $route['middleware'], true);

        $view = $this->c->get(View::class);
        $view->share('currentPath', $request->path());
        $view->share('currentUser', null);
        $pipeline = [];
        if (!$stateless) {
            $session = $this->c->get(Session::class);
            $session->start();
            $view->share('csrfToken', $session->csrfToken());
            $pipeline[] = $this->c->get(VerifyCsrf::class);
        } elseif (!$request->isMethodSafe()) {
            throw new HttpException(405, 'METODO_NO_PERMITIDO', 'Método no permitido.');
        }

        $ctx = $this->c->get(AuditContext::class);
        $ctx->ip = $request->ip();
        $ctx->userAgent = $request->userAgent();
        $ctx->origin = 'WEB';

        foreach ($route['middleware'] as $spec) {
            if ($spec === 'stateless') {
                continue;
            }
            if ($spec === 'auth' && !$stateless) {
                // En rutas protegidas se autentica ANTES de validar CSRF: con la sesión expirada,
                // el usuario recibe la redirección a /login en lugar de un error de formulario.
                array_unshift($pipeline, $this->resolveMiddleware($spec));
                continue;
            }
            $pipeline[] = $this->resolveMiddleware($spec);
        }

        $core = function (Request $req) use ($route, $params): Response {
            [$class, $method] = $route['handler'];
            $controller = $this->c->get($class);
            if ($controller instanceof Controller) {
                $controller->boot($this->c->get(View::class), $this->c->get(Session::class));
            }
            return $controller->$method($req, ...$params);
        };

        $next = array_reduce(
            array_reverse($pipeline),
            static fn (callable $carry, Middleware $mw): callable => static fn (Request $r): Response => $mw->process($r, $carry),
            $core
        );
        return $next($request);
    }

    private function resolveMiddleware(string $spec): Middleware
    {
        return match (true) {
            $spec === 'auth' => $this->c->get(Authenticate::class),
            $spec === 'guest' => $this->c->get(RedirectIfAuthenticated::class),
            str_starts_with($spec, 'perm:') => new RequirePermission(substr($spec, 5), $this->c->get(Logger::class)),
            default => throw new \RuntimeException('Middleware desconocido: ' . $spec),
        };
    }

    private function renderError(Request $request, int $status, string $code, string $message): Response
    {
        if ($request->wantsJson()) {
            return Response::apiError($code, $message, $status);
        }
        try {
            $view = $this->c->get(View::class);
            return Response::html($view->render('errors/error', [
                'status' => $status, 'code' => $code, 'message' => $message, 'title' => 'Error ' . $status,
            ]), $status);
        } catch (Throwable) {
            return Response::html('<!doctype html><meta charset="utf-8"><title>Error</title><p>' . e($message) . '</p>', $status);
        }
    }

    private function secure(Request $request, Response $response): Response
    {
        if (!$response->hasHeader('Content-Security-Policy')) {
            $response->withHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
        }
        $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->withHeader('Cache-Control', 'no-store, max-age=0')
            ->withHeader('X-Request-Id', Logger::requestId());
        $config = $this->c->get(Config::class);
        if ($config->isProduction() && $request->isSecure()) {
            $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        return $response;
    }
}
