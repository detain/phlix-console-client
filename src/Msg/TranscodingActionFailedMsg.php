<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Carries the error message when a tone-mapping PUT fails.
 */
final readonly class TranscodingActionFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}
