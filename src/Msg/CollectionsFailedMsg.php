<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Loading collections failed.
 */
final readonly class CollectionsFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}
