<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Open a plugin's detail + settings editor — emitted by the AdminPluginsScreen
 * (`D` on the selected plugin) and handled at App level, which pushes an
 * AdminPluginDetailScreen for the named plugin (like OpenLibraryMsg).
 */
final readonly class OpenAdminPluginDetailMsg implements Msg
{
    public function __construct(
        public string $name,
    ) {
    }
}
