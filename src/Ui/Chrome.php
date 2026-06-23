<?php

declare(strict_types=1);

namespace Phlix\Console\Ui;

use SugarCraft\Boxer\SugarBoxer;
use SugarCraft\Crumbs\Breadcrumb;

/**
 * The full-window app shell: a header, a bordered content region, and a status
 * line, composed with sugar-boxer. Screens render their body into the middle.
 *
 * When a breadcrumb $trail is supplied (the App threads it through the screen
 * stack), the header shows it (Home › Movies › The Matrix) instead of the bare
 * title, truncating from the left to fit the width so the deepest crumbs stay
 * visible.
 */
final class Chrome
{
    /**
     * @param list<string> $trail breadcrumb labels, root-first; empty = bare title
     */
    public static function frame(string $title, string $body, string $hint, int $cols, int $rows, array $trail = []): string
    {
        $b = SugarBoxer::new();

        // header = 1 fixed line, content = FILLS (flex/grow), status = 1 fixed line.
        // The content panel keeps its border (so the body width stays cols-4); the
        // header/status are borderless single lines, and a 1-row gap separates the
        // sections (sugar-boxer draws an inter-panel divider on a borderless leaf's
        // only row at spacing 0, so use spacing 1 — the blank line reads as
        // breathing room). The content grows to fill everything left over.
        $header = $b->leaf(' Phlix  ·  ' . self::headerLabel($title, $trail, $cols))->withBorder(false)->withMinHeight(1);
        $content = $b->leaf($body)->withBorder(true)->withGrow();
        $status = $b->leaf(' ' . $hint)->withBorder(false)->withMinHeight(1);

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
     * The content panel now FILLS the frame (sugar-boxer flex/grow), so the body
     * is a deterministic `rows - CHROME_HEIGHT` — no probing. This equals EXACTLY
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
     * @param list<string> $trail
     */
    private static function headerLabel(string $title, array $trail, int $cols): string
    {
        if ($trail === []) {
            return $title;
        }

        // Reserve the " Phlix  ·  " prefix (~11 cols) and a small margin.
        $maxWidth = max(10, $cols - 14);

        return (new Breadcrumb())->setMaxWidth($maxWidth)->renderTitles($trail);
    }
}
