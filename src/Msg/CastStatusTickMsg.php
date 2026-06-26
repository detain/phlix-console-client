<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A status-poll tick for the {@see \Phlix\Console\Screen\CastScreen}. Carries the
 * poll `$epoch` it was armed under; a tick whose epoch no longer matches the
 * screen's current poll generation (the screen left Transport / switched device)
 * is dropped, so a stale poll chain dies.
 */
final readonly class CastStatusTickMsg implements Msg
{
    public function __construct(
        public int $epoch,
    ) {
    }
}
