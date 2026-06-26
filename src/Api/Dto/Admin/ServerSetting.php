<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto\Admin;

/**
 * One server setting (one key of `GET /api/v1/admin/settings` →
 * `{success, data:{settings, overridden, types}}`). The
 * {@see \Phlix\Server\Http\Controllers\Admin\AdminSettingsController} IS enveloped
 * (admin envelopes are per-controller), so the maps live under `data`.
 *
 * The effective $value (a config default merged with any DB override) arrives
 * loosely typed; {@see fromParts()} renders it to a stable {@see $displayValue}
 * string so the DTO never carries `mixed` (PHPStan-clean) and the screen can
 * pre-fill an edit field directly. The authoritative {@see $type} (one of
 * `bool|int|float|string|json`) drives both the render and the edit coercion;
 * {@see $overridden} flags keys with a DB override.
 *
 * Immutable.
 */
final readonly class ServerSetting
{
    public function __construct(
        public string $key,
        public string $displayValue,
        public string $type,
        public bool $overridden,
    ) {
    }

    /**
     * Build a setting from its raw effective value, rendering $value to a
     * display string per $type: a bool → `'true'`/`'false'`, a json array →
     * compact `json_encode`, any other scalar → its string cast, null → `''`.
     */
    public static function fromParts(string $key, mixed $value, string $type, bool $overridden): self
    {
        return new self($key, self::render($value), $type, $overridden);
    }

    /** Render a loosely-typed effective value to a stable display string. */
    private static function render(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return (string) json_encode($value);
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    /** Whether this setting is edited via an immediate toggle (a bool key). */
    public function isBool(): bool
    {
        return $this->type === 'bool';
    }

    /** The boolean reading of a bool key's current display value. */
    public function boolValue(): bool
    {
        return $this->displayValue === 'true';
    }
}
