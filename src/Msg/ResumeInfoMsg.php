<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The item's saved resume position (from continue-watching), or null when there
 * is nothing to resume. The {@see \Phlix\Console\Screen\PlayerScreen} seeks the
 * playing video to it once known and offers "start over". Best-effort — a fetch
 * failure simply yields null (play from the start).
 */
final readonly class ResumeInfoMsg implements Msg
{
    public function __construct(
        public ?float $seconds,
    ) {
    }
}
