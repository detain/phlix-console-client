<?php

declare(strict_types=1);

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
