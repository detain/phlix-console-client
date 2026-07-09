<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
