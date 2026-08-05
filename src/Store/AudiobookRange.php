<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Store;

/**
 * The result of a {@see AudiobooksStore::ensureRange()} fetch: the audiobooks
 * covering a visible window, keyed by their ABSOLUTE index in the full result
 * set. The screen splices `audiobooks` straight into a sparse list at those
 * indices so even a large library scrolls smoothly.
 */
final readonly class AudiobookRange
{
    /**
     * @param array<int, \Phlix\Console\Api\Dto\Audiobook> $audiobooks absolute index → audiobook
     */
    public function __construct(
        public array $audiobooks,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->audiobooks === [];
    }
}
