<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A Live-TV section fetch failed — the AdminLiveTvScreen shows the error in the
 * active section with a retry hint.
 */
final readonly class AdminLiveTvFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}
