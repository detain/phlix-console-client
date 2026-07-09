<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Ui;

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Style;

/**
 * A named colour theme for the app shell. T1 keeps it to two colour tokens:
 *
 *   - {@see $accent} — the brand / active-emphasis colour (the " Phlix " token in
 *     the header). `null` means "no colour" → the terminal's default foreground.
 *   - {@see $muted}  — the status-hint colour. `null` means "no colour".
 *
 * The default theme, {@see nocturne()}, carries `null` for both, so every styled
 * surface renders BYTE-IDENTICALLY to the pre-theme plain output: a fresh
 * {@see Style::new()} adds zero SGR (verified — `Style::new()->render($s) === $s`),
 * so {@see brandStyle()} / {@see statusStyle()} are true no-ops under Nocturne.
 * That identity is what lets the whole theme be threaded through the chrome
 * without changing the look of the current (monochrome) UI.
 *
 * The non-default themes ({@see daylight()}, {@see midnight()}) carry hex accents
 * tuned for light / dark terminals; their style factories call
 * {@see Style::resolveProfile()} so a truecolor accent downgrades to 256 / ANSI
 * per the terminal's capability.
 */
final readonly class Theme
{
    /**
     * @param string  $name   human-readable name (shown in the T2 picker)
     * @param ?string $accent brand / emphasis colour as hex (`#rrggbb`), or null for the terminal default
     * @param ?string $muted  status-hint colour as hex, or null for the terminal default
     */
    public function __construct(
        public string $name,
        public ?string $accent = null,
        public ?string $muted = null,
    ) {
    }

    /**
     * A ready style for the " Phlix " brand token. Under Nocturne (accent null)
     * this is a plain {@see Style::new()} that renders text unchanged (zero SGR),
     * keeping the header byte-identical to today. Otherwise it carries the accent
     * foreground, profile-resolved for the terminal.
     */
    public function brandStyle(): Style
    {
        if ($this->accent === null) {
            return Style::new();
        }

        return Style::new()->fg(Color::hex($this->accent))->resolveProfile();
    }

    /**
     * A ready style for the status-hint line. Null muted → plain (unchanged)
     * style; otherwise the muted foreground, profile-resolved.
     */
    public function statusStyle(): Style
    {
        if ($this->muted === null) {
            return Style::new();
        }

        return Style::new()->fg(Color::hex($this->muted))->resolveProfile();
    }

    /**
     * The identity / default theme: no colours, so the app renders exactly as it
     * did before the theme system existed.
     */
    public static function nocturne(): self
    {
        return new self('Nocturne', null, null);
    }

    /** Accent + muted tuned for LIGHT terminals (deep teal on a light field). */
    public static function daylight(): self
    {
        return new self('Daylight', '#005f87', '#6b6b6b');
    }

    /** Accent + muted tuned for DARK terminals (bright blue on a dark field). */
    public static function midnight(): self
    {
        return new self('Midnight', '#5fafff', '#6c7086');
    }

    /**
     * Every preset, in picker order (Nocturne first as the default).
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return [self::nocturne(), self::daylight(), self::midnight()];
    }

    /**
     * Resolve a preset by name (case-insensitive). An unknown / null-ish name
     * falls back to {@see nocturne()} so a stale or hand-edited config can never
     * crash the boot.
     */
    public static function byName(string $name): self
    {
        $needle = strtolower(trim($name));
        foreach (self::all() as $theme) {
            if (strtolower($theme->name) === $needle) {
                return $theme;
            }
        }

        return self::nocturne();
    }
}
