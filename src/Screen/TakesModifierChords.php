<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Screen;

/**
 * Opt-in marker [CON-3]: the screen owns a typed buffer (form field, filter
 * row) and so legitimately receives ctrl/alt-modified Char keys (line-editing
 * chords such as Ctrl-U / Alt-Backspace).
 *
 * The App's key choke drops every modifier-held Char/Space KeyMsg before it
 * reaches a screen UNLESS the top screen implements this marker (or is a
 * {@see HostsTextInput} that is editing right now). Without the choke a
 * terminal reporting Ctrl-Y / Alt-Q as the bare rune (`y` / `q`) would
 * confirm a destructive prompt or quit the player, because the per-screen
 * key arms match on the rune alone.
 */
interface TakesModifierChords
{
}
