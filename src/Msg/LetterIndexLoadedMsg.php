<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\LetterIndex;
use SugarCraft\Core\Msg;

/** The A–Z jump index for a library resolved; the LibraryScreen shows its rail. */
final readonly class LetterIndexLoadedMsg implements Msg
{
    public function __construct(
        public LetterIndex $index,
        public int $generation,
    ) {
    }
}
