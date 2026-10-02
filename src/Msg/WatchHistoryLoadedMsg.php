<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\RecentlyWatchedItem;
use SugarCraft\Core\Msg;

/**
 * Watch history items have been loaded from the API.
 */
final readonly class WatchHistoryLoadedMsg implements Msg
{
    /**
     * @param list<RecentlyWatchedItem> $items
     */
    public function __construct(
        public array $items,
    ) {
    }
}
