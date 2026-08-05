<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** User rating API call failed; reverts the optimistic update and shows a toast. */
final readonly class RatingSetFailedMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}
