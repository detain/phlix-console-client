<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** Loading a single audiobook's detail failed. */
final readonly class AudiobookFailedMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}
