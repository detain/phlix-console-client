<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Failed to load the favorites list from the API.
 */
final readonly class FavoritesFailedMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}
