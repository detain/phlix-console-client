<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * The whole catalog response, mirroring `GET /api/v1/admin/plugins/catalog` →
 * TOP-LEVEL `{default_source(string), sources(list<string>),
 * catalogs:[{source, name, plugins:[entry]}], errors(list)}` (the
 * {@see \Phlix\Server\Http\Controllers\PluginCatalogController} is unenveloped —
 * admin envelopes are per-controller).
 *
 * `errors` are per-source fetch failures (non-fatal); the screen surfaces them in
 * its header. Immutable; the tolerant `fromArray` builds the nested lists.
 */
final readonly class PluginCatalogResult
{
    /**
     * @param list<string>        $sources
     * @param list<PluginCatalog> $catalogs
     * @param list<string>        $errors
     */
    public function __construct(
        public string $defaultSource,
        public array $sources,
        public array $catalogs,
        public array $errors,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rows = $data['catalogs'] ?? null;
        $catalogs = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $catalogs[] = PluginCatalog::fromArray($row);
                }
            }
        }

        return new self(
            defaultSource: Coerce::str($data['default_source'] ?? ''),
            sources: Coerce::stringList($data['sources'] ?? null),
            catalogs: $catalogs,
            errors: Coerce::stringList($data['errors'] ?? null),
        );
    }

    /**
     * Every catalog's plugins flattened in order — convenient for a single flat
     * list screen.
     *
     * @return list<CatalogPlugin>
     */
    public function flatPlugins(): array
    {
        $out = [];
        foreach ($this->catalogs as $catalog) {
            foreach ($catalog->plugins as $plugin) {
                $out[] = $plugin;
            }
        }

        return $out;
    }
}
