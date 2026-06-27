<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Open the plugin catalog browser — emitted by the AdminPluginsScreen (`C`) and
 * handled at App level, which pushes an AdminPluginCatalogScreen (like
 * OpenAdminPluginDetailMsg).
 */
final readonly class OpenAdminPluginCatalogMsg implements Msg
{
}
