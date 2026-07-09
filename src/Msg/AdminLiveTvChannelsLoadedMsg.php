<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Admin\Channel;
use SugarCraft\Core\Msg;

/**
 * The Live-TV channel list arrived — the AdminLiveTvScreen caches it into the
 * Channels section and renders the windowed table.
 */
final readonly class AdminLiveTvChannelsLoadedMsg implements Msg
{
    /** @param list<Channel> $channels */
    public function __construct(
        public array $channels,
    ) {
    }
}
