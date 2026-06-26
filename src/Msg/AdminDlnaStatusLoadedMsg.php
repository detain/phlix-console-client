<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Admin\DlnaServerStatus;
use SugarCraft\Core\Msg;

/**
 * The DLNA server status was fetched — carries the {@see DlnaServerStatus} the
 * screen renders.
 */
final readonly class AdminDlnaStatusLoadedMsg implements Msg
{
    public function __construct(
        public DlnaServerStatus $status,
    ) {
    }
}
