<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The federation outgoing shares list arrived.
 *
 * @param list<array<string, mixed>> $shares
 */
final readonly class FederationSharesOutgoingLoadedMsg implements Msg
{
    /**
     * @param list<array<string, mixed>> $shares
     */
    public function __construct(
        public array $shares,
    ) {
    }
}
