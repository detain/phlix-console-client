<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\SyncPlay;

/**
 * One delivered hub-relay `pending_command` / `play_media` frame (S93's shape,
 * emitted by the hub's `PendingCommandDispatcher`).
 *
 * Parsed at the boundary by {@see HubRelayConsumer::parsePendingCommandFrame()}:
 * every field here is already validated, so consumers can trust it without
 * re-checking. `issuedAt` is Unix seconds.
 */
final readonly class PendingPlayMediaCommand
{
    public function __construct(
        /** Hub server id the media id belongs to (the socket is bound to it). */
        public string $serverId,
        /** Media item id to start playing. */
        public string $mediaId,
        /** Human-readable title ("Alexa, play X" → X). */
        public string $title,
        /** Unix seconds — when the hub dispatched the frame. */
        public int $issuedAt,
        /** Frame origin, e.g. `alexa`. */
        public string $source,
    ) {
    }
}
