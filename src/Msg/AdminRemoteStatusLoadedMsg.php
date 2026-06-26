<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Admin\RemoteAccessStatus;
use SugarCraft\Core\Msg;

/**
 * The four remote-access statuses (hub / subdomain / relay / port forward) were
 * fetched and assembled — carries the aggregate for the screen to render.
 */
final readonly class AdminRemoteStatusLoadedMsg implements Msg
{
    public function __construct(
        public RemoteAccessStatus $status,
    ) {
    }
}
