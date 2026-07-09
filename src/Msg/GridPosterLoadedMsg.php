<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** A grid cell's poster finished rendering; attach it to the card at $index. */
final readonly class GridPosterLoadedMsg implements Msg
{
    public function __construct(
        public int $index,
        public string $ansi,
    ) {
    }
}
