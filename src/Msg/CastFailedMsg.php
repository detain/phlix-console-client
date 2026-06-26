<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The cast send failed — the {@see \Phlix\Console\Screen\CastScreen} toasts the
 * server error and stays in the picker.
 */
final readonly class CastFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}
