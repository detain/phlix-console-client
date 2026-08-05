<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
