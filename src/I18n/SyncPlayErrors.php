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
     *
     * Wave-2 dotted twins: the server flip replaces the coarse SCREAMING
     * carriers below with the dotted codes reserved by the contracts
     * registry (@phlix/contracts src/errors.ts SYNCPLAY_ERROR_CODE_TWINS —
     * syncplay.create_failed / syncplay.join_failed / syncplay.leave_failed
     * twin CREATE_FAILED / JOIN_FAILED / LEAVE_FAILED). A twin is the same
     * failure as its carrier, so both wire codes resolve to the same
     * catalog key: two codes, one key, byte-identical text before and
     * after the flip. The SCREAMING entries stay mapped for older servers
     * that still emit them.
     *
     * Inner-path specializations: the four dotted codes the createGroup /
     * joinGroup handlers return beneath the carriers (srv SyncPlayManager
     * :621 group_limit_reached, :702 group_not_found, :741 invalid_password,
     * :745 group_full — forwarded verbatim by the `?? ` wraps at :1588 /
     * :1627) un-wrap a coarse carrier into a precise reason, so each gets
     * its own catalog key instead of the carrier text. Before this mapping
     * they were unknown codes and rendered the server's English prose via
     * the debug fallback; texts mirror the roku catalogs byte-for-locale.
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
        'syncplay.create_failed' => 'syncplay.create_failed',
        'syncplay.join_failed' => 'syncplay.join_failed',
        'syncplay.leave_failed' => 'syncplay.leave_failed',
        'syncplay.group_limit_reached' => 'syncplay.group_limit_reached',
        'syncplay.group_not_found' => 'syncplay.group_not_found',
        'syncplay.invalid_password' => 'syncplay.invalid_password',
        'syncplay.group_full' => 'syncplay.group_full',
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
