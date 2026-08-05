<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
