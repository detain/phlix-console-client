<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** The plugin list fetch failed — the AdminPluginsScreen shows the error + a retry. */
final readonly class AdminPluginsFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}
