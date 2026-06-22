<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A child grid cell's poster finished rendering. Tagged with the owning
 * `parentId` (see {@see ChildrenLoadedMsg}) so a late poster can't be applied
 * to a different DetailScreen stacked above.
 */
final readonly class ChildPosterLoadedMsg implements Msg
{
    public function __construct(
        public string $parentId,
        public int $index,
        public string $ansi,
    ) {
    }
}
