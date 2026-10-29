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
     * Wire rows pass through HubClient::inviteLinks() unvalidated, so the
     * honest boundary type is a loose row map (consumers guard per-field,
     * e.g. the screen's is_string($link['id']) check).
     *
     * @param list<array<string, mixed>> $links
     */
    public function __construct(
        public array $links,
    ) {
    }
}
