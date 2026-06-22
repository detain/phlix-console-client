<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** A poster finished rendering; carries the ANSI to drop into its card. */
final readonly class PosterLoadedMsg implements Msg
{
    public function __construct(
        public string $railId,
        public string $cardId,
        public string $ansi,
    ) {
    }
}
