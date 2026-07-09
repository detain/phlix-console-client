<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Admin\Tuner;
use SugarCraft\Core\Msg;

/**
 * The Live-TV tuner list arrived — the AdminLiveTvScreen caches it into the
 * Tuners section and renders the windowed table. Untagged: a single screen
 * instance routes it to the active section.
 */
final readonly class AdminLiveTvTunersLoadedMsg implements Msg
{
    /** @param list<Tuner> $tuners */
    public function __construct(
        public array $tuners,
    ) {
    }
}
