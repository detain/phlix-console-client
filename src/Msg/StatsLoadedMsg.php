<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Server stats have been loaded.
 */
final readonly class StatsLoadedMsg implements Msg
{
    /** @param array<string, mixed> $stats */
    public function __construct(
        public array $stats,
    ) {
    }
}