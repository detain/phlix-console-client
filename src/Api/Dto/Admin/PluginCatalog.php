<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * One catalog source's contents, mirroring a `catalogs[]` entry from
 * `GET /api/v1/admin/plugins/catalog`: `{source(string), name(string),
 * plugins:[entry]}`. Each entry is mapped to a {@see CatalogPlugin}.
 *
 * Immutable; the tolerant `fromArray` defaults a missing/non-array `plugins`
 * payload to an empty list and skips non-array rows.
 */
final readonly class PluginCatalog
{
    /**
     * @param list<CatalogPlugin> $plugins
     */
    public function __construct(
        public string $source,
        public string $name,
        public array $plugins,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rows = $data['plugins'] ?? null;
        $plugins = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $plugins[] = CatalogPlugin::fromArray($row);
                }
            }
        }

        return new self(
            source: Coerce::str($data['source'] ?? ''),
            name: Coerce::str($data['name'] ?? ''),
            plugins: $plugins,
        );
    }
}
