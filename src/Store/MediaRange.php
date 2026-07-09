<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Store;

use Phlix\Console\Api\Dto\MediaItem;

/**
 * The result of a {@see MediaStore::ensureRange()} fetch: the items covering a
 * visible window, keyed by their ABSOLUTE index in the full result set, plus the
 * total item count. The screen splices `items` straight into a sparse grid at
 * those indices (an A–Z jump to offset 2600 lands those items at 2600, not
 * appended at the end).
 */
final readonly class MediaRange
{
    /**
     * @param array<int, MediaItem> $items absolute index → item
     */
    public function __construct(
        public array $items,
        public int $total,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
