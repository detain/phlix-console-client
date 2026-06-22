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
