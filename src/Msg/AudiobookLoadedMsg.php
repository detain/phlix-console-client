<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Audiobook;
use SugarCraft\Core\Msg;

/** A single audiobook's detail (the shape that adds the signed stream URL) resolved. */
final readonly class AudiobookLoadedMsg implements Msg
{
    public function __construct(
        public Audiobook $audiobook,
    ) {
    }
}
