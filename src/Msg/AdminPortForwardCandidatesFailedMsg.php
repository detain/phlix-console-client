<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
