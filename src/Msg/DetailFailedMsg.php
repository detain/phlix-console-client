<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** Loading an item's detail failed; the DetailScreen shows the reason. */
final readonly class DetailFailedMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}
