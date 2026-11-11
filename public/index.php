<?php

declare(strict_types=1);

/**
 * ConciliaRH — Front Controller. Único punto de entrada PHP expuesto por el servidor web.
 */

use ConciliaRH\Core\App;
use ConciliaRH\Core\Config;
use ConciliaRH\Core\Kernel;
use ConciliaRH\Core\Request;

$basePath = dirname(__DIR__);

if (!is_file($basePath . '/vendor/autoload.php')) {
    http_response_code(503);
    exit('Servicio no disponible.');
}
require $basePath . '/vendor/autoload.php';

try {
    $request = Request::fromGlobals();
    $container = App::container($basePath, null, $request);
    $container->get(Config::class)->assertSecure();
} catch (Throwable $e) {
    error_log('[conciliarh] Error de arranque: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    exit('<!doctype html><meta charset="utf-8"><title>Servicio no disponible</title><p>El sistema no está disponible por un problema de configuración. Contacte al administrador.</p>');
}

(new Kernel($container))->handle($request)->send();
