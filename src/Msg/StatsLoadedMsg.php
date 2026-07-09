<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Library;
use SugarCraft\Core\Msg;

/** The library list arrived — the StatsScreen aggregates it into per-type stats. */
final readonly class StatsLoadedMsg implements Msg
{
    /**
     * @param list<Library> $libraries
     */
    public function __construct(
        public array $libraries,
    ) {
    }
}
