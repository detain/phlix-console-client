<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\MediaItem;
use SugarCraft\Core\Msg;

/**
 * Play this item — the App pushes a {@see \Phlix\Console\Screen\PlayerScreen}.
 *
 * Carries the already-loaded {@see MediaItem} (a leaf, so it holds the signed
 * `stream_url`) so the player can direct-play it without re-fetching the detail.
 */
final readonly class PlayRequestedMsg implements Msg
{
    public function __construct(
        public MediaItem $item,
    ) {
    }
}
