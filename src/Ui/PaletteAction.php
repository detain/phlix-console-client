<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Ui;

use SugarCraft\Core\Msg;

/**
 * One entry in the command palette: a fuzzy-searchable label and the {@see Msg}
 * the App dispatches when it is chosen.
 */
final readonly class PaletteAction
{
    public function __construct(
        public string $label,
        public Msg $msg,
    ) {
    }
}
