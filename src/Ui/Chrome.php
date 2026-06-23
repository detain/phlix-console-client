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

        $header = $b->leaf(' Phlix  ·  ' . self::headerLabel($title, $trail, $cols))->withMinHeight(1);
        $content = $b->leaf($body)->withBorder(true);
        $status = $b->leaf(' ' . $hint)->withMinHeight(1);

        $root = $b->vertical($header, $content, $status);

        return $b->render($root, max(1, $cols), max(1, $rows));
    }

    /** @var array<string,int> */
    private static array $contentHeightCache = [];

    /**
     * How many body lines the bordered content region can actually show at a
     * given terminal size.
     *
     * sugar-boxer's vertical split distributes height by weight, so the content
     * region is only a fraction of $rows — NOT `rows - chrome`. A screen that
     * sizes a scrolling body (a grid window, a table viewport) must clamp it to
     * THIS height, or its lower rows — including the selected one — get clipped
     * by the frame. Measured by probing the real frame once per size (memoized,
     * deterministic).
     */
    public static function contentHeight(int $cols, int $rows): int
    {
        $key = $cols . ':' . $rows;
        if (isset(self::$contentHeightCache[$key])) {
            return self::$contentHeightCache[$key];
        }

        // A tall body of uniquely-tagged lines; count how many survive the frame.
        $lines = [];
        for ($i = 1, $n = max(1, $rows); $i <= $n; $i++) {
            $lines[] = "\x01" . $i . "\x01";
        }
        $rendered = self::frame('', implode("\n", $lines), '', $cols, $rows);
        $stripped = preg_replace('/\e\[[0-9;]*m/', '', $rendered) ?? $rendered;
        preg_match_all('/\x01\d+\x01/', $stripped, $matches);

        return self::$contentHeightCache[$key] = max(1, count(array_unique($matches[0])));
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
