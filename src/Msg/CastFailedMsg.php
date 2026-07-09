<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
