<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\ContinueWatchingItem;
use SugarCraft\Core\Msg;

/** The Continue Watching list finished loading. */
final readonly class ContinueWatchingLoadedMsg implements Msg
{
    /** @param list<ContinueWatchingItem> $items */
    public function __construct(
        public array $items,
    ) {
    }
}
