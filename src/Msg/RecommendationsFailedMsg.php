<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Indicates recommendations failed to load.
 */
final readonly class RecommendationsFailedMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}
