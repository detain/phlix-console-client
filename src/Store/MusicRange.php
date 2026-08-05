<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Store;

use Phlix\Console\Api\Dto\Album;

/**
 * The result of a {@see MusicStore::ensureRange()} fetch: the albums covering a
 * visible window, keyed by their ABSOLUTE index in the full result set, plus the
 * total album count. The screen splices `albums` straight into a sparse list at
 * those indices so even a 5,000-album library scrolls smoothly.
 */
final readonly class MusicRange
{
    /**
     * @param array<int, Album> $albums absolute index → album
     */
    public function __construct(
        public array $albums,
        public int $total,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->albums === [];
    }
}
