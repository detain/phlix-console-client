<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A playback session was opened (POST /sessions). The
 * {@see \Phlix\Console\Screen\PlayerScreen} stores the id and begins reporting
 * progress against it. Only delivered on success — a failure to create a session
 * is swallowed (progress reporting is best-effort and never interrupts playback).
 */
final readonly class SessionStartedMsg implements Msg
{
    public function __construct(
        public string $sessionId,
    ) {
    }
}
