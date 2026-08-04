<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * SyncPlay WebSocket disconnected.
 */
final readonly class SyncPlayDisconnectedMsg implements Msg
{
    public function __construct(
        public bool $wasIntentional,
    ) {
    }
}
