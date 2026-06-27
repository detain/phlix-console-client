<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A catalog action (install / add-source / remove-source) failed. Carries the
 * server `error`; the AdminPluginCatalogScreen toasts it and leaves the catalog
 * unchanged.
 */
final readonly class AdminPluginCatalogActionFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}
