<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

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
