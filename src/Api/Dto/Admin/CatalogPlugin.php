<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * One catalog entry, mirroring an item of a `catalogs[].plugins[]` entry from
 * `GET /api/v1/admin/plugins/catalog` (the {@see \Phlix\Server\Http\Controllers\PluginCatalogController}
 * is TOP-LEVEL — admin envelopes are per-controller). The LIVE entry shape is:
 * `{name, title, type, summary, description, repo, author, tags(list), installed(bool),
 * enabled(bool)}`.
 *
 * LANDMINE: the install URL is the **`repo`** field — there is NO `url`/`version`
 * field on a catalog entry. Install-from-catalog reuses
 * {@see \Phlix\Console\Api\Admin\AdminClient::installPlugin()} with this `repo`.
 *
 * Immutable; the tolerant `fromArray` defaults every missing key.
 */
final readonly class CatalogPlugin
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $name,
        public string $title,
        public string $type,
        public string $summary,
        public string $description,
        public string $repo,
        public string $author,
        public array $tags,
        public bool $installed,
        public bool $enabled,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: Coerce::str($data['name'] ?? ''),
            title: Coerce::str($data['title'] ?? ''),
            type: Coerce::str($data['type'] ?? ''),
            summary: Coerce::str($data['summary'] ?? ''),
            description: Coerce::str($data['description'] ?? ''),
            repo: Coerce::str($data['repo'] ?? ''),
            author: Coerce::str($data['author'] ?? ''),
            tags: Coerce::stringList($data['tags'] ?? null),
            installed: Coerce::bool($data['installed'] ?? false),
            enabled: Coerce::bool($data['enabled'] ?? false),
        );
    }

    /** The title to show in a list — the entry's `title`, falling back to `name`. */
    public function displayTitle(): string
    {
        return $this->title !== '' ? $this->title : $this->name;
    }
}
