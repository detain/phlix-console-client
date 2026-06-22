<?php

declare(strict_types=1);

namespace Phlix\Console\Ui;

use SugarCraft\Boxer\SugarBoxer;

/**
 * The full-window app shell: a header, a bordered content region, and a status
 * line, composed with sugar-boxer. Screens render their body into the middle.
 */
final class Chrome
{
    public static function frame(string $title, string $body, string $hint, int $cols, int $rows): string
    {
        $b = SugarBoxer::new();

        $header = $b->leaf(' Phlix  ·  ' . $title)->withMinHeight(1);
        $content = $b->leaf($body)->withBorder(true);
        $status = $b->leaf(' ' . $hint)->withMinHeight(1);

        $root = $b->vertical($header, $content, $status);

        return $b->render($root, max(1, $cols), max(1, $rows));
    }
}
