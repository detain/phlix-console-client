<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Indicates a recommendation dismiss action failed.
 */
final readonly class RecommendationDismissFailedMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}
