<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Carries the confirmation message after a successful tone-mapping PUT.
 */
final readonly class TranscodingActionDoneMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}
