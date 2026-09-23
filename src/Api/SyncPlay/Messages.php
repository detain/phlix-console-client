<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\SyncPlay;

/**
 * SyncPlay protocol message type constants and validation.
 *
 * Mirrors phlix-server `src/Session/SyncPlay/Messages.php` exactly: every
 * wire type is `syncplay_`-prefixed and the protocol version is the integer
 * 1. The pre-prefix values this fork carried were stale — the server never
 * emits them, so inbound frames were silently dropped and outbound frames
 * failed the server's PROTOCOL_VERSION_MISMATCH gate.
 */
final class Messages
{
    /** Wire protocol version, sent as an integer per SPEC.md §2. */
    public const PROTOCOL_VERSION = 1;

    // Group lifecycle
    public const TYPE_GROUP_CREATE = 'syncplay_group_create';
    public const TYPE_GROUP_JOIN = 'syncplay_group_join';
    public const TYPE_GROUP_LEAVE = 'syncplay_group_leave';
    public const TYPE_GROUP_STATE = 'syncplay_group_state';
    public const TYPE_GROUP_LIST = 'syncplay_group_list';

    // Playback control
    public const TYPE_PLAYBACK_PLAY = 'syncplay_playback_play';
    public const TYPE_PLAYBACK_PAUSE = 'syncplay_playback_pause';
    public const TYPE_PLAYBACK_SEEK = 'syncplay_playback_seek';
    public const TYPE_PLAYBACK_QUEUE = 'syncplay_playback_queue';
    public const TYPE_PLAYBACK_SYNC = 'syncplay_playback_sync';

    // Chat
    public const TYPE_CHAT_MESSAGE = 'syncplay_chat';
    public const TYPE_CHAT_TYPING = 'syncplay_typing';

    // Host management
    public const TYPE_HOST_TRANSFER = 'syncplay_host_transfer';
    public const TYPE_HOST_ELECT = 'syncplay_host_elect';

    // Clock synchronization
    public const TYPE_TIME_PING = 'syncplay_time_ping';
    public const TYPE_TIME_PONG = 'syncplay_time_pong';
    public const TYPE_TIME_SYNC = 'syncplay_time_sync';

    // Diagnostics
    public const TYPE_ERROR = 'syncplay_error';
    public const TYPE_INFO = 'syncplay_info';

    /**
     * Deprecated Tizen-style envelope type still emitted by the server's
     * Connection::sendMessage() error paths (MessageHandler JSON-parse
     * failure and handler-error catch). Carries `{type: 'error', data: {message}, timestamp}`
     * with no error_code. Dispatch-only: deliberately absent from
     * VALID_TYPES so it is never sent or treated as a current wire type.
     */
    public const LEGACY_TYPE_ERROR = 'error';

    /** @var list<string> */
    private const VALID_TYPES = [
        self::TYPE_GROUP_CREATE,
        self::TYPE_GROUP_JOIN,
        self::TYPE_GROUP_LEAVE,
        self::TYPE_GROUP_STATE,
        self::TYPE_GROUP_LIST,
        self::TYPE_PLAYBACK_PLAY,
        self::TYPE_PLAYBACK_PAUSE,
        self::TYPE_PLAYBACK_SEEK,
        self::TYPE_PLAYBACK_QUEUE,
        self::TYPE_PLAYBACK_SYNC,
        self::TYPE_CHAT_MESSAGE,
        self::TYPE_CHAT_TYPING,
        self::TYPE_HOST_TRANSFER,
        self::TYPE_HOST_ELECT,
        self::TYPE_TIME_PING,
        self::TYPE_TIME_PONG,
        self::TYPE_TIME_SYNC,
        self::TYPE_ERROR,
        self::TYPE_INFO,
    ];

    /**
     * Check if a message type is a valid SyncPlay message type.
     */
    public static function isValid(string $type): bool
    {
        return in_array($type, self::VALID_TYPES, true);
    }
}
