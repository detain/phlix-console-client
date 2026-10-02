<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The auth providers data arrived — the AdminAuthProvidersScreen renders the list.
 *
 * @param list<array{name:string,enabled:bool,configured:bool}> $providers
 */
final readonly class AdminAuthProvidersLoadedMsg implements Msg
{
    /**
     * @param list<array{name:string,enabled:bool,configured:bool}> $providers
     */
    public function __construct(
        public array $providers,
    ) {
    }
}
