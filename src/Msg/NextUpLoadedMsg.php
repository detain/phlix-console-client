<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\MediaItem;
use SugarCraft\Core\Msg;

/**
 * Next-up items (next unwatched episode per series) have been loaded from the API for the BrowseScreen.
 */
final readonly class NextUpLoadedMsg implements Msg
{
    /**
     * @param list<MediaItem> $items
     */
    public function __construct(
        public array $items,
    ) {
    }
}