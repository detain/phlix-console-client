<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Store\MusicRange;
use SugarCraft\Core\Msg;

/**
 * A page of music albums arrived covering the visible window — the MusicScreen
 * splices them in at their absolute indices and updates total so scrolling knows
 * how many more pages exist.
 */
final readonly class MusicRangeLoadedMsg implements Msg
{
    public function __construct(
        public MusicRange $range,
        public int $generation,
    ) {
    }
}
