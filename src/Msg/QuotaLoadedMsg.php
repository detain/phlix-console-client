<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Admin\UserQuota;
use SugarCraft\Core\Msg;

/**
 * User quota data was loaded from the server.
 */
final readonly class QuotaLoadedMsg implements Msg
{
    public function __construct(
        public string $userId,
        public UserQuota $quota,
    ) {
    }
}
