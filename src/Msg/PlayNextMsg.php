<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\MediaItem;
use SugarCraft\Core\Msg;

/**
 * Advance to another episode — the App **replaces** the current player frame
 * with a fresh {@see \Phlix\Console\Screen\PlayerScreen} for $item (so the stack
 * doesn't grow as you binge). Sent by the player on up-next auto-advance or the
 * `n`/`p` keys.
 */
final readonly class PlayNextMsg implements Msg
{
    public function __construct(
        public MediaItem $item,
    ) {
    }
}
