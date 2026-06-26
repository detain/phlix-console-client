<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A transport action (pause / resume / stop) failed — the
 * {@see \Phlix\Console\Screen\CastScreen} toasts the server error and stays in
 * Transport mode.
 */
final readonly class CastActionFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}
