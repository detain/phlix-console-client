<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto\Admin;

/**
 * One editable plugin setting field — one entry of a plugin's `settings_schema`
 * (the authoritative key set) paired with its current value from `settings`.
 *
 * The schema entry carries the field's {@see $type} (one of
 * `bool|int|float|string|json`, defaulting to `string`), its human {@see $label}
 * and {@see $description}, and the {@see $required} / {@see $secret} flags. The
 * loosely-typed current value is rendered to a stable {@see $value} display
 * string by {@see fromParts()} (a bool → `'true'`/`'false'`, a json array →
 * compact `json_encode`, any other scalar → its string cast, null → `''`) so the
 * DTO never carries `mixed` (PHPStan-clean) and the screen can pre-fill an edit
 * field directly. A {@see $secret}'s value arrives already MASKED from the server
 * and is carried through verbatim — the editor never sends it back unchanged.
 *
 * Immutable.
 */
final readonly class PluginSettingField
{
    public function __construct(
        public string $key,
        public string $type,
        public string $label,
        public string $description,
        public bool $required,
        public bool $secret,
        public string $value,
    ) {
    }

    /**
     * Build a field from its schema metadata plus its raw current value,
     * rendering the value to a display string per its kind (NOT per $type — the
     * server may mask a secret to a non-matching string, so the render is purely
     * value-shaped).
     */
    public static function fromParts(
        string $key,
        string $type,
        string $label,
        string $description,
        bool $required,
        bool $secret,
        mixed $value,
    ): self {
        return new self($key, $type, $label, $description, $required, $secret, self::render($value));
    }

    /** Render a loosely-typed current value to a stable display string. */
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

    /** The label to show — the schema label, falling back to the raw key. */
    public function displayLabel(): string
    {
        return $this->label === '' ? $this->key : $this->label;
    }

    /**
     * The canonical editing kind of this field, normalizing the schema's raw
     * {@see $type} string. Third-party manifests use JSON-Schema names
     * (`boolean`/`integer`/`number`/`array`/`object`) as well as the short forms
     * (`bool`/`int`/`float`/`json`); both vocabularies collapse to one of
     * `bool|int|float|json|string` so the DTO and the editor agree on a single
     * alias map (no drift). Anything unrecognized is free-text `string`.
     */
    public function kind(): string
    {
        return match ($this->type) {
            'bool', 'boolean' => 'bool',
            'int', 'integer' => 'int',
            'float', 'number' => 'float',
            'json', 'array', 'object' => 'json',
            default => 'string',
        };
    }

    /** Whether this field is edited via an immediate toggle (a bool field). */
    public function isBool(): bool
    {
        return $this->kind() === 'bool';
    }

    /** The boolean reading of a bool field's current display value. */
    public function boolValue(): bool
    {
        return $this->value === 'true';
    }
}
