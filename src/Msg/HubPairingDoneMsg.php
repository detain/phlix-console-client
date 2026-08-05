<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The hub pairing claim code was received from the server — the wizard moves
 * to the "show code + poll" phase carrying the claimCode, claimId, hubUrl, and
 * a countdown of remaining poll attempts.
 */
final readonly class HubPairingDoneMsg implements Msg
{
    /**
     * @param string $claimCode   Human-readable claim code (e.g. "ABCD-1234")
     * @param string $claimId     Opaque claim ID for polling
     * @param string $hubUrl      The hub base URL used for the pairing request
     * @param int    $expiresIn  Seconds until the claim code expires
     * @param int    $pollLeft   Remaining poll attempts (displayed as countdown)
     */
    public function __construct(
        public string $claimCode,
        public string $claimId,
        public string $hubUrl,
        public int $expiresIn,
        public int $pollLeft,
    ) {
    }
}
