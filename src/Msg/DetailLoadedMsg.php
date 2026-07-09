<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\MediaItem;
use SugarCraft\Core\Msg;

/** The full detail for an item resolved; the DetailScreen renders it. */
final readonly class DetailLoadedMsg implements Msg
{
    public function __construct(
        public MediaItem $item,
    ) {
    }
}
