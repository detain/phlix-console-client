<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The hub pairing poll returned "Claim has expired." — the claim code's TTL
 * elapsed before the operator completed the hub web flow. The wizard exits
 * to the idle state.
 */
final readonly class HubPairingExpiredMsg implements Msg
{
}
