<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Carries the error when the concurrent transcoding GET fails.
 */
final readonly class TranscodingLoadFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}
