<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * One installed plugin, mirroring an item of `GET /api/v1/admin/plugins` →
 * TOP-LEVEL `{plugins: [...]}` (the {@see \Phlix\Server\Http\Controllers\PluginAdminController}
 * is unenveloped — admin envelopes are per-controller). Each row is the
 * `serializeInstalled` shape:
 * `id, name, version, type, entry, enabled (bool), installed_at (ATOM),
 * signed (bool), settings`.
 *
 * The install endpoint (`POST .../install` → `{plugin: ManifestJson}`) and the
 * enable/disable endpoints (`{plugin: {name, enabled}}`) reuse this DTO; those
 * partial shapes (no `installed_at`, no `version`, etc.) are tolerated by the
 * defensive `fromArray` so the screen never has to special-case them.
 *
 * Immutable. The settings map is intentionally NOT carried here — the
 * settings-schema editor is a deferred surface.
 */
final readonly class Plugin
{
    public function __construct(
        public string $name,
        public string $version,
        public string $type,
        public bool $enabled,
        public ?string $installedAt,
        public bool $signed,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: Coerce::str($data['name'] ?? ''),
            version: Coerce::str($data['version'] ?? ''),
            type: Coerce::str($data['type'] ?? ''),
            enabled: Coerce::bool($data['enabled'] ?? false),
            installedAt: Coerce::nstr($data['installed_at'] ?? null),
            signed: Coerce::bool($data['signed'] ?? false),
        );
    }
}
