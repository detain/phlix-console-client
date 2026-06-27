<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** Fetching the plugin catalog failed — the AdminPluginCatalogScreen shows it. */
final readonly class AdminPluginCatalogFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}
