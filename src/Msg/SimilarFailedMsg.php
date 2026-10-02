<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Indicates similar items failed to load.
 */
final readonly class SimilarFailedMsg implements Msg
{
    public function __construct(
        public string $mediaId,
        public string $reason,
    ) {
    }
}
