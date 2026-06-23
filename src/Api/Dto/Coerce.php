<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * Defensive coercion helpers for mapping loosely-typed server JSON into
 * strict readonly DTOs.
 *
 * The phlix-server shapes numbers as ints or numeric strings depending on the
 * code path, omits keys, and (for raw metadata_json) can carry actors as either
 * plain strings or `{name: ...}` objects. These helpers normalise all of that
 * so a DTO constructor always receives the declared type.
 *
 * @internal
 */
final class Coerce
{
    /** Coerce a scalar to a string, falling back to $default for non-scalars. */
    public static function str(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    /** Coerce to a non-empty string, or null when absent/empty/non-scalar. */
    public static function nstr(mixed $value): ?string
    {
        if ($value === null || $value === '' || !is_scalar($value)) {
            return null;
        }

        $string = (string) $value;

        return $string === '' ? null : $string;
    }

    /** Coerce a numeric value to int, or null when absent/non-numeric. */
    public static function nint(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /** Coerce to int with a default (server sends 0|1, "0"|"1", true|false). */
    public static function int(mixed $value, int $default = 0): int
    {
        return self::nint($value) ?? $default;
    }

    /** Coerce a numeric value to float, or null when absent/non-numeric. */
    public static function nfloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /** Coerce a numeric value to float with a default (e.g. marker seconds). */
    public static function float(mixed $value, float $default = 0.0): float
    {
        return self::nfloat($value) ?? $default;
    }

    /** Coerce assorted truthy encodings (bool, 0|1, "0"|"1", "true") to bool. */
    public static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        return $value === 'true' || $value === 'yes';
    }

    /**
     * Coerce a value to a list of non-empty strings (e.g. genres, paths).
     *
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $string = self::nstr($item);
            if ($string !== null) {
                $out[] = $string;
            }
        }

        return $out;
    }

    /**
     * Normalise an actors value that may be a list of plain names or of
     * `{name: ...}` objects (the TMDB-shaped metadata) into flat names.
     *
     * @return list<string>
     */
    public static function actorNames(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $name = self::nstr($item['name'] ?? null);
                if ($name !== null) {
                    $out[] = $name;
                }
                continue;
            }
            $name = self::nstr($item);
            if ($name !== null) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * Coerce to an associative array, or [] when absent/non-array.
     *
     * @return array<string,mixed>
     */
    public static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
