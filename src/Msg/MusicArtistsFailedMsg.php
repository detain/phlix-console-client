<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** A music artist-list fetch failed (non-auth) — the MusicArtistsScreen shows the reason. */
final readonly class MusicArtistsFailedMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}
