<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** Loading an item's detail failed; the DetailScreen shows the reason. */
final readonly class DetailFailedMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}
