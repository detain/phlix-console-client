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
 * Mirrors the TypeScript `messages.ts` implementation.
 */
final class Messages
{
    public const PROTOCOL_VERSION = '1.0.0';

    // Message types
    public const TYPE_GROUP_JOIN = 'group_join';
    public const TYPE_GROUP_LEAVE = 'group_leave';
    public const TYPE_GROUP_STATE = 'group_state';
    public const TYPE_TIME_PING = 'time_ping';
    public const TYPE_TIME_PONG = 'time_pong';
    public const TYPE_TIME_SYNC = 'time_sync';
    public const TYPE_PLAYBACK_PLAY = 'playback_play';
    public const TYPE_PLAYBACK_PAUSE = 'playback_pause';
    public const TYPE_PLAYBACK_SEEK = 'playback_seek';
    public const TYPE_ERROR = 'error';
    public const TYPE_INFO = 'info';
    public const TYPE_HOST_ELECT = 'host_elect';

    /** @var list<string> */
    private const VALID_TYPES = [
        self::TYPE_GROUP_JOIN,
        self::TYPE_GROUP_LEAVE,
        self::TYPE_GROUP_STATE,
        self::TYPE_TIME_PING,
        self::TYPE_TIME_PONG,
        self::TYPE_TIME_SYNC,
        self::TYPE_PLAYBACK_PLAY,
        self::TYPE_PLAYBACK_PAUSE,
        self::TYPE_PLAYBACK_SEEK,
        self::TYPE_ERROR,
        self::TYPE_INFO,
        self::TYPE_HOST_ELECT,
    ];

    /**
     * Check if a message type is a valid SyncPlay message type.
     */
    public static function isValid(string $type): bool
    {
        return in_array($type, self::VALID_TYPES, true);
    }
}
