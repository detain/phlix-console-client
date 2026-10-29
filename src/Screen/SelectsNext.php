<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Screen;

/**
 * The upper-bound-clamped "move selection down" over a private array $items list — the
 * 5 item-list screens carried it byte-identically (M-CONSOLE-37).
 *
 * Was 5 raw-identical private copies (Collections/Favorites/Playlists/Recommendations/
 * WatchHistory). A screen `use`s this trait when it keeps private int $selectedIndex,
 * private list $items and a private withSelectedIndex(int): self clone-mutate; the body
 * is verbatim. Selection wrap-around fixes land once; drift is pinned by
 * {@see \Phlix\Console\Tests\Unit\Screen\ScreenConcernSingleSourceTest}.
 */
trait SelectsNext
{
    /** @return array{self, ?\Closure} */
    private function selectNext(): array
    {
        if ($this->selectedIndex < count($this->items) - 1) {
            return [$this->withSelectedIndex($this->selectedIndex + 1), null];
        }

        return [$this, null];
    }
}
