<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A debounce timer fired for a search edit; the LibraryScreen applies the search
 * only when $seq is still the latest keystroke (older timers are no-ops), so a
 * burst of typing collapses into a single query.
 */
final readonly class SearchDebouncedMsg implements Msg
{
    public function __construct(
        public int $seq,
    ) {
    }
}
