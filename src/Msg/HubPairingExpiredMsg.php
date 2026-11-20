<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The hub pairing ended without a paired hub: the claim code's TTL elapsed
 * during the poll, or the pairing request itself failed. The wizard exits to
 * the idle state and the reason is toasted.
 *
 * The reason travels on the message because the request and poll closures run
 * against a screen instance the program no longer holds, so they cannot leave
 * it in the wizard for the handler to read back.
 */
final readonly class HubPairingExpiredMsg implements Msg
{
    /** @param string|null $reason The friendly failure text; null falls back to a generic line. */
    public function __construct(
        public ?string $reason = null,
    ) {
    }
}
