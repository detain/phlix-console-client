<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * One plugin's full detail, mirroring `$body['plugin']` of
 * `GET /api/v1/admin/plugins/{name}` (and the refreshed body returned by
 * `PUT .../{name}/settings`). The {@see \Phlix\Server\Http\Controllers\PluginAdminController}
 * is unenveloped (admin envelopes are per-controller), so the client reads
 * `$body['plugin']`, NOT `$body['data']['plugin']`.
 *
 * The shape is `{name, version, type, enabled (bool), installed_at (ATOM),
 * settings_schema: { <key>: {type, required, secret, label, description, default?} },
 * settings: { <key>: <value, secrets MASKED> } }`. The {@see $fields} are built
 * by iterating `settings_schema` (the AUTHORITATIVE key set), pulling each
 * field's metadata from the schema entry and its current value from `settings`.
 *
 * Immutable. Tolerant of any missing key (→ defaults / an empty field list) so a
 * thin or wrongly-wrapped body never breaks the mapping.
 */
final readonly class PluginDetail
{
    /**
     * @param list<PluginSettingField> $fields
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $type,
        public bool $enabled,
        public ?string $installedAt,
        public array $fields,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data the `plugin` payload
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: Coerce::str($data['name'] ?? ''),
            version: Coerce::str($data['version'] ?? ''),
            type: Coerce::str($data['type'] ?? ''),
            enabled: Coerce::bool($data['enabled'] ?? false),
            installedAt: Coerce::nstr($data['installed_at'] ?? null),
            fields: self::fields(
                Coerce::map($data['settings_schema'] ?? null),
                Coerce::map($data['settings'] ?? null),
            ),
        );
    }

    /**
     * Build the editable fields from the `settings_schema` map (the authoritative
     * key set) and the `settings` value map. Each schema entry supplies the
     * type/label/description/flags; the value comes from `settings[key]`. A
     * non-array schema entry is skipped. Keys are kept in schema order.
     *
     * @param array<string,mixed> $schema
     * @param array<string,mixed> $settings
     * @return list<PluginSettingField>
     */
    private static function fields(array $schema, array $settings): array
    {
        $out = [];
        foreach ($schema as $rawKey => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = (string) $rawKey;
            $out[] = PluginSettingField::fromParts(
                $key,
                Coerce::str($entry['type'] ?? null, 'string'),
                Coerce::str($entry['label'] ?? ''),
                Coerce::str($entry['description'] ?? ''),
                Coerce::bool($entry['required'] ?? false),
                Coerce::bool($entry['secret'] ?? false),
                $settings[$key] ?? null,
            );
        }

        return $out;
    }
}
