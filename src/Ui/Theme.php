<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Ui;

use SugarCraft\Core\Util\Color;
use SugarCraft\Style\Style;

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
 *
 * ## Name vs. id — the wire law
 *
 * `$name` is the human-readable label the picker renders; `$id` is the canonical
 * lowercase wire identifier. Since phlix-server's S85-era strict theme
 * validation (UserRepository::validateThemeId), PUT /api/v1/users/me/settings
 * accepts ONLY an id in BuiltInThemes::IDS ('nocturne'/'daylight'/'midnight')
 * or an enabled plugin theme slug — anything else, display names included,
 * answers 400. Every settings sync therefore sends {@see $id} and never
 * {@see $name}.
 */
final readonly class Theme
{
    /**
     * @param string  $name   human-readable name (shown in the T2 picker) — UI-only, never sent on the wire
     * @param string  $id     canonical lowercase wire id, the ONLY theme value the
     *                        server accepts on PUT /api/v1/users/me/settings
     *                        (phlix-server's BuiltInThemes::IDS — 'nocturne',
     *                        'daylight', 'midnight' — or a plugin theme slug);
     *                        the display name is NOT a valid wire value
     * @param ?string $accent brand / emphasis colour as hex (`#rrggbb`), or null for the terminal default
     * @param ?string $muted  status-hint colour as hex, or null for the terminal default
     */
    public function __construct(
        public string $name,
        public string $id,
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
        return new self('Nocturne', 'nocturne', null, null);
    }

    /** Accent + muted tuned for LIGHT terminals (deep teal on a light field). */
    public static function daylight(): self
    {
        return new self('Daylight', 'daylight', '#005f87', '#6b6b6b');
    }

    /** Accent + muted tuned for DARK terminals (bright blue on a dark field). */
    public static function midnight(): self
    {
        return new self('Midnight', 'midnight', '#5fafff', '#6c7086');
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
     * Resolve a preset from any persisted theme string (case-insensitive): a
     * canonical wire id ('midnight' — what the server stores and echoes back on
     * GET /api/v1/users/me/settings) or a legacy display name ('Midnight' — what
     * pre-wire-id client versions wrote into the local config). An unknown /
     * fossil / null-ish value (e.g. a pre-S85 'phlix-dark' row surviving on the
     * server) falls back to {@see nocturne()} so a stale or hand-edited config
     * can never crash the boot.
     */
    public static function byName(string $name): self
    {
        $needle = strtolower(trim($name));
        foreach (self::all() as $theme) {
            if ($theme->id === $needle || strtolower($theme->name) === $needle) {
                return $theme;
            }
        }

        return self::nocturne();
    }
}
