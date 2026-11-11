<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Imports;

final class UploadedFile
{
    public function __construct(
        public readonly string $tmpPath,
        public readonly string $clientName,
        public readonly string $clientMime,
        public readonly int $error,
    ) {
    }

    /** @param mixed $entry entrada cruda de $_FILES[campo] */
    public static function fromArray(mixed $entry): ?self
    {
        if (!is_array($entry) || !isset($entry['tmp_name'], $entry['name'], $entry['error'])) {
            return null;
        }
        if (is_array($entry['tmp_name']) || is_array($entry['name']) || is_array($entry['error'])) {
            return null;
        }
        return new self(
            (string) $entry['tmp_name'],
            (string) $entry['name'],
            is_string($entry['type'] ?? null) ? mb_substr((string) $entry['type'], 0, 120) : '',
            (int) $entry['error'],
        );
    }

    /**
     * @param mixed $entry entrada cruda de $_FILES[campo] con name[]
     * @return list<self>
     */
    public static function fromMultiple(mixed $entry, int $max = 10): array
    {
        if (!is_array($entry) || !isset($entry['tmp_name'], $entry['name'], $entry['error'])) {
            return [];
        }
        if (!is_array($entry['tmp_name'])) {
            $one = self::fromArray($entry);
            return $one !== null && $one->error !== UPLOAD_ERR_NO_FILE ? [$one] : [];
        }
        $out = [];
        foreach (array_keys($entry['tmp_name']) as $k) {
            if (!is_int($k) || count($out) >= $max || !is_string($entry['tmp_name'][$k] ?? null) || !is_string($entry['name'][$k] ?? null)) {
                continue;
            }
            $error = (int) ($entry['error'][$k] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $type = $entry['type'][$k] ?? '';
            $out[] = new self($entry['tmp_name'][$k], $entry['name'][$k], is_string($type) ? mb_substr($type, 0, 120) : '', $error);
        }
        return $out;
    }
}
