<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Store\MusicArtistsRange;
use SugarCraft\Core\Msg;

/**
 * A page of music artists arrived covering the visible window — the
 * MusicArtistsScreen splices them in at their absolute indices and updates
 * total so scrolling knows how many more pages exist.
 */
final readonly class MusicArtistsRangeLoadedMsg implements Msg
{
    public function __construct(
        public MusicArtistsRange $range,
        public int $generation,
    ) {
    }
}
