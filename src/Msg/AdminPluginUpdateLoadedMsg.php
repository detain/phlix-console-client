<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The plugin update settings and available updates arrived — the
 * AdminPluginUpdateScreen builds its display.
 */
final readonly class AdminPluginUpdateLoadedMsg implements Msg
{
    /**
     * @param list<array{name:string,current_version:string,latest_version:string}> $updates
     */
    public function __construct(
        public string $channel,
        public bool $autoUpdate,
        public array $updates,
    ) {
    }
}
