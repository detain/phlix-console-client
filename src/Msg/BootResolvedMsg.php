<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Config\Config;
use SugarCraft\Core\Msg;

/**
 * Boot-time token restore finished: carries the restored user, or null when
 * there was no valid stored session (→ show login). When $user is non-null,
 * $mergedConfig holds the merged local+server settings (server values override
 * local defaults for server-persisted keys, while device-local keys are
 * preserved from the local config). When $user is null, $mergedConfig is null.
 *
 * @param ShowToastMsg|null $toast Optional toast to surface to the user alongside
 *     the boot resolution (e.g., when server settings could not be merged).
 */
final readonly class BootResolvedMsg implements Msg
{
    /**
     * @param AuthUser|null $user
     * @param Config|null $mergedConfig
     * @param ShowToastMsg|null $toast
     */
    public function __construct(
        public ?AuthUser $user,
        public ?Config $mergedConfig = null,
        public ?ShowToastMsg $toast = null,
    ) {
    }
}
