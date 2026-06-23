<?php

declare(strict_types=1);

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
