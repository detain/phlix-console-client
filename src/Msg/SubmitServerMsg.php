<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** The first-run server wizard was submitted with a server URL. */
final readonly class SubmitServerMsg implements Msg
{
    public function __construct(
        public string $url,
    ) {
    }
}
