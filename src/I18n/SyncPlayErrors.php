<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\I18n;

/**
 * Localizes SyncPlay wire error codes into user-facing messages.
 *
 * Doctrine: error-code-first. The stable `error_code` on a server
 * `syncplay_error` frame selects the catalog string; the server's English
 * `message` is only a debug fallback for codes we do not recognize, and a
 * generic localized line covers frames with neither.
 */
final class SyncPlayErrors
{
    /**
     * Server error codes (phlix-server SyncPlayManager / MessageHandler
     * sendError literals) mapped to catalog keys.
     */
    private const CODE_KEYS = [
        'NOT_AUTHENTICATED' => 'syncplay.not_authenticated',
        'NOT_IN_GROUP' => 'syncplay.not_in_group',
        'NOT_HOST' => 'syncplay.not_host',
        'UNKNOWN_MESSAGE' => 'syncplay.unknown_message',
        'HANDLER_ERROR' => 'syncplay.handler_error',
        'PROTOCOL_VERSION_MISMATCH' => 'syncplay.protocol_version_mismatch',
        'INVALID_NEW_HOST' => 'syncplay.invalid_new_host',
        'MEMBER_NOT_FOUND' => 'syncplay.member_not_found',
        'SAME_HOST' => 'syncplay.same_host',
        'CREATE_FAILED' => 'syncplay.create_failed',
        'JOIN_FAILED' => 'syncplay.join_failed',
        'LEAVE_FAILED' => 'syncplay.leave_failed',
    ];

    /**
     * Resolve user-facing text for a wire error.
     *
     * Fallback chain: known code → localized catalog string;
     * unknown code with server text → that text (debug fallback);
     * otherwise → generic localized line.
     */
    public static function localize(string $code, string $message): string
    {
        $key = self::CODE_KEYS[$code] ?? null;

        if ($key !== null) {
            return Lang::t($key);
        }

        return $message !== '' ? $message : Lang::t('syncplay.unknown_error');
    }
}
