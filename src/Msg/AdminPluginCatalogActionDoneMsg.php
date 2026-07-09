<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A catalog action (install / add-source / remove-source) succeeded. Carries a
 * ready-to-toast success message; the AdminPluginCatalogScreen toasts it and
 * refetches the catalog.
 */
final readonly class AdminPluginCatalogActionDoneMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}
