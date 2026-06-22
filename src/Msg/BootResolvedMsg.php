<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\AuthUser;
use SugarCraft\Core\Msg;

/**
 * Boot-time token restore finished: carries the restored user, or null when
 * there was no valid stored session (→ show login).
 */
final readonly class BootResolvedMsg implements Msg
{
    public function __construct(
        public ?AuthUser $user,
    ) {
    }
}
