<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Screen;

/**
 * Contract for screens whose rendered frame is line-addressable.
 *
 * A frame is line-addressable when every "\n" in it opens the NEXT terminal
 * row, so newline-indexed compositors (line splices, sugar-overlay canvases,
 * the toast layer) address real screen rows. The pixel-graphics player frame
 * breaks that promise by design: its first line carries a multi-row sixel /
 * kitty / iTerm2 blob that must never be byte-split (Width::truncateAnsi /
 * dropAnsi only pass through CSI+OSC — a DCS/APC payload would be cut like
 * plain text), and rows AFTER the blob are positionally ambiguous because the
 * image advances the cursor by its own grid height. Everything the graphics
 * player writes below the blob therefore rides absolute cursor moves — see
 * {@see PlayerScreen::graphicsChrome()}.
 *
 * App::view() consults this on the top screen: a screen returning false gets
 * its overlays grafted with absolute moves ({@see \Phlix\Console\Ui\Graft})
 * instead of line-spliced, and the vendor toast compositor (which splits
 * lines and cannot be told otherwise) is skipped while the frame is on top.
 */
interface LineAligned
{
    /**
     * Whether the CURRENT frame is safe for newline-indexed compositing.
     * False means the frame's first line is an opaque pixel-graphics blob.
     */
    public function isLineAligned(): bool;
}
