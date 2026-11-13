<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Ui;

/**
 * The single in-app key reference [review console CON-17]: the data behind the
 * `?` overlay ({@see \Phlix\Console\Screen\KeyHelpScreen}) and the palette's
 * "Keyboard shortcuts" action. One table, no hand-copy per surface — the
 * overlay renders straight from {@see sections()}, and
 * tests/Ui/KeyReferenceTest cross-checks every chord here against the shipped
 * docs/clients/console.md Keybindings tables so the two cannot drift.
 *
 * Only chords the handlers really bind appear (the App-level modifier choke
 * [CON-3] drops ctrl/alt chords other than Ctrl-C / Ctrl-K / Ctrl-Z).
 */
final class KeyReference
{
    /**
     * @return list<array{title: string, rows: list<array{0: string, 1: string}>}>
     */
    public static function sections(): array
    {
        return [
            ['title' => 'Navigation', 'rows' => [
                ['↑↓←→', 'Move focus / scroll'],
                ['PgUp / PgDn', 'Page a grid'],
                ['Home / End', 'First / last item in a grid'],
                ['Enter', 'Open selected item'],
                ['Esc', 'Go back / dismiss'],
                ['Tab', 'Switch focus (sidebar / grid)'],
                ['R', 'Refresh the home rails'],
                ['s', 'Shuffle-play the focused card'],
                ['H / P', 'Watch history / playlists (home)'],
                ['q', 'Quit (home)'],
                ['Ctrl-C', 'Quit'],
                ['Ctrl-Z', 'Suspend to the background'],
            ]],
            ['title' => 'Search and commands', 'rows' => [
                ['/', 'Global search (or filter within a grid)'],
                ['Ctrl-K or :', 'Command palette'],
                ['?', 'This key reference'],
            ]],
            ['title' => 'Player', 'rows' => [
                ['Space', 'Play / pause'],
                ['← / →', 'Seek 10 seconds'],
                ['0–9', 'Seek to a percentage'],
                ['[ / ]', 'Playback speed'],
                ['m', 'Cycle render mode'],
                ['c', 'Toggle captions'],
                ['a / S / C', 'Audio / subtitle / chapter picker'],
                ['n / p', 'Next / previous episode'],
                ['f', 'Toggle player chrome'],
                ['q or Esc', 'Close the player'],
            ]],
        ];
    }

    /**
     * The flat render lines (section titles,
     * then `key  action` rows, a blank line between sections).
     *
     * @return list<string>
     */
    public static function lines(): array
    {
        $keyW = 0;
        foreach (self::sections() as $section) {
            foreach ($section['rows'] as $row) {
                $keyW = max($keyW, mb_strlen($row[0]));
            }
        }

        $out = [];
        foreach (self::sections() as $i => $section) {
            if ($i > 0) {
                $out[] = '';
            }
            $out[] = '  ' . $section['title'];
            foreach ($section['rows'] as [$key, $action]) {
                $line = '    ' . $key . str_repeat(' ', $keyW - mb_strlen($key)) . '  ' . $action;
                $out[] = $line;
            }
        }

        return $out;
    }
}
