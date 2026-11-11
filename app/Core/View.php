<?php

declare(strict_types=1);

namespace ConciliaRH\Core;

use RuntimeException;

/**
 * Motor de plantillas PHP sin dependencias.
 * Dentro de una plantilla, $this es un Template con: layout(), start()/stop(), section(),
 * insert(), csrf(), can(), url(), asset(). Los datos se exponen como variables locales.
 * Regla: toda salida dinámica se escapa con e().
 */
final class View
{
    /** @var array<string,mixed> */
    private array $shared = [];

    public function __construct(private readonly string $path, private readonly string $assetVersion = '')
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @return array<string,mixed> */
    public function shared(): array
    {
        return $this->shared;
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $tpl = new Template($this, $template, [...$this->shared, ...$data]);
        return $tpl->render();
    }

    public function file(string $template): string
    {
        if (!preg_match('#^[a-z0-9_/-]+$#', $template) || str_contains($template, '..')) {
            throw new RuntimeException('Nombre de plantilla inválido: ' . $template);
        }
        $file = $this->path . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Plantilla no encontrada: ' . $template);
        }
        return $file;
    }

    public function asset(string $path): string
    {
        $path = '/assets/' . ltrim($path, '/');
        return $this->assetVersion === '' ? $path : $path . '?v=' . rawurlencode($this->assetVersion);
    }
}
