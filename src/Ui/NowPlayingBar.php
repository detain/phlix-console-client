<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Ui;

use Phlix\Console\Audio\NowPlayingSession;
use SugarCraft\Core\Util\Width;

/**
 * The persistent now-playing bar: a single, ANSI-safe line summarising the App's
 * active {@see NowPlayingSession} — a music track OR an audiobook chapter —
 * composited onto the bottom row of every screen so playback stays visible across
 * navigation.
 *
 * Layout (exactly {@see $width} cells):
 *
 *   ▶ Title — Album · Artist                         1:23 / 4:19
 *   └─ left (truncated to fit) ─┘ └─ pad ─┘ └─ right clock ─┘
 *
 * The ▶/⏸ glyph + title may be tinted with the theme's brand accent (Nocturne is
 * plain — a no-op). The clock (`position / duration`) is precomputed by the
 * session (`m:ss` / `h:mm:ss`, with `—` for an unknown duration). All widths are
 * measured with {@see Width} (ANSI-stripped) so the line is exactly $width display
 * cells regardless of any embedded SGR.
 */
final class NowPlayingBar
{
    /** Cells between the left text and the right-aligned clock (minimum gap). */
    private const GAP = 2;

    public static function render(NowPlayingSession $np, int $width, ?Theme $theme = null): string
    {
        $width = max(1, $width);

        $glyph = $np->paused() ? '⏸' : '▶';
        $clock = $np->positionLabel() . ' / ' . $np->durationLabel();
        $clockWidth = Width::string($clock);

        // Reserve the clock + a gap on the right; the left text gets the rest.
        $leftBudget = $width - $clockWidth - self::GAP;

        // Tint only the glyph + title (the brand accent); the subtitle stays plain.
        $accent = ($theme ?? Theme::nocturne())->brandStyle();
        $head = $accent->render($glyph . ' ' . $np->title());
        $tail = $np->subtitle() !== '' ? ' — ' . $np->subtitle() : '';

        if ($leftBudget <= 0) {
            // No room for the clock alongside the text — fill the whole line with
            // the (truncated) left text so the bar is still exactly $width cells.
            return Width::padRight(Width::truncateAnsi($head . $tail, $width), $width);
        }

        $left = Width::truncateAnsi($head . $tail, $leftBudget);
        // Pad the left text out so the clock sits flush against the right edge.
        $padded = Width::padRight($left, $width - $clockWidth);

        return $padded . $clock;
    }
}
