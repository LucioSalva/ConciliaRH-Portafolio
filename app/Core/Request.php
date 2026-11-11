<?php

declare(strict_types=1);

namespace ConciliaRH\Core;

final class Request
{
    /** @var array<string,mixed> */
    private array $attributes = [];

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     * @param array<string,mixed> $server
     * @param array<string,string> $headers  nombres en minúsculas
     * @param array<string,mixed> $files
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $server = [],
        private readonly array $headers = [],
        private readonly array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            } elseif (in_array($k, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[strtolower(str_replace('_', '-', $k))] = (string) $v;
            }
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');

        $body = $_POST;
        if (str_contains($headers['content-type'] ?? '', 'application/json')) {
            $raw = file_get_contents('php://input', false, null, 0, 1_048_576);
            $decoded = json_decode((string) $raw, true);
            $body = is_array($decoded) ? $decoded : [];
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            self::normalizePath($path),
            $_GET,
            $body,
            $_SERVER,
            $headers,
            $_FILES,
        );
    }

    public static function normalizePath(string $path): string
    {
        $path = '/' . trim(rawurldecode($path), '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isMethodSafe(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function file(string $key): mixed
    {
        return $this->files[$key] ?? null;
    }

    public function exceededPostMaxSize(): bool
    {
        $len = (int) ($this->server['CONTENT_LENGTH'] ?? 0);
        $max = self::iniBytes((string) ini_get('post_max_size'));
        return $this->method === 'POST' && $max > 0 && $len > $max && $this->body === [] && $this->files === [];
    }

    public static function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || !preg_match('/^(\d+)\s*([kmg]?)$/i', $v, $m)) {
            return 0;
        }
        return (int) $m[1] * match (strtolower($m[2])) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $v = $this->body[$key] ?? $this->query[$key] ?? $default;
        if (!is_scalar($v)) {
            return $default;
        }
        $v = trim((string) $v);
        return mb_check_encoding($v, 'UTF-8') ? $v : $default;
    }

    /** @return list<string> */
    public function stringList(string $key): array
    {
        $v = $this->body[$key] ?? [];
        if (!is_array($v)) {
            return [];
        }
        return array_values(array_filter(
            array_map('strval', array_filter($v, 'is_scalar')),
            static fn (string $s) => mb_check_encoding($s, 'UTF-8')
        ));
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->body;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function ip(): ?string
    {
        $ip = (string) ($this->server['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    public function userAgent(): string
    {
        $ua = (string) ($this->server['HTTP_USER_AGENT'] ?? '');
        $ua = mb_check_encoding($ua, 'UTF-8') ? $ua : mb_convert_encoding($ua, 'UTF-8', 'UTF-8');
        return mb_substr($ua, 0, 300);
    }

    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? '') === 'on' || (string) ($this->server['SERVER_PORT'] ?? '') === '443';
    }

    public function wantsJson(): bool
    {
        return str_starts_with($this->path, '/api/')
            || str_contains((string) $this->header('accept'), 'application/json');
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
