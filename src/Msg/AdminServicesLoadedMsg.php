<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** The services status arrived — the AdminServicesScreen displays connection state. */
final readonly class AdminServicesLoadedMsg implements Msg
{
    /**
     * @param array{trakt: array{connected:bool, username?:?string, configured:bool}, lastfm: array{connected:bool, username?:?string, api_key_set:bool}} $services
     */
    public function __construct(
        public array $services,
    ) {
    }
}
