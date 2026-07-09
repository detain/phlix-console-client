<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Audiobook;
use SugarCraft\Core\Msg;

/** The audiobook library's list arrived — the AudiobooksScreen fills its table. */
final readonly class AudiobooksLoadedMsg implements Msg
{
    /**
     * @param list<Audiobook> $audiobooks
     */
    public function __construct(
        public array $audiobooks,
    ) {
    }
}
