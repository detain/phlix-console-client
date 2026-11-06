<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

final readonly class InviteLinkCreatedMsg implements Msg
{
    /**
     * @param array<string, mixed> $link the created link row, parsed at the
     *        boundary from the hub's flat 201 body (`{id, url, permission,
     *        max_uses, use_count, expires_at, ...}` — CU-005 retired the
     *        screen-fabricated empty {id,code,url} placeholder this msg used to
     *        carry; the hub wire has no `code` key at all)
     */
    public function __construct(
        public array $link,
    ) {
    }
}
