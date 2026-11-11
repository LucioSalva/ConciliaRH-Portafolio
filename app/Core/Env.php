<?php

declare(strict_types=1);

namespace ConciliaRH\Core;

/**
 * Lector de variables de entorno.
 * Prioridad: variables reales del proceso > archivo .env.
 * El parser es deliberadamente simple: KEY=VALUE, comentarios con #,
 * comillas simples o dobles opcionales. No interpola ni ejecuta nada.
 */
final class Env
{
    /** @param array<string,string> $fileVars */
    private function __construct(private readonly array $fileVars)
    {
    }

    public static function load(?string $file): self
    {
        $vars = [];
        if ($file !== null && is_file($file) && is_readable($file)) {
            $vars = self::parse((string) file_get_contents($file));
        }
        return new self($vars);
    }

    /** @param array<string,string> $vars */
    public static function fromArray(array $vars): self
    {
        return new self($vars);
    }

    /** @return array<string,string> */
    public static function parse(string $content): array
    {
        $vars = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/^([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
                continue;
            }
            $value = $m[2];
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            } else {
                // Comentario al final sólo si va precedido de espacio
                $value = rtrim((string) preg_replace('/\s+#.*$/', '', $value));
            }
            $vars[$m[1]] = $value;
        }
        return $vars;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $real = getenv($key);
        if ($real !== false) {
            return $real;
        }
        return $this->fileVars[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $v = $this->get($key);
        return ($v === null || $v === '') ? $default : $v;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->get($key);
        if ($v === null || $v === '') {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->get($key);
        return ($v === null || !preg_match('/^-?\d+$/', $v)) ? $default : (int) $v;
    }
}
