<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The hub pairing poll succeeded — the hub consumed the claim and the server
 * stored the enrollment. The screen refetches all statuses to show the newly-paired
 * state.
 */
final readonly class HubPairingPollSucceededMsg implements Msg
{
    public function __construct(
        public string $serverId,
        public string $hubUrl,
    ) {
    }
}
