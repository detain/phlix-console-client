<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Ui;

use Phlix\Console\Audio\NowPlayingSession;
use SugarCraft\Core\Util\Width;

/**
 * The persistent now-playing bar: a single, ANSI-safe line summarising the App's
 * active {@see NowPlayingSession} — a music track OR an audiobook chapter —
 * composited onto the bottom row of every screen so playback stays visible across
 * navigation.
 *
 * Layout (at most {@see $width} cells, exactly it when nothing truncates):
 *
 *   ▶ Title — Album · Artist                         1:23 / 4:19
 *   └─ left (truncated to fit) ─┘ └─ pad ─┘ └─ right clock ─┘
 *
 * The ▶/⏸ glyph + title may be tinted with the theme's brand accent (Nocturne is
 * plain — a no-op). The clock (`position / duration`) is precomputed by the
 * session (`m:ss` / `h:mm:ss`, with `—` for an unknown duration). All widths are
 * measured with {@see Width} (ANSI-stripped) so the line fits $width cells
 * regardless of any embedded SGR — and because the bar rides a full-width row,
 * the ▶/⏸ glyphs the vendored scorer under-counts are reserved through
 * {@see DisplayCells} (CU-054) so an emoji-aware terminal never charges $width+1.
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

        // CU-054: the media glyphs are scored 1 cell by the vendored Width, but
        // an emoji-aware terminal charges 2. The bar rides a full-width row, so
        // reserve every under-counted cell up front and lay the composition out
        // in what remains — the result is always DisplayCells::string() <= the
        // requested width (exactly it when nothing truncates), so DECAWM can
        // never wrap the bar and scroll the frame under the pixel payloads.
        $reserve = DisplayCells::extra($glyph . ' ' . $np->title() . $np->subtitle());
        $eff = max(1, $width - $reserve);

        $clockWidth = Width::string($clock);

        // Reserve the clock + a gap on the right; the left text gets the rest.
        $leftBudget = $eff - $clockWidth - self::GAP;

        // Tint only the glyph + title (the brand accent); the subtitle stays plain.
        $accent = ($theme ?? Theme::nocturne())->brandStyle();
        $head = $accent->render($glyph . ' ' . $np->title());
        $tail = $np->subtitle() !== '' ? ' — ' . $np->subtitle() : '';

        if ($leftBudget <= 0) {
            // No room for the clock alongside the text — fill the line with the
            // (truncated) left text so the bar still occupies at most the
            // reserved width once an emoji-aware terminal charges the glyphs.
            return Width::padRight(Width::truncateAnsi($head . $tail, $eff), $eff);
        }

        $left = Width::truncateAnsi($head . $tail, $leftBudget);
        // Pad the left text out so the clock sits flush against the right edge.
        $padded = Width::padRight($left, $eff - $clockWidth);

        return $padded . $clock;
    }
}
