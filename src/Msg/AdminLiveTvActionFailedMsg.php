<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A Live-TV action failed — the AdminLiveTvScreen toasts the (friendly, per LT1)
 * server message and leaves the active section's list unchanged.
 */
final readonly class AdminLiveTvActionFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}
