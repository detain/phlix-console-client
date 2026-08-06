<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The federation incoming shares list arrived.
 *
 * @param list<array<string, mixed>> $shares
 */
final readonly class FederationSharesIncomingLoadedMsg implements Msg
{
    /**
     * @param list<array<string, mixed>> $shares
     */
    public function __construct(
        public array $shares,
    ) {
    }
}
