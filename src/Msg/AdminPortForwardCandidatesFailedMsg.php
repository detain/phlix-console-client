<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The port-forward candidates fetch failed — carries the friendly error to show
 * on the candidates sub-view's error line.
 */
final readonly class AdminPortForwardCandidatesFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}
