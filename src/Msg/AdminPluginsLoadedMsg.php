<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Admin\Plugin;
use SugarCraft\Core\Msg;

/** The installed-plugin list arrived — the AdminPluginsScreen builds its table. */
final readonly class AdminPluginsLoadedMsg implements Msg
{
    /** @param list<Plugin> $plugins */
    public function __construct(
        public array $plugins,
    ) {
    }
}
