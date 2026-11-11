<?php

declare(strict_types=1);

/**
 * Arranque común para comandos CLI. Nunca debe ser accesible por web (bin/ está fuera de public/).
 */

use ConciliaRH\Core\App;
use ConciliaRH\Core\Container;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

return static fn (): Container => App::container($basePath);
