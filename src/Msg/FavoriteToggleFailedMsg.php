<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The favorite toggle API call failed; reverts the optimistic update
 * and signals the App to show a toast.
 */
final readonly class FavoriteToggleFailedMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}
