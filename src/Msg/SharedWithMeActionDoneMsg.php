<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * An accept/reject share action succeeded. Carries the server `message` to toast.
 * The screen refetches the list after applying this.
 */
final readonly class SharedWithMeActionDoneMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}
