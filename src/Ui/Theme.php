<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Ui;

use InvalidArgumentException;
use SugarCraft\Core\Util\Color;
use SugarCraft\Style\Style;

/**
 * A named colour theme for the app shell. T1 kept it to two colour tokens:
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
 * ## Content-widget roles (M-CONSOLE-48)
 *
 * Beyond the two chrome tokens, widgets (rating stars, badges, card titles,
 * the clear-rating danger mark, cast-avatar backgrounds) take their colours
 * from {@see widgetStyle()} / {@see avatarStyle()} — the ONLY legal home of
 * colour hexes in src/ is the palette tables below. Four widgets used to
 * hardcode raw truecolor (`#ffffff` card titles included), so they rendered
 * identically under every theme and illegibly on light terminals; a drift
 * pin (ThemePaletteSoleSourceTest) censuses src/ for hex literals outside
 * this file and keeps that bypass class permanently red. Nocturne carries
 * the historical hexes verbatim so the default theme stays byte-identical
 * to the pre-palette emission; Daylight remaps every role that was
 * illegible on a light field (dark gold, its own accent, terminal-default
 * title, dark red) and swaps the avatar ramp for pastel backgrounds.
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

    /** Role token: filled rating stars and match-score emphasis. */
    public const ROLE_STAR = 'star';

    /** Role token: the "Because You Watched" recommendation badge. */
    public const ROLE_BADGE = 'badge';

    /** Role token: recommendation-card title text. */
    public const ROLE_TITLE = 'title';

    /** Role token: the clear-rating (danger) mark. */
    public const ROLE_DANGER = 'danger';

    /**
     * Content-widget palette: theme id => role => [hex|null, bold]. A null hex
     * means "terminal default" (daylight's title — the one text whose whole job
     * was contrast, honestly degraded rather than recoloured).
     *
     * Nocturne carries the four widgets' historical hexes verbatim (M48 zero-
     * golden law: default-theme emission stays byte-identical); Daylight maps
     * every role illegible on light terminals; Midnight lifts the badge onto its
     * accent because `#0066cc` vanishes on a dark field.
     *
     * @var array<string, array<string, array{?string, bool}>>
     */
    private const ROLE_COLORS = [
        'nocturne' => [
            self::ROLE_STAR => ['#ffcc00', true],
            self::ROLE_BADGE => ['#0066cc', true],
            self::ROLE_TITLE => ['#ffffff', false],
            self::ROLE_DANGER => ['#ff6666', true],
        ],
        'daylight' => [
            self::ROLE_STAR => ['#7a5c00', true],
            self::ROLE_BADGE => ['#005f87', true],
            self::ROLE_TITLE => [null, false],
            self::ROLE_DANGER => ['#a40000', true],
        ],
        'midnight' => [
            self::ROLE_STAR => ['#ffcc00', true],
            self::ROLE_BADGE => ['#5fafff', true],
            self::ROLE_TITLE => ['#ffffff', false],
            self::ROLE_DANGER => ['#ff6666', true],
        ],
    ];

    /**
     * Cast-avatar background ramp: theme id => six hues, slot-picked by
     * crc32(member name) — the historical order is preserved on the dark
     * postures, daylight swaps in pastels so the dimmed initials keep a
     * readable field on light terminals.
     *
     * @var array<string, list<string>>
     */
    private const AVATAR_RAMPS = [
        'nocturne' => ['#ff0000', '#00ff00', '#ffff00', '#0000ff', '#ff00ff', '#00ffff'],
        'daylight' => ['#ffcccc', '#ccffcc', '#ffffcc', '#ccccff', '#ffccff', '#ccffff'],
        'midnight' => ['#ff0000', '#00ff00', '#ffff00', '#0000ff', '#ff00ff', '#00ffff'],
    ];

    /**
     * The ready style for a content-widget role: the role's attributes (bold
     * when it carries emphasis, reverse for an interactive cursor) plus the
     * theme's hex for it, profile-resolved exactly like {@see brandStyle()}.
     * A null role hex renders attribute-only (terminal default colour).
     *
     * Unknown theme id or unknown role is a programming error — fail loud at
     * the boundary rather than silently render someone's stale guess (the
     * preset list and these tables drift-check pin themselves in tests).
     */
    public function widgetStyle(string $role, bool $reversed = false): Style
    {
        $palette = self::ROLE_COLORS[$this->id]
            ?? throw new InvalidArgumentException("Theme '{$this->id}' has no widget palette");
        [$hex, $bold] = $palette[$role]
            ?? throw new InvalidArgumentException("Unknown widget colour role '{$role}'");

        $style = Style::new();
        if ($bold) {
            $style = $style->bold();
        }
        if ($reversed) {
            $style = $style->reverse();
        }
        if ($hex !== null) {
            $style = $style->fg(Color::hex($hex));
        }

        return $style->resolveProfile();
    }

    /**
     * A cast-avatar chip style: the dimmed initials look the widgets shipped
     * with, on the theme's ramp slot ($slot is the historical crc32 of the
     * member name — the call site keeps the slotting, the theme keeps the hues).
     */
    public function avatarStyle(int $slot): Style
    {
        if ($slot < 0) {
            throw new InvalidArgumentException("Avatar slot {$slot} must be non-negative");
        }

        $ramp = self::AVATAR_RAMPS[$this->id]
            ?? throw new InvalidArgumentException("Theme '{$this->id}' has no avatar ramp");

        return Style::new()->faint()->bg(Color::hex($ramp[$slot % count($ramp)]))->resolveProfile();
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
