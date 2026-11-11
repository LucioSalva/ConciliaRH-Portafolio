<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Support;

use stdClass;

final class Json
{
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS
        | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public static function canonical(mixed $value): string
    {
        return json_encode(self::sorted($value), self::FLAGS);
    }

    public static function encode(mixed $value): string
    {
        return json_encode(self::plain($value), self::FLAGS);
    }

    public static function obj(array $pairs = []): stdClass
    {
        $o = new stdClass();
        foreach ($pairs as $k => $v) {
            $o->{(string) $k} = $v;
        }
        return $o;
    }

    /** @return array<mixed>|null */
    public static function decode(?string $json): mixed
    {
        if ($json === null || $json === '') {
            return null;
        }
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function sorted(mixed $v): mixed
    {
        if ($v instanceof stdClass) {
            $v = (array) $v;
            $out = [];
            foreach ($v as $k => $x) {
                $out[(string) $k] = self::sorted($x);
            }
            uksort($out, static fn ($a, $b): int => strcmp((string) $a, (string) $b));
            return self::asObject($out);
        }
        if (is_array($v)) {
            if (array_is_list($v)) {
                return array_map(self::sorted(...), $v);
            }
            $out = [];
            foreach ($v as $k => $x) {
                $out[(string) $k] = self::sorted($x);
            }
            uksort($out, static fn ($a, $b): int => strcmp((string) $a, (string) $b));
            return self::asObject($out);
        }
        return $v;
    }

    private static function plain(mixed $v): mixed
    {
        if ($v instanceof stdClass) {
            $out = new stdClass();
            foreach ((array) $v as $k => $x) {
                $out->{(string) $k} = self::plain($x);
            }
            return $out;
        }
        if (is_array($v)) {
            if (array_is_list($v)) {
                return array_map(self::plain(...), $v);
            }
            $out = new stdClass();
            foreach ($v as $k => $x) {
                $out->{(string) $k} = self::plain($x);
            }
            return $out;
        }
        return $v;
    }

    /** @param array<string,mixed> $a */
    private static function asObject(array $a): stdClass
    {
        $o = new stdClass();
        foreach ($a as $k => $x) {
            $o->{$k} = $x;
        }
        return $o;
    }
}
