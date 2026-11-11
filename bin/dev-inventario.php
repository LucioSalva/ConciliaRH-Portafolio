<?php

declare(strict_types=1);

use ConciliaRH\Engine\Tools\Inventory;

require dirname(__DIR__) . '/vendor/autoload.php';

$args = array_slice($argv, 1);
$source = null;
$output = null;
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--salida') {
        $output = $args[++$i] ?? null;
    } elseif (str_starts_with($args[$i], '--salida=')) {
        $output = substr($args[$i], 9);
    } elseif ($source === null) {
        $source = $args[$i];
    }
}
if ($source === null) {
    fwrite(STDERR, "Uso: php bin/dev-inventario.php RUTA_EXTERNA [--salida ARCHIVO.json|.md]\n");
    exit(2);
}
try {
    fwrite(STDOUT, Inventory::main($source, $output, dirname(__DIR__)));
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
