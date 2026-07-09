<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
