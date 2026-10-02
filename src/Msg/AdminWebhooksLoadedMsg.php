<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The admin webhooks data arrived — the AdminWebhooksScreen renders the list.
 *
 * @param list<array<string,mixed>> $webhooks
 */
final readonly class AdminWebhooksLoadedMsg implements Msg
{
    /**
     * @param list<array<string,mixed>> $webhooks
     */
    public function __construct(
        public array $webhooks,
    ) {
    }
}
