<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * The full server-settings set, mirroring the `data` payload of
 * `GET /api/v1/admin/settings` → `{success, data:{settings, overridden, types}}`
 * (the {@see \Phlix\Server\Http\Controllers\Admin\AdminSettingsController} IS
 * enveloped — admin envelopes are per-controller, so the maps live under
 * `data`; the client reads `$body['data']`).
 *
 * The `types` map is the AUTHORITATIVE key set (every editable key, with its
 * internal type); each value is pulled from `settings` (the effective value) and
 * the key is marked {@see ServerSetting::$overridden} when it appears in the
 * `overridden` list. Keys are sorted for a stable display. Tolerant of any
 * missing map (→ an empty set), so a top-level `{settings}` with no `data`
 * wrapper yields nothing.
 *
 * Immutable.
 */
final readonly class ServerSettings
{
    /**
     * @param list<ServerSetting> $settings
     */
    public function __construct(
        public array $settings,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data the `data` payload (settings/types/overridden maps)
     */
    public static function fromArray(array $data): self
    {
        $settings = Coerce::map($data['settings'] ?? null);
        $types = Coerce::map($data['types'] ?? null);
        $overridden = Coerce::stringList($data['overridden'] ?? null);

        $keys = array_keys($types);
        sort($keys);

        $out = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            $out[] = ServerSetting::fromParts(
                $key,
                $settings[$key] ?? null,
                Coerce::str($types[$key] ?? ''),
                in_array($key, $overridden, true),
            );
        }

        return new self($out);
    }
}
