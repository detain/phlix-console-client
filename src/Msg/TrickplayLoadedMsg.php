<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Trickplay;
use SugarCraft\Core\Msg;

/**
 * The item's trickplay (sprite-preview) data arrived (from
 * `/media/{id}/trickplay`). The {@see \Phlix\Console\Screen\PlayerScreen}
 * uses the sprite + timeline URLs to render thumbnail previews during scrub.
 * Optional — a fetch failure simply leaves the player without scrub previews.
 */
final readonly class TrickplayLoadedMsg implements Msg
{
    public function __construct(
        public Trickplay $trickplay,
    ) {
    }
}
