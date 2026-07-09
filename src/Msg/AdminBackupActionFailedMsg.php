<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A backup action failed — carries the server `error` message to toast; the
 * list is left unchanged.
 */
final readonly class AdminBackupActionFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}
