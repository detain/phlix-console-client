<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A live scan-status poll tick for the {@see \Phlix\Console\Screen\AdminLibrariesScreen}.
 * Carries the poll `$epoch` it was armed under; a tick whose epoch no longer
 * matches the screen's current poll generation (the selection moved, or the
 * screen is leaving) is dropped, so a stale poll chain dies.
 */
final readonly class AdminScanStatusTickMsg implements Msg
{
    public function __construct(
        public int $epoch,
    ) {
    }
}
