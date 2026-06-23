<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** Open a single audiobook's detail — the App pushes an AudiobookDetailScreen onto the stack. */
final readonly class OpenAudiobookMsg implements Msg
{
    public function __construct(
        public string $id,
        public string $title,
    ) {
    }
}
