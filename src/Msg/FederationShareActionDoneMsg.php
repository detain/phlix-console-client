<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * An accept/reject/remove federation share action succeeded. Carries the
 * server `message` to toast. The screen refetches the list after this.
 */
final readonly class FederationShareActionDoneMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}
