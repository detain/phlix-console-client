<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** User rating was successfully set or cleared; clears the pending revert state. */
final readonly class RatingSetMsg implements Msg
{
    public function __construct(
        public bool $success,
    ) {
    }
}
