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
 * A window of a container's children (seasons of a series, episodes of a season)
 * resolved; the DetailScreen splices it into its child grid. Tagged with the
 * `parentId` it was fetched for so a late result can't land on a *different*
 * DetailScreen stacked above (series → season → episode all reuse this screen).
 */
final readonly class ChildrenLoadedMsg implements Msg
{
    public function __construct(
        public string $parentId,
        public MediaRange $range,
    ) {
    }
}
