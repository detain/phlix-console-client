<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The hub pairing claim code was received from the server — the wizard moves
 * to the "show code + poll" phase carrying the claimCode, claimId and hubUrl.
 * The wizard counts its own polls from expiresIn, so pollLeft is not rendered.
 */
final readonly class HubPairingDoneMsg implements Msg
{
    /**
     * @param string $claimCode   Human-readable claim code (e.g. "ABCD-1234")
     * @param string $claimId     Opaque claim ID for polling
     * @param string $hubUrl      The hub base URL used for the pairing request
     * @param int    $expiresIn  Seconds until the claim code expires
     * @param int    $pollLeft   Poll budget the request was issued with (MAX_POLLS); not rendered
     * @param int    $attempt    The pairing attempt that issued the request; a result
     *                           for an attempt the user has since cancelled is dropped
     */
    public function __construct(
        public string $claimCode,
        public string $claimId,
        public string $hubUrl,
        public int $expiresIn,
        public int $pollLeft,
        public int $attempt,
    ) {
    }
}
