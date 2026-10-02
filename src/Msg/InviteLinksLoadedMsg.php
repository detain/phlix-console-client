<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** The invite links list arrived. */
final readonly class InviteLinksLoadedMsg implements Msg
{
    /**
     * @param list<array{id:string,code:string,label:string,uses:int,max_uses:int|null,expires_at:string|null}> $links
     */
    public function __construct(
        public array $links,
    ) {
    }
}
