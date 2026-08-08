<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Store;

use Phlix\Console\Api\Dto\MusicArtist;

/**
 * The result of a {@see MusicArtistsStore::ensureRange()} fetch: the artists
 * covering a visible window, keyed by their ABSOLUTE index in the full result
 * set, plus the total artist count. The screen splices `artists` straight into
 * a sparse list at those indices so even a large artist list scrolls smoothly.
 */
final readonly class MusicArtistsRange
{
    /**
     * @param array<int, MusicArtist> $artists absolute index → artist
     */
    public function __construct(
        public array $artists,
        public int $total,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->artists === [];
    }
}
