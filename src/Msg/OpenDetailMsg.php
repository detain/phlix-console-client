<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Open an item's detail screen — the App pushes a DetailScreen onto the stack.
 * Carries the id (the screen fetches the full detail) plus the already-known
 * name so the header reads correctly during the brief load.
 */
final readonly class OpenDetailMsg implements Msg
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
