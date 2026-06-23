<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\MediaItem;
use SugarCraft\Core\Msg;

/**
 * The episode's sibling list (the season's episodes, ordered) arrived — the
 * player's up-next queue. `currentIndex` is where the playing episode sits, or
 * -1 if it wasn't found. Best-effort: absent/failed → no queue, no up-next.
 */
final readonly class SiblingsLoadedMsg implements Msg
{
    /**
     * @param list<MediaItem> $siblings
     */
    public function __construct(
        public array $siblings,
        public int $currentIndex,
    ) {
    }
}
