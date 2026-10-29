<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Screen;

/**
 * The lower-bound-clamped "move selection up" 9 list screens carried byte-identically
 * (M-CONSOLE-37).
 *
 * Was 9 raw-identical private copies across src/Screen/. A screen `use`s this trait
 * when it keeps a private int $selectedIndex field and exposes a private
 * withSelectedIndex(int): self clone-mutate — the pair (selectedIndex, withSelectedIndex)
 * is the screen's own; the body here is verbatim. Split from {@see SelectsNext} on
 * purpose: the four admin list screens step down over differently-named collections, so
 * a single both-directions trait would hand them a shadowed selectNext. Selection
 * wrap-around fixes land once; drift is pinned by
 * {@see \Phlix\Console\Tests\Unit\Screen\ScreenConcernSingleSourceTest}.
 */
trait SelectsPrevious
{
    /** @return array{self, ?\Closure} */
    private function selectPrev(): array
    {
        if ($this->selectedIndex > 0) {
            return [$this->withSelectedIndex($this->selectedIndex - 1), null];
        }

        return [$this, null];
    }
}
