<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SettingsSavedMsg;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Theme;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Form;

/**
 * The Settings screen: a two-field candy-forms form — a theme picker
 * (Nocturne / Daylight / Midnight) and the photo-slideshow interval (seconds) —
 * both PRE-FILLED with the user's current values. On submit it emits
 * {@see SettingsSavedMsg} (the App persists + applies the theme LIVE + pops back);
 * Esc cancels with NO save.
 *
 * Like {@see ServerScreen}, the embedded Form returns Cmd::quit() on submit /
 * abort (it is built to run as a standalone Program); this screen intercepts that
 * and substitutes its own navigation intent so the app doesn't exit. A blank /
 * unknown theme falls back to the current one; a non-numeric or out-of-range
 * interval re-prompts with an inline error (the form is rebuilt, keeping editing).
 */
final class SettingsScreen implements Model, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    /** Floor / ceiling (seconds) for the photo-slideshow interval — mirrors Config. */
    private const INTERVAL_MIN = 1;
    private const INTERVAL_MAX = 300;

    public function __construct(
        public readonly Form $form,
        public readonly string $currentTheme,
        public readonly int $currentInterval,
        public readonly ?string $error = null,
        public readonly int $cols = 80,
        public readonly int $rows = 24,
    ) {
    }

    public static function create(string $currentTheme, int $currentInterval, int $cols = 80, int $rows = 24): self
    {
        return new self(self::buildForm($currentTheme, $currentInterval), $currentTheme, $currentInterval, null, $cols, $rows);
    }

    private static function buildForm(string $currentTheme, int $currentInterval): Form
    {
        return Form::new(
            Select::new('theme')
                ->withTitle('Theme')
                ->withOptions(...array_map(static fn (Theme $t): string => $t->name, Theme::all()))
                ->withSelected($currentTheme),
            Input::new('slideshow')
                ->withTitle('Photo slideshow interval (seconds)')
                ->withValue((string) $currentInterval),
        );
    }

    public function init(): ?\Closure
    {
        return $this->form->init();
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [new self($this->form, $this->currentTheme, $this->currentInterval, $this->error, $msg->cols, $msg->rows), null];
        }

        /** @var array{0: Form, 1: ?\Closure} $result candy-forms' Form::update inherits Model's loose `:array` return, so narrow it. */
        $result = $this->form->update($msg);
        [$form, $cmd] = $result;

        // Esc / Ctrl-C cancels — no save, pop back to the previous screen.
        if ($form->isAborted()) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }

        if ($form->isSubmitted()) {
            $themeName = $this->resolveTheme($form->getString('theme'));
            $interval = $this->parseInterval($form->getString('slideshow'));
            if ($interval === null) {
                // Re-prompt (like ServerScreen's empty-URL re-prompt): rebuild the
                // form keeping the chosen theme + the user's interval-so-far display.
                $fresh = self::buildForm($themeName, $this->currentInterval);

                return [
                    new self($fresh, $themeName, $this->currentInterval, 'Enter a slideshow interval between 1 and 300 seconds.', $this->cols, $this->rows),
                    $fresh->init(),
                ];
            }

            return [
                new self($form, $themeName, $interval, null, $this->cols, $this->rows),
                Cmd::send(new SettingsSavedMsg($themeName, $interval)),
            ];
        }

        return [new self($form, $this->currentTheme, $this->currentInterval, $this->error, $this->cols, $this->rows), $cmd];
    }

    public function view(): string
    {
        $lines = ['Settings', ''];
        if ($this->error !== null) {
            $lines[] = '  ' . $this->error;
            $lines[] = '';
        }
        $body = implode("\n", $lines) . $this->form->view();

        return Chrome::frame('Settings', $body, 'Enter  save      Esc  cancel', $this->cols, $this->rows, theme: $this->theme());
    }

    /** A submitted theme value, validated against the presets; blank/unknown → the current theme. */
    private function resolveTheme(string $value): string
    {
        foreach (Theme::all() as $theme) {
            if ($theme->name === $value) {
                return $theme->name;
            }
        }

        return $this->currentTheme;
    }

    /**
     * Parse the slideshow-interval entry: a numeric value inside [1, 300] is
     * accepted; a non-numeric or out-of-range entry returns null (the caller
     * re-prompts). Config clamps defensively too, but rejecting here lets the
     * user fix an obvious typo (e.g. "0" / "abc") rather than silently snapping.
     */
    private function parseInterval(string $value): ?int
    {
        $value = trim($value);
        if (!is_numeric($value)) {
            return null;
        }

        $seconds = (int) $value;
        if ($seconds < self::INTERVAL_MIN || $seconds > self::INTERVAL_MAX) {
            return null;
        }

        return $seconds;
    }
}
