<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Store\MediaRange;
use SugarCraft\Core\Msg;

/**
 * A {@see MediaRange} for a requested visible window resolved; the LibraryScreen
 * splices it into the grid. The $generation it was requested under lets the
 * screen drop a result whose query was superseded mid-flight.
 */
final readonly class MediaRangeLoadedMsg implements Msg
{
    public function __construct(
        public MediaRange $range,
        public int $generation,
    ) {
    }
}
