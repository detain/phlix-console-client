<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Admin\PluginCatalogResult;
use SugarCraft\Core\Msg;

/** The plugin catalog arrived — the AdminPluginCatalogScreen builds its table. */
final readonly class AdminPluginCatalogLoadedMsg implements Msg
{
    public function __construct(
        public PluginCatalogResult $result,
    ) {
    }
}
