<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Ui;

use Phlix\Console\Screen\Themed;
use SugarCraft\Core\Util\Width;
use SugarCraft\Panels\SugarPanels;
use SugarCraft\NavStack\Breadcrumb;

/**
 * The full-window app shell: a header, a bordered content region, and a status
 * line, composed with sugar-panels. Screens render their body into the middle.
 *
 * When a breadcrumb $trail is supplied (the App threads it through the screen
 * stack), the header shows it (Home › Movies › The Matrix) instead of the bare
 * title, truncating from the left to fit the width so the deepest crumbs stay
 * visible.
 *
 * An optional {@see Theme} tints two tokens: the " Phlix " brand (its accent) and
 * the status line (its muted colour). Under the default {@see Theme::nocturne()}
 * both styles are plain (zero SGR), so the rendered frame is byte-identical to
 * the pre-theme output — the breadcrumb / title portion is never tinted. The
 * theme is applied per render (the App hands the top {@see Themed} screen the
 * active theme just before it renders); sugar-panels's ANSI-aware placement keeps
 * the injected SGR aligned.
 *
 * The borderless status leaf CLIPS (it does not wrap), so a hint longer than
 * the leaf would vanish silently from the right — exactly where every hint in
 * the app parks its safety key (`Esc  back`, `q  quit`). {@see fitHint()}
 * adapts the hint to the width before it is rendered, keeping whole words at
 * both ends and marking the elision with ` … ` (M-CONSOLE-45).
 */
final class Chrome
{
    /** The brand token (space-padded) the theme's accent colours. */
    private const BRAND = ' Phlix ';

    /** What sits between the brand and the title/breadcrumb (kept un-tinted). */
    private const BRAND_SEP = ' ·  ';

    /**
     * @param list<string> $trail breadcrumb labels, root-first; empty = bare title
     */
    public static function frame(string $title, string $body, string $hint, int $cols, int $rows, array $trail = [], ?Theme $theme = null): string
    {
        $theme ??= Theme::nocturne();
        $b = SugarPanels::new();

        // header = 1 fixed line, content = FILLS (flex/grow), status = 1 fixed line.
        // The content panel keeps its border (so the body width stays cols-4); the
        // header/status are borderless single lines, and a 1-row gap separates the
        // sections (sugar-panels draws an inter-panel divider on a borderless leaf's
        // only row at spacing 0, so use spacing 1 — the blank line reads as
        // breathing room). The content grows to fill everything left over.
        //
        // The accent colours ONLY the brand token; the separator + title/breadcrumb
        // stay plain. Under Nocturne brandStyle()/statusStyle() add no SGR, so this
        // concatenation reproduces the old ' Phlix  ·  ' . label header byte-for-byte.
        //
        // Sink law (M-CONSOLE-33 census): BRAND/BRAND_SEP are app constants and
        // $title/$trail are app literals or server text already sanitized at
        // DTO parse (Coerce::str law) — Chrome renders the app's OWN intentional
        // escapes and is deliberately not a re-escaping boundary.
        $headerText = $theme->brandStyle()->render(self::BRAND)
            . self::BRAND_SEP
            . self::headerLabel($title, $trail, $cols);
        $header = $b->leaf($headerText)->withBorder(false)->withMinHeight(1);
        $content = $b->leaf($body)->withBorder(true)->withGrow();
        $status = $b->leaf(
            $theme->statusStyle()->render(' ' . self::fitHint($hint, $cols))
        )->withBorder(false)->withMinHeight(1);

        $root = $b->vertical($header, $content, $status)->withSpacing(1);

        return $b->render($root, max(1, $cols), max(1, $rows));
    }

    /**
     * Chrome height overhead around the content body: root border (2) + two
     * spacing gaps (2) + header line (1) + status line (1) + content-panel
     * border (2) = 8. Kept in sync with {@see frame()}'s layout.
     */
    private const CHROME_HEIGHT = 8;

    /**
     * How many body lines the content panel shows at a given terminal height.
     *
     * The content panel now FILLS the frame (sugar-panels flex/grow), so the
     * body is a deterministic `rows - CHROME_HEIGHT` — no probing. This equals EXACTLY
     * the number of body lines {@see frame()} displays (0 when the terminal is too
     * short to fit the chrome at all). A screen that windows a scrolling body (a
     * grid, a table viewport) sizes it to THIS value (less any in-content header
     * lines of its own) so its rows fill the panel and the selected row is never
     * clipped; callers floor their own viewport so a 0 here is safe.
     */
    public static function bodyHeight(int $rows): int
    {
        return max(0, $rows - self::CHROME_HEIGHT);
    }

    /**
     * Cells a hint loses to the frame's outer border (1 per side) — measured at
     * HEAD (M-CONSOLE-45): the borderless status leaf shows exactly `cols - 2`
     * hint cells; the 60-col probe rendered 58 and clipped the rest silently.
     */
    private const HINT_INSET = 2;

    /** Padded marker left where middle words were elided — the omission signal. */
    private const HINT_GAP = ' … ';

    /**
     * Fit a status hint to the terminal width by dropping WHOLE whitespace-run
     * delimited words from the middle (M-CONSOLE-45).
     *
     * Every hint in the app parks its safety-relevant key at the END
     * (`Esc  back`, `q  quit`, `Esc cancel`, `Esc done`), and the borderless
     * status leaf right-clips — so the old behavior deleted exactly the tokens
     * a stuck user needs, with no signal. The fit keeps a run of whole words
     * from the front and a run of whole words from the back (the tail capped at
     * half the usable budget, so both ends keep a share) joined by
     * {@see HINT_GAP}. A hint that already fits is returned BYTE-IDENTICAL, so
     * wide-terminal rendering — and the Docs pins that read the shipped hint
     * constants for their key tables — is untouched. Widths too degenerate for
     * two word-runs plus the marker fall back to the canonical display-width
     * {@see Width::truncateMiddle()} (grapheme + East-Asian aware; never this
     * repo's forbidden codepoint counting). A hint with no whitespace to elide
     * at all takes the same fallback: word granularity is undefined for it.
     */
    public static function fitHint(string $hint, int $cols): string
    {
        $budget = $cols - self::HINT_INSET;
        if ($hint === '' || Width::string($hint) <= $budget) {
            return $hint;
        }

        $gapWidth = Width::string(self::HINT_GAP);
        if ($budget <= $gapWidth) {
            return Width::truncateMiddle($hint, $budget);
        }

        /** @var list<string> $parts words at even indices, whitespace runs at odd */
        $parts = preg_split('/(\s+)/', $hint, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (count($parts) < 3) {
            return Width::truncateMiddle($hint, $budget);
        }

        $usable = $budget - $gapWidth;
        $tailBudget = intdiv($usable, 2);

        // Tail: whole words from the end, so the safety key always survives.
        $tail = $parts[count($parts) - 1];
        $tailWidth = Width::string($tail);
        if ($tailWidth > $usable) {
            return Width::truncateMiddle($hint, $budget);
        }
        for ($k = count($parts) - 3; $k >= 0; $k -= 2) {
            $width = $tailWidth + Width::string($parts[$k]) + Width::string($parts[$k + 1]);
            if ($width > $tailBudget) {
                break;
            }
            $tail = $parts[$k] . $parts[$k + 1] . $tail;
            $tailWidth = $width;
        }

        // Head: whole words from the front with whatever budget the tail left.
        $headBudget = $usable - $tailWidth;
        $head = '';
        $headWidth = 0;
        for ($k = 0; $k <= count($parts) - 2; $k += 2) {
            $width = $headWidth + Width::string($parts[$k]) + ($head === '' ? 0 : Width::string($parts[$k - 1]));
            if ($width > $headBudget) {
                break;
            }
            $head .= ($head === '' ? '' : $parts[$k - 1]) . $parts[$k];
            $headWidth = $width;
        }

        return $head . self::HINT_GAP . $tail;
    }

    /**
     * @param list<string> $trail
     */
    private static function headerLabel(string $title, array $trail, int $cols): string
    {
        // Reserve the " Phlix  ·  " prefix (~11 cols) and a small margin.
        $maxWidth = max(10, $cols - 14);

        if ($trail === []) {
            // A bare title is server/user text on several screens (a library
            // name, a media title) — it gets the SAME budget law the crumb
            // path already pays, or it pushes the header line past the frame.
            return Truncate::withEllipsis($title, $maxWidth);
        }

        return (new Breadcrumb())->setMaxWidth($maxWidth)->renderTitles($trail);
    }
}
