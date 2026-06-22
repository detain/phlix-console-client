<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** The detail screen's hero poster finished rendering to ANSI. */
final readonly class DetailPosterLoadedMsg implements Msg
{
    public function __construct(
        public string $ansi,
    ) {
    }
}
