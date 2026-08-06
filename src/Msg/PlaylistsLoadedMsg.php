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
 * Playlists have been loaded from the API.
 */
final readonly class PlaylistsLoadedMsg implements Msg
{
    /**
     * @param list<MediaItem> $items
     */
    public function __construct(
        public array $items,
    ) {
    }
}
