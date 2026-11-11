<?php

declare(strict_types=1);

namespace ConciliaRH\Core;

final class Response
{
    private ?string $streamPath = null;
    private bool $deleteAfterSend = false;

    /** @param array<string,string> $headers */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        private array $headers = [],
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** @param array<mixed> $payload */
    public static function json(array $payload, int $status = 200): self
    {
        return new self(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    /** Envoltura estándar de éxito de la API interna. */
    public static function apiOk(mixed $data = null, ?string $message = null, int $status = 200): self
    {
        return self::json(['success' => true, 'data' => $data ?? new \stdClass(), 'message' => $message], $status);
    }

    /** Envoltura estándar de error de la API interna. */
    public static function apiError(string $code, string $message, int $status): self
    {
        return self::json(['success' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
    }

    public static function redirect(string $location, int $status = 303): self
    {
        // Sólo rutas locales: evita redirecciones abiertas.
        if (!str_starts_with($location, '/') || str_starts_with($location, '//') || str_starts_with($location, '/\\')
            || preg_match('/[\x00-\x1f]/', $location) === 1) {
            $location = '/';
        }
        return new self('', $status, ['Location' => $location]);
    }

    /** Cuerpo transmitido desde un archivo (descargas); el Kernel conserva las cabeceras propias. */
    public function withStream(string $path): self
    {
        $this->streamPath = $path;
        return $this;
    }

    /** Archivo temporal: se transmite y se BORRA (reportes generados bajo demanda, nunca persistidos). */
    public function withTempStream(string $path): self
    {
        $this->streamPath = $path;
        $this->deleteAfterSend = true;
        // También si el cliente se desconecta a mitad de la transmisión
        register_shutdown_function(static function () use ($path): void {
            if (is_file($path)) {
                @unlink($path);
            }
        });
        return $this;
    }

    /** @var (callable(callable(string):void):void)|null */
    private $streamWriter = null;

    /**
     * Cuerpo generado por segmentos (p. ej. un reporte que se DESCIFRA al transmitirse: el claro nunca toca el disco).
     * @param callable(callable(string):void):void $writer recibe la función que emite cada segmento
     */
    public function withStreamWriter(callable $writer): self
    {
        $this->streamWriter = $writer;
        return $this;
    }

    public function hasStreamWriter(): bool
    {
        return $this->streamWriter !== null;
    }

    /** Sólo pruebas: ejecuta el escritor y devuelve lo que se habría transmitido. */
    public function renderStream(): string
    {
        $out = '';
        if ($this->streamWriter !== null) {
            ($this->streamWriter)(static function (string $chunk) use (&$out): void {
                $out .= $chunk;
            });
        }
        return $out;
    }

    public function isTempStream(): bool
    {
        return $this->deleteAfterSend;
    }

    public function streamPath(): ?string
    {
        return $this->streamPath;
    }

    public function hasHeader(string $name): bool
    {
        return $this->header($name) !== null;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }
        return null;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }
        if ($this->streamWriter !== null) {
            ($this->streamWriter)(static function (string $chunk): void {
                echo $chunk;
                flush();
            });
            return;
        }
        if ($this->streamPath !== null) {
            $fh = fopen($this->streamPath, 'rb');
            if ($fh !== false) {
                while (!feof($fh)) {
                    echo fread($fh, 65536);
                    flush();
                }
                fclose($fh);
            }
            if ($this->deleteAfterSend) {
                @unlink($this->streamPath);
            }
            return;
        }
        echo $this->body;
    }
}
