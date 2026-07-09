<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A user action failed — carries the server `error` message (e.g. "Cannot
 * disable the last admin") to toast; the list is left unchanged.
 */
final readonly class AdminUserActionFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}
