<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Screen;

/**
 * The clone-mutate window-size copy every screen had byte-identically (M-CONSOLE-37).
 *
 * Was 37 raw-identical private copies across src/Screen/. A screen `use`s this trait
 * when it holds its frame size in private int $cols/$rows fields (constructor-promoted
 * or plain) and returns a resized immutable copy from its ScreenResizedMsg arm, exactly
 * as each copy did before — the body is verbatim, only its location changed. Single
 * source for the future "resize bounds" fix; drift is pinned by
 * {@see \Phlix\Console\Tests\Unit\Screen\ScreenConcernSingleSourceTest}.
 */
trait ResizesTo
{
    private function resizedTo(int $cols, int $rows): self
    {
        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;

        return $next;
    }
}
