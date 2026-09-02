<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto;

/**
 * SyncPlay session returned after creating or joining a group.
 *
 * S414 authority ruling (server `01340633`, live paths only): the create and
 * join rails emit `{success:true, group:{…}}` where `group` is the verbatim
 * `GroupState::getState()` — the group identity on that rail is `group_id`.
 * The server NEVER emits top-level `room_id`, `session_id` or `server_url`
 * (those keys only ever appeared in the dead `WebSocket/SyncPlay/*` classes;
 * `room_id` in particular is emitted nowhere in live server code). An earlier
 * revision of this DTO parsed exactly those three phantom keys, so `roomId`
 * was ALWAYS the empty string and every WS join frame carried `group_id => ''`.
 *
 * `serverUrl` is likewise NOT a wire field — it is derived at the call site
 * from the client's configured API base ({@see \Phlix\Console\Api\ApiClient}),
 * because `SyncPlayService::buildWebSocketUrl()` consumes it VERBATIM (there
 * is no baseUrl fallback inside that method).
 *
 * @readonly
 */
final readonly class SyncPlaySession
{
    public function __construct(
        public string $roomId,
        public string $serverUrl,
    ) {
    }

    /**
     * Parse the `{success:true, group:{…}}` envelope create/join emit.
     *
     * @param array<string, mixed> $data      the decoded response envelope
     * @param string               $serverUrl the client's configured API base (NOT a wire field — see class docblock)
     */
    public static function fromArray(array $data, string $serverUrl = ''): self
    {
        $group = $data['group'] ?? null;

        return new self(
            roomId: is_array($group) ? Coerce::str($group['group_id'] ?? '') : '',
            serverUrl: $serverUrl,
        );
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'room_id' => $this->roomId,
            'server_url' => $this->serverUrl,
        ];
    }
}
