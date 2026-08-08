<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The like toggle API call failed; reverts the optimistic update
 * and signals the App to show a toast.
 */
final readonly class LikeToggleFailedMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}
