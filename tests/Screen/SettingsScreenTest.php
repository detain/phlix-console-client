<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SettingsSavedMsg;
use Phlix\Console\Screen\SettingsScreen;
use Phlix\Console\Screen\Themed;
use Phlix\Console\Ui\Theme;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Form;

/**
 * The embedded candy-forms Form is driven the way ServerScreenTest drives it:
 * KeyMsgs flow through update(). The form starts focused on the Select (theme);
 * a Select consumes Up/Down (option nav), so focus is moved to the Input with
 * Tab, and Enter on that last field submits. Esc on the (non-filtering) Select
 * aborts → NavigateBack with no save.
 */
final class SettingsScreenTest extends TestCase
{
    /** Tab to the slideshow Input (the Select consumes arrows, so Tab advances focus). */
    private function toInterval(Model $model): Model
    {
        [$model] = $model->update(new KeyMsg(KeyType::Tab));

        return $model;
    }

    private function type(Model $model, string $text): Model
    {
        foreach (mb_str_split($text) as $ch) {
            [$model] = $model->update(new KeyMsg(KeyType::Char, $ch));
        }

        return $model;
    }

    private function backspace(Model $model, int $times): Model
    {
        for ($i = 0; $i < $times; $i++) {
            [$model] = $model->update(new KeyMsg(KeyType::Backspace));
        }

        return $model;
    }

    // ---- pre-fill ------------------------------------------------------

    public function testCreatePreSelectsTheThemeAndPreFillsTheInterval(): void
    {
        $screen = SettingsScreen::create('Daylight', 8);

        // The Select pre-selects Daylight; the Input pre-fills "8" — both readable
        // off the form's (untouched) submitted values.
        self::assertSame('Daylight', $screen->form->getString('theme'));
        self::assertSame('8', $screen->form->getString('slideshow'));

        $view = $screen->view();
        self::assertStringContainsString('Settings', $view);
        self::assertStringContainsString('Theme', $view);
        self::assertStringContainsString('8', $view, 'the current interval pre-fills the input');
    }

    // ---- submit --------------------------------------------------------

    public function testSubmitEmitsSettingsSavedWithTheCurrentValues(): void
    {
        $screen = SettingsScreen::create('Daylight', 8);

        // Tab to the interval input, then Enter submits the (pre-filled) values.
        $screen = $this->toInterval($screen);
        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        self::assertInstanceOf(\Closure::class, $cmd);
        $msg = $cmd();
        self::assertInstanceOf(SettingsSavedMsg::class, $msg);
        self::assertSame('Daylight', $msg->themeName);
        self::assertSame(8, $msg->slideshowInterval);
        self::assertInstanceOf(SettingsScreen::class, $next);
        self::assertNull($next->error);
    }

    public function testAnUnknownSubmittedThemeFallsBackToTheCurrentTheme(): void
    {
        // A form whose theme Select offers a value that is NOT a real preset
        // (e.g. a stale option). On submit, resolveTheme() must fall back to the
        // current theme rather than persisting the bogus name.
        $form = Form::new(
            Select::new('theme')->withTitle('Theme')->withOptions('Bogus'),
            Input::new('slideshow')->withTitle('Photo slideshow interval (seconds)')->withValue('8'),
        );
        $screen = new SettingsScreen($form, 'Midnight', 8);

        $screen = $this->toInterval($screen);
        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        $msg = $cmd();
        self::assertInstanceOf(SettingsSavedMsg::class, $msg);
        self::assertSame('Midnight', $msg->themeName, 'an unknown submitted theme falls back to the current theme');
        self::assertSame(8, $msg->slideshowInterval);
    }

    public function testSubmitWithAnEditedIntervalSavesTheNewValue(): void
    {
        $screen = SettingsScreen::create('Midnight', 4);
        $screen = $this->toInterval($screen);
        // Clear the pre-filled "4" and type a new value.
        $screen = $this->backspace($screen, 1);
        $screen = $this->type($screen, '30');

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        $msg = $cmd();
        self::assertInstanceOf(SettingsSavedMsg::class, $msg);
        self::assertSame('Midnight', $msg->themeName, 'the theme passes through unchanged');
        self::assertSame(30, $msg->slideshowInterval);
    }

    // ---- bad interval re-prompts ---------------------------------------

    public function testZeroIntervalRePromptsWithAnErrorAndNoSave(): void
    {
        $screen = SettingsScreen::create('Nocturne', 4);
        $screen = $this->toInterval($screen);
        $screen = $this->backspace($screen, 1); // clear "4"
        $screen = $this->type($screen, '0');

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        self::assertInstanceOf(SettingsScreen::class, $next);
        self::assertNotNull($next->error, 'an out-of-range interval re-prompts with an error');
        if ($cmd !== null) {
            self::assertNotInstanceOf(SettingsSavedMsg::class, $cmd());
        }
        self::assertStringContainsString('300', $next->view(), 'the error names the valid range');
    }

    public function testNonNumericIntervalRePromptsWithAnErrorAndNoSave(): void
    {
        $screen = SettingsScreen::create('Nocturne', 4);
        $screen = $this->toInterval($screen);
        // Append letters to the pre-filled "4" → "4abc" is non-numeric.
        $screen = $this->type($screen, 'abc');

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        self::assertInstanceOf(SettingsScreen::class, $next);
        self::assertNotNull($next->error);
        if ($cmd !== null) {
            self::assertNotInstanceOf(SettingsSavedMsg::class, $cmd());
        }
    }

    public function testTooHighIntervalRePromptsWithAnErrorAndNoSave(): void
    {
        $screen = SettingsScreen::create('Nocturne', 4);
        $screen = $this->toInterval($screen);
        $screen = $this->backspace($screen, 1); // clear "4"
        $screen = $this->type($screen, '5000');

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        self::assertNotNull($next->error);
        if ($cmd !== null) {
            self::assertNotInstanceOf(SettingsSavedMsg::class, $cmd());
        }
    }

    // ---- esc / abort ---------------------------------------------------

    public function testEscCancelsWithNavigateBackAndNoSave(): void
    {
        $screen = SettingsScreen::create('Daylight', 8);

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Escape));

        self::assertInstanceOf(\Closure::class, $cmd);
        $msg = $cmd();
        self::assertInstanceOf(NavigateBackMsg::class, $msg);
        self::assertNotInstanceOf(SettingsSavedMsg::class, $msg, 'Esc never saves');
        self::assertNotInstanceOf(QuitMsg::class, $msg, 'the screen substitutes nav for the form quit');
    }

    // ---- resize --------------------------------------------------------

    public function testResizeUpdatesDimensionsAndKeepsValues(): void
    {
        [$next] = SettingsScreen::create('Daylight', 8)->update(new WindowSizeMsg(120, 40));

        self::assertInstanceOf(SettingsScreen::class, $next);
        self::assertSame(120, $next->cols);
        self::assertSame(40, $next->rows);
        self::assertSame('Daylight', $next->currentTheme);
        self::assertSame(8, $next->currentInterval);
    }

    // ---- themed --------------------------------------------------------

    public function testIsThemedAndRendersTheAccentBrandUnderAColourTheme(): void
    {
        $screen = SettingsScreen::create('Daylight', 8);

        self::assertInstanceOf(Themed::class, $screen);
        // Under Nocturne (the default) the ` Phlix ` brand is plain (the Select's
        // own reverse-video highlight is unrelated to the theme); under Midnight
        // the brand is accent-wrapped — proof the screen threads its theme into
        // the chrome header.
        self::assertStringContainsString(' Phlix  ·  Settings', $screen->view(), 'the default brand carries no accent SGR');
        $midnight = $screen->withTheme(Theme::midnight());
        self::assertMatchesRegularExpression('/\e\[[0-9;]*m Phlix \e\[0m/', $midnight->view(), 'the brand is accent-wrapped under Midnight');
    }
}
