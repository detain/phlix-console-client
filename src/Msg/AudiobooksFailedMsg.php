<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** An audiobook-list fetch failed (non-auth) — the AudiobooksScreen shows the reason. */
final readonly class AudiobooksFailedMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}
