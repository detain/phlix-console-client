<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Failed to load the most-watched list from the API.
 */
final readonly class MostWatchedFailedMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}
