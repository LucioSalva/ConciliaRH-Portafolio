<?php

declare(strict_types=1);

namespace ConciliaRH\Core;

use RuntimeException;
use Throwable;

/** Contexto de ejecución de una plantilla (ver View). */
final class Template
{
    private ?string $layout = null;
    /** @var array<string,mixed> */
    private array $layoutData = [];
    /** @var array<string,string> */
    private array $sections = [];
    private ?string $capturing = null;

    /** @param array<string,mixed> $data */
    public function __construct(
        private readonly View $view,
        private readonly string $name,
        private array $data,
    ) {
    }

    public function render(): string
    {
        $content = $this->capture($this->view->file($this->name), $this->data);
        if ($this->layout === null) {
            return $content;
        }
        $layout = new Template($this->view, $this->layout, [...$this->data, ...$this->layoutData, 'content' => $content]);
        $layout->sections = $this->sections;
        return $layout->render();
    }

    /** @param array<string,mixed> $data */
    private function capture(string $__file, array $__data): string
    {
        extract($__data, EXTR_SKIP);
        $level = ob_get_level();
        ob_start();
        try {
            include $__file;
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
        return (string) ob_get_clean();
    }

    /** @param array<string,mixed> $data */
    public function layout(string $name, array $data = []): void
    {
        $this->layout = $name;
        $this->layoutData = $data;
    }

    public function start(string $section): void
    {
        if ($this->capturing !== null) {
            throw new RuntimeException('Sección anidada no soportada');
        }
        $this->capturing = $section;
        ob_start();
    }

    public function stop(): void
    {
        if ($this->capturing === null) {
            throw new RuntimeException('stop() sin start()');
        }
        $this->sections[$this->capturing] = (string) ob_get_clean();
        $this->capturing = null;
    }

    /** Devuelve HTML ya generado por plantillas (no escapar de nuevo). */
    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /** @param array<string,mixed> $data */
    public function insert(string $partial, array $data = []): string
    {
        return $this->view->render($partial, [...$this->data, ...$data]);
    }

    public function asset(string $path): string
    {
        return $this->view->asset($path);
    }

    /** Campo oculto con el token CSRF. */
    public function csrf(): string
    {
        return '<input type="hidden" name="_token" value="' . e($this->data['csrfToken'] ?? '') . '">';
    }

    public function can(string $permission): bool
    {
        $user = $this->data['currentUser'] ?? null;
        return $user !== null && $user->can($permission);
    }

    public function active(string $prefix): string
    {
        $path = (string) ($this->data['currentPath'] ?? '');
        $isActive = $prefix === '/' ? $path === '/' : ($path === $prefix || str_starts_with($path, $prefix . '/'));
        return $isActive ? 'active' : '';
    }

    public function old(string $key, string $default = ''): string
    {
        $old = $this->data['old'] ?? [];
        $v = is_array($old) ? ($old[$key] ?? $default) : $default;
        return is_scalar($v) ? (string) $v : $default;
    }
}
