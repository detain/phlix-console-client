<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Ui;

use SugarCraft\Panels\Node;
use SugarCraft\Panels\SugarPanels;

/**
 * The one door through which this app turns a sugar-panels layout into text.
 *
 * Recent sugar-panels releases prefix EVERY rendered frame with an absolute
 * cursor-home (CUP, `ESC[H`) so a diff-replaying host always paints from the
 * origin. This app never replays panel frames: every call site renders a fresh
 * {@see SugarPanels} once and treats the result as plain layout TEXT — a screen
 * body handed to the runtime, or a modal box spliced/grafted into another
 * frame line by line. A leading CUP inside that text would land mid-composite
 * (corrupting the line splice and the width maths) and breaks the
 * monochrome-Nocturne "zero escape" identity. The home prefix is therefore
 * dropped here, once; a frame that carries no prefix passes through untouched.
 */
final class PanelRender
{
    /** Cursor-home prefix sugar-panels may put in front of a frame. */
    private const HOME = "\x1b[H";

    /** Render a layout tree to plain frame text (no leading cursor-home). */
    public static function render(Node $root, int $width, int $height): string
    {
        return self::plain(SugarPanels::new()->render($root, $width, $height));
    }

    /** Drop one leading cursor-home from an already-rendered panels frame. */
    public static function plain(string $frame): string
    {
        return str_starts_with($frame, self::HOME) ? substr($frame, strlen(self::HOME)) : $frame;
    }
}
