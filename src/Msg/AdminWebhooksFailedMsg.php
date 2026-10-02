<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** The admin webhooks fetch failed (non-auth) — the screen shows the reason + a retry. */
final readonly class AdminWebhooksFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}
