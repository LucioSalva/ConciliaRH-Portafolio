<?php

declare(strict_types=1);

namespace ConciliaRH\Core;

final class Logger
{
    public const CHANNELS = ['app', 'security', 'import', 'motor'];

    private const SENSITIVE_KEY = '/pass|token|secret|clave_api|authorization|cookie|clabe|cuenta|curp|rfc/i';

    private static ?string $requestId = null;

    public function __construct(private readonly string $dir)
    {
    }

    public static function requestId(): string
    {
        return self::$requestId ??= bin2hex(random_bytes(8));
    }

    public static function resetRequestId(): void
    {
        self::$requestId = null;
    }

    /** @param array<string,mixed> $context */
    public function log(string $channel, string $level, string $message, array $context = []): void
    {
        if (!in_array($channel, self::CHANNELS, true)) {
            $channel = 'app';
        }
        $line = json_encode([
            'ts' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED),
            'level' => $level,
            'channel' => $channel,
            'request_id' => self::requestId(),
            'message' => self::maskText($message),
            'context' => self::redact($context),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        $file = $this->dir . '/' . $channel . self::suffix() . '.log';
        $nuevo = !is_file($file);
        if (!@file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX)) {
            error_log('[conciliarh] ' . $line);
        } elseif ($nuevo) {
            @chmod($file, 0640);
        }
    }

    private static function suffix(): string
    {
        if (PHP_SAPI !== 'cli') {
            return '';
        }
        $user = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '';
        return '-cli' . (preg_match('/^[a-z0-9_.-]{1,32}$/', (string) $user) ? '-' . $user : '');
    }

    public function info(string $message, array $context = [], string $channel = 'app'): void
    {
        $this->log($channel, 'INFO', $message, $context);
    }

    public function warning(string $message, array $context = [], string $channel = 'app'): void
    {
        $this->log($channel, 'WARNING', $message, $context);
    }

    public function error(string $message, array $context = [], string $channel = 'app'): void
    {
        $this->log($channel, 'ERROR', $message, $context);
    }

    public function security(string $message, array $context = []): void
    {
        $this->log('security', 'WARNING', $message, $context);
    }

    /** @param array<mixed> $data @return array<mixed> */
    public static function isDigest(mixed $key, mixed $value): bool
    {
        return is_string($key) && is_string($value) && preg_match('/^(sha256|huella|huella_contenido|hash|checksum)$/', $key) === 1
            && preg_match('/^([0-9a-f]{32}|[0-9a-f]{64})$/', $value) === 1;
    }

    public static function redact(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && preg_match(self::SENSITIVE_KEY, $k)) {
                $out[$k] = '[REDACTADO]';
            } elseif (self::isDigest($k, $v)) {
                $out[$k] = $v;
            } elseif (is_array($v)) {
                $out[$k] = self::redact($v);
            } elseif (is_string($v)) {
                $out[$k] = self::maskText($v);
            } elseif ($v instanceof \Throwable) {
                $out[$k] = ['class' => $v::class, 'message' => self::maskText($v->getMessage()), 'file' => $v->getFile() . ':' . $v->getLine()];
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    public static function maskText(string $text): string
    {
        $text = (string) preg_replace_callback('/(?<!\d)\d{3,}(?:[ -]\d{3,})*(?!\d)/', static function (array $m): string {
            $digits = (string) preg_replace('/\D/', '', $m[0]);
            $n = strlen($digits);
            return ($n >= 10 && $n <= 18) ? str_repeat('*', $n - 4) . substr($digits, -4) : $m[0];
        }, $text);
        $text = (string) preg_replace('/(?<![A-Z0-9])[A-Z]{4}\d{6}[HM][A-Z]{5}[0-9A-Z]\d(?![A-Z0-9])/iu', '[CURP]', $text);
        return (string) preg_replace('/(?<![A-Z0-9])[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}(?![A-Z0-9])/iu', '[RFC]', $text);
    }
}
