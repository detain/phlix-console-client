<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** The admin metrics data arrived — the AdminMetricsScreen renders its panels. */
final readonly class AdminMetricsLoadedMsg implements Msg
{
    /**
     * @param array<string,mixed>       $snapshot
     * @param list<array<string,mixed>> $history
     * @param list<array<string,mixed>> $connections
     * @param list<array<string,mixed>> $routes
     */
    public function __construct(
        public array $snapshot,
        public array $history,
        public array $connections,
        public array $routes,
    ) {
    }
}
