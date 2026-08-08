<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Next-up items failed to load from the API for the BrowseScreen.
 */
final readonly class NextUpFailedMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}
