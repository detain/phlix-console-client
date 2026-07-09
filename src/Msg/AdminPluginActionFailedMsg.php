<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A plugin action failed — carries the server `error` message (e.g. "not HTTPS"
 * / "signature invalid") to toast; the list is left unchanged.
 */
final readonly class AdminPluginActionFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}
