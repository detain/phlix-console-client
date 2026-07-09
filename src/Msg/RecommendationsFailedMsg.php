<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
