<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

final readonly class InviteLinkCreatedMsg implements Msg
{
    /**
     * @param array{id:string,code:string,url:string} $link
     */
    public function __construct(
        public array $link,
    ) {
    }
}
