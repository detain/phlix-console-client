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
 * A named color theme for the app shell. T1 kept it to two color tokens:
 *
 *   - {@see $accent} — the brand / active-emphasis color (the " Phlix " token in
 *     the header). `null` means "no color" → the terminal's default foreground.
 *   - {@see $muted}  — the status-hint color. `null` means "no color".
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
 * ## Color suppression — NO_COLOR and a basic TERM
 *
 * A user who exports {@see colorSuppressed()} — `NO_COLOR` with any non-empty
 * value (the no-color.org convention: the mere presence disables, an empty
 * value does not), or a `TERM` that signals a basic terminal: unset, empty,
 * the literal `dumb`, or ncurses' fallback name `unknown` — gets COLOR-FREE
 * output from every styled surface here, regardless of theme.
 * Suppression is color-only, exactly as the two conventions specify: bold,
 * faint and reverse attributes survive, so emphasis and selection stay
 * visible on terminals that render color poorly or not at all.
 *
 * ## Content-widget roles (M-CONSOLE-48)
 *
 * Beyond the two chrome tokens, widgets (rating stars, badges, card titles,
 * the clear-rating danger mark, cast-avatar backgrounds) take their colors
 * from {@see widgetStyle()} / {@see avatarStyle()} — the ONLY legal home of
 * color hexes in src/ is the palette tables below. Four widgets used to
 * hardcode raw truecolor (`#ffffff` card titles included), so they rendered
 * identically under every theme and illegibly on light terminals; a drift
 * pin (ThemePaletteSoleSourceTest) censuses src/ for hex literals outside
 * this file and keeps that bypass class permanently red. Nocturne carries
 * the historical hexes verbatim so the default theme stays byte-identical
 * to the pre-palette emission — with ONE disclosed exception: the badge is
 * lifted from `#0066cc` to `#5fafff` (CON-23), because the default theme
 * is what dark terminals run and the old blue failed AA there (3.77:1 on
 * #000000, 2.99:1 on #1e1e1e); Daylight remaps every role that was
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
     * @param ?string $accent brand / emphasis color as hex (`#rrggbb`), or null for the terminal default
     * @param ?string $muted  status-hint color as hex, or null for the terminal default
     */
    public function __construct(
        public string $name,
        public string $id,
        public ?string $accent = null,
        public ?string $muted = null,
    ) {
    }

    /**
     * Whether the process environment asks for color-free output: `NO_COLOR`
     * set to any non-empty value (no-color.org: presence disables, an empty
     * value does not), or `TERM` signalling a basic terminal — unset or
     * empty (no terminal type was ever advertised: minimal containers,
     * tmux/screen misconfigs; the ncurses convention treats this as the most
     * basic terminal), the literal `dumb` (the classic line-mode terminal),
     * or ncurses' own fallback name `unknown`.
     *
     * Read fresh per call — the same per-decision-snapshot posture the rest of
     * the estate takes: a TUI process's environment does not change under it,
     * and a memoised flag is one more stale state to test against. The house
     * env doctrine (PosterLoader/DownloadService): getenv() is the one true
     * process-environment read — putenv lands here; $_ENV only when
     * variables_order says so.
     */
    public static function colorSuppressed(): bool
    {
        $noColor = getenv('NO_COLOR');

        if (is_string($noColor) && $noColor !== '') {
            return true;
        }

        return self::basicTerminal();
    }

    /**
     * Whether `TERM` signals a basic terminal: unset or empty (no terminal
     * type was ever advertised — the ncurses convention treats this as the
     * most basic terminal), the literal `dumb` (the classic line-mode
     * terminal), or ncurses' own fallback name `unknown`.
     *
     * One predicate, two consumers: {@see colorSuppressed()} drops colour on
     * it, and `bin/phlix run` refuses to start the full-window app on it — a
     * terminal that advertises no cursor addressing cannot interpret the
     * renderer's alternate-screen / cursor-positioning stream, so colour
     * suppression alone would still leave escape garbage on screen
     * [review CON-16]. Same fresh getenv() read as colorSuppressed().
     */
    public static function basicTerminal(): bool
    {
        $term = getenv('TERM');

        return $term === false || $term === '' || $term === 'dumb' || $term === 'unknown';
    }

    /**
     * A ready style for the " Phlix " brand token. Under Nocturne (accent null)
     * this is a plain {@see Style::new()} that renders text unchanged (zero SGR),
     * keeping the header byte-identical to today. Otherwise it carries the accent
     * foreground, profile-resolved for the terminal. Under {@see colorSuppressed()}
     * the color is dropped — same zero-SGR identity as Nocturne.
     */
    public function brandStyle(): Style
    {
        if ($this->accent === null || self::colorSuppressed()) {
            return Style::new();
        }

        return Style::new()->fg(Color::hex($this->accent))->resolveProfile();
    }

    /**
     * A ready style for the status-hint line. Null muted → plain (unchanged)
     * style; otherwise the muted foreground, profile-resolved. Under
     * {@see colorSuppressed()} the color is dropped (plain style).
     */
    public function statusStyle(): Style
    {
        if ($this->muted === null || self::colorSuppressed()) {
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
     * was contrast, honestly degraded rather than recolored).
     *
     * Nocturne carries the four widgets' historical hexes verbatim (M48 zero-
     * golden law: default-theme emission stays byte-identical) EXCEPT the
     * badge — a disclosed golden move (CON-23): the historical `#0066cc`
     * measured 3.77:1 on #000000 and 2.99:1 on #1e1e1e, below the 4.5:1 AA
     * body-text floor on the dark fields the default theme actually runs on,
     * so it takes Midnight's lift `#5fafff` (9.06:1 on #000000, 7.19:1 on
     * #1e1e1e; ThemeMutedContrastTest re-measures and pins both dark themes).
     * Daylight maps every role illegible on light terminals; Midnight's badge
     * sits on its accent `#5fafff`, the same lift Nocturne now carries.
     *
     * @var array<string, array<string, array{?string, bool}>>
     */
    private const ROLE_COLORS = [
        'nocturne' => [
            self::ROLE_STAR => ['#ffcc00', true],
            self::ROLE_BADGE => ['#5fafff', true],
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
     * A null role hex renders attribute-only (terminal default color) — and
     * under {@see colorSuppressed()} every role renders that way: attributes
     * kept, color dropped.
     *
     * Unknown theme id or unknown role is a programming error — fail loud at
     * the boundary rather than silently render someone's stale guess (the
     * preset list and these tables drift-check pin themselves in tests).
     */
    public function widgetStyle(string $role, bool $reversed = false): Style
    {
        [$hex, $bold] = $this->widgetRole($role);

        $style = Style::new();
        if ($bold) {
            $style = $style->bold();
        }
        if ($reversed) {
            $style = $style->reverse();
        }
        if ($hex !== null && !self::colorSuppressed()) {
            $style = $style->fg(Color::hex($hex));
        }

        return $style->resolveProfile();
    }

    /**
     * The raw palette hex for a content-widget role (null = terminal default),
     * before any profile resolution or color suppression — the token the
     * contrast pins measure. Same fail-loud boundary as {@see widgetStyle()}.
     */
    public function widgetHex(string $role): ?string
    {
        return $this->widgetRole($role)[0];
    }

    /**
     * @return array{?string, bool}
     */
    private function widgetRole(string $role): array
    {
        $palette = self::ROLE_COLORS[$this->id]
            ?? throw new InvalidArgumentException("Theme '{$this->id}' has no widget palette");

        return $palette[$role]
            ?? throw new InvalidArgumentException("Unknown widget color role '{$role}'");
    }

    /**
     * A cast-avatar chip style: the dimmed initials look the widgets shipped
     * with, on the theme's ramp slot ($slot is the historical crc32 of the
     * member name — the call site keeps the slotting, the theme keeps the hues).
     * Under {@see colorSuppressed()} the chip keeps its faint attribute and
     * loses only the colored field.
     */
    public function avatarStyle(int $slot): Style
    {
        if ($slot < 0) {
            throw new InvalidArgumentException("Avatar slot {$slot} must be non-negative");
        }

        $ramp = self::AVATAR_RAMPS[$this->id]
            ?? throw new InvalidArgumentException("Theme '{$this->id}' has no avatar ramp");

        $style = Style::new()->faint();
        if (!self::colorSuppressed()) {
            $style = $style->bg(Color::hex($ramp[$slot % count($ramp)]));
        }

        return $style->resolveProfile();
    }

    /**
     * The identity / default theme: no chrome colors, so the app renders exactly
     * as it did before the theme system existed — except the recommendation
     * badge, lifted to `#5fafff` for dark-field AA (CON-23; see ROLE_COLORS).
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

    /**
     * Accent + muted tuned for DARK terminals (bright blue on a dark field).
     * The muted grey clears WCAG AA on every common dark backdrop: measured
     * 5.19:1 on #1e1e1e, 5.11:1 on #1e1e2e, 5.84:1 on #11111b, 6.54:1 on
     * #000000 (the pre-lift #6c7086 sat at 3.41:1 on #1e1e1e — below the
     * 4.5:1 body-text floor; ThemeMutedContrastTest re-measures and pins it).
     */
    public static function midnight(): self
    {
        return new self('Midnight', 'midnight', '#5fafff', '#8a8fa3');
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
