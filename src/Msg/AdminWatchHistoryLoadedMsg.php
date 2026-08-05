<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Admin\WatchHistoryEntry;
use SugarCraft\Core\Msg;

/**
 * The admin watch-history data arrived — the AdminWatchHistoryScreen renders the list.
 *
 * @param list<WatchHistoryEntry> $entries
 */
final readonly class AdminWatchHistoryLoadedMsg implements Msg
{
    /**
     * @param list<WatchHistoryEntry> $entries
     */
    public function __construct(
        public array $entries,
    ) {
    }
}
