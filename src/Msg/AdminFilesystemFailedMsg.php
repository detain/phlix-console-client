<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Emitted when the filesystem browse fails.
 */
final readonly class AdminFilesystemFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}
