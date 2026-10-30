<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Config\Config;
use Phlix\Console\Msg\LogoutMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenServersMsg;
use Phlix\Console\Msg\SettingsSavedMsg;
use Phlix\Console\Screen\SettingsScreen;
use Phlix\Console\Screen\Themed;
use Phlix\Console\Store\AuthStore;
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
 * The embedded sugar-forms Form is driven the way ServerScreenTest drives it:
 * KeyMsgs flow through update(). The form starts focused on the Select (theme);
 * a Select consumes Up/Down (option nav), so focus is moved to the Input with
 * Tab, and Enter on that last field submits. Esc on the (non-filtering) Select
 * aborts → NavigateBack with no save.
 */
final class SettingsScreenTest extends TestCase
{
    // AuthStore is final and requires filesystem dependencies, so tests pass null.
    // The form behavior (submit, validation, resize) is what these tests verify.
    private function makeAuthStore(?AuthUser $user = null): ?AuthStore
    {
        return null; // User display is not tested - form behavior is the focus.
    }

    private function makeConfig(string $theme = 'Daylight', int $interval = 8): Config
    {
        return new Config(
            theme: $theme,
            slideshowInterval: $interval,
        );
    }
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
        $screen = SettingsScreen::create($this->makeAuthStore(), $this->makeConfig('Daylight', 8));

        // The Select pre-selects Daylight; the Input pre-fills "8" — both readable
        // off the form's (untouched) submitted values.
        self::assertSame('Daylight', $screen->form->getString('theme'));
        self::assertSame('8', $screen->form->getString('slideshow'));

        $view = $screen->view();
        self::assertStringContainsString('Settings', $view);
        self::assertStringContainsString('Theme', $view);
        self::assertStringContainsString('8', $view, 'the current interval pre-fills the input');
    }

    public function testCreateNormalisesWireIdsAndFossilsToADisplayOption(): void
    {
        // config->theme arrives as a canonical wire id after a server sync
        // ('midnight'), as a legacy display name from an old local file
        // ('Midnight'), or as a pre-S85 fossil the presets never knew
        // ('phlix-dark'). The Select offers display names, so the seeded
        // selection must be normalised through Theme::byName() — never the raw
        // stored string, which would match no option at all.
        self::assertSame(
            'Midnight',
            SettingsScreen::create($this->makeAuthStore(), $this->makeConfig('midnight', 8))->currentTheme,
            'a lowercase wire id seeds its capitalised display option',
        );
        self::assertSame(
            'Daylight',
            SettingsScreen::create($this->makeAuthStore(), $this->makeConfig('Daylight', 8))->currentTheme,
            'a legacy display name seeds itself unchanged',
        );
        self::assertSame(
            'Nocturne',
            SettingsScreen::create($this->makeAuthStore(), $this->makeConfig('phlix-dark', 8))->currentTheme,
            'an unknown fossil falls back to the Nocturne default',
        );
        // The form carries the same normalised value (what the Select shows).
        $screen = SettingsScreen::create($this->makeAuthStore(), $this->makeConfig('nocturne', 8));
        self::assertSame('Nocturne', $screen->form->getString('theme'));
    }

    // ---- submit --------------------------------------------------------

    public function testSubmitEmitsSettingsSavedWithTheCurrentValues(): void
    {
        $screen = SettingsScreen::create($this->makeAuthStore(), $this->makeConfig('Daylight', 8));

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
        $screen = new SettingsScreen($form, 'Midnight', 8, $this->makeAuthStore(), $this->makeConfig('Midnight', 8));

        $screen = $this->toInterval($screen);
        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        $msg = $cmd();
        self::assertInstanceOf(SettingsSavedMsg::class, $msg);
        self::assertSame('Midnight', $msg->themeName, 'an unknown submitted theme falls back to the current theme');
        self::assertSame(8, $msg->slideshowInterval);
    }

    public function testSubmitWithAnEditedIntervalSavesTheNewValue(): void
    {
        $screen = SettingsScreen::create($this->makeAuthStore(), $this->makeConfig('Midnight', 4));
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
        $screen = SettingsScreen::create($this->makeAuthStore(), $this->makeConfig('Nocturne', 4));
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
        $screen = SettingsScreen::create($this->makeAuthStore(), $this->makeConfig('Nocturne', 4));
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
        $screen = SettingsScreen::create($this->makeAuthStore(), $this->makeConfig('Nocturne', 4));
        $screen = $this->toInterval($screen);
        $screen = $this->backspace($screen, 1); // clear "4"
        $screen = $this->type($screen, '5000');

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        self::assertInstanceOf(SettingsScreen::class, $next);
        self::assertNotNull($next->error);
        if ($cmd !== null) {
            self::assertNotInstanceOf(SettingsSavedMsg::class, $cmd());
        }
    }

    // ---- esc / abort ---------------------------------------------------

    public function testEscCancelsWithNavigateBackAndNoSave(): void
    {
        $screen = SettingsScreen::create($this->makeAuthStore(), $this->makeConfig('Daylight', 8));

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
        [$next] = SettingsScreen::create($this->makeAuthStore(), $this->makeConfig('Daylight', 8))->update(new WindowSizeMsg(120, 40));

        self::assertInstanceOf(SettingsScreen::class, $next);
        self::assertSame(120, $next->cols);
        self::assertSame(40, $next->rows);
        self::assertSame('Daylight', $next->currentTheme);
        self::assertSame(8, $next->currentInterval);
    }

    // ---- themed --------------------------------------------------------

    public function testIsThemedAndRendersTheAccentBrandUnderAColourTheme(): void
    {
        $screen = SettingsScreen::create($this->makeAuthStore(), $this->makeConfig('Daylight', 8));

        self::assertInstanceOf(Themed::class, $screen);
        // Under Nocturne (the default) the ` Phlix ` brand is plain (the Select's
        // own reverse-video highlight is unrelated to the theme); under Midnight
        // the brand is accent-wrapped — proof the screen threads its theme into
        // the chrome header.
        self::assertStringContainsString(' Phlix  ·  Settings', $screen->view(), 'the default brand carries no accent SGR');
        $midnight = $screen->withTheme(Theme::midnight());
        self::assertMatchesRegularExpression('/\e\[[0-9;]*m Phlix \e\[0m/', $midnight->view(), 'the brand is accent-wrapped under Midnight');
    }

    // ---- L / S key handlers --------------------------------------------

    public function testLKeyEmitsLogoutMsg(): void
    {
        $screen = SettingsScreen::create($this->makeAuthStore(), $this->makeConfig('Daylight', 8));

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'l'));

        self::assertInstanceOf(\Closure::class, $cmd);
        $msg = $cmd();
        self::assertInstanceOf(LogoutMsg::class, $msg);
    }

    public function testSKeyEmitsOpenServersMsg(): void
    {
        $screen = SettingsScreen::create($this->makeAuthStore(), $this->makeConfig('Daylight', 8));

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 's'));

        self::assertInstanceOf(\Closure::class, $cmd);
        $msg = $cmd();
        self::assertInstanceOf(OpenServersMsg::class, $msg);
    }

    // [review console__a11y critical 1] The status hint advertises the UPPERCASE
    // 'L'/'S' (like BrowseScreen advertises 'H'/'P' for its h/H, p/P pairs), but
    // the handler only accepted the lowercase rune — pressing the advertised key
    // fell through to the form and did nothing. The hint is the app's own
    // instruction; what it prints must be what the screen accepts.

    public function testUppercaseLKeyEmitsLogoutMsgLikeTheHintAdvertises(): void
    {
        $screen = SettingsScreen::create($this->makeAuthStore(), $this->makeConfig('Daylight', 8));

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'L'));

        self::assertInstanceOf(\Closure::class, $cmd);
        $msg = $cmd();
        self::assertInstanceOf(LogoutMsg::class, $msg, 'uppercase L must log out, not fall through');
    }

    public function testUppercaseSKeyEmitsOpenServersMsgLikeTheHintAdvertises(): void
    {
        $screen = SettingsScreen::create($this->makeAuthStore(), $this->makeConfig('Daylight', 8));

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'S'));

        self::assertInstanceOf(\Closure::class, $cmd);
        $msg = $cmd();
        self::assertInstanceOf(OpenServersMsg::class, $msg, 'uppercase S must open servers, not fall through');
    }

    public function testTheStatusHintAdvertisesTheUppercasePairTheHandlerAccepts(): void
    {
        $screen = SettingsScreen::create($this->makeAuthStore(), $this->makeConfig('Daylight', 8));

        $view = $screen->view();

        self::assertStringContainsString('L  logout', $view, 'the hint prints the uppercase key');
        self::assertStringContainsString('S  servers', $view, 'both cases are handled');
    }

    // [review console__a11y M-CONSOLE-05] The l/s shortcuts intercepted the
    // lowercase keys BEFORE the form, so 'l' and 's' could never be typed into
    // the slideshow interval field — a screen-level violation of the repo's own
    // capture-text law (App honors it for '/' and ':' while a screen captures
    // text). While a text field has focus, printable characters belong to it;
    // the shortcuts keep working everywhere else (the resting-focus and
    // uppercase-pair tests above pin the other side of the law).

    public function testLowercaseShortcutsDoNotInterceptWhileTheSlideshowFieldIsFocused(): void
    {
        $screen = SettingsScreen::create($this->makeAuthStore(), $this->makeConfig('Nocturne', 4));
        $screen = $this->toInterval($screen);

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'l'));
        $msg = $cmd !== null ? $cmd() : null;
        self::assertNotInstanceOf(LogoutMsg::class, $msg, "'l' while editing must type, not log out");

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 's'));
        $msg = $cmd !== null ? $cmd() : null;
        self::assertNotInstanceOf(OpenServersMsg::class, $msg, "'s' while editing must type, not open servers");
    }

    public function testShortcutLettersLandInTheSlideshowFieldValueWhileEditing(): void
    {
        $screen = SettingsScreen::create($this->makeAuthStore(), $this->makeConfig('Nocturne', 4));
        $screen = $this->toInterval($screen);
        $screen = $this->type($screen, 'ls');

        // Strip styling: the focused input renders the cursor as SGR, never as
        // inserted text, so the bare value reads '4ls'.
        $plain = (string) preg_replace('/\e\[[0-9;?]*[mH]|\e\][^\a]*\a/', '', $screen->view());

        self::assertStringContainsString('4ls', $plain, 'both shortcut letters must reach the field value');
    }

    public function testUppercaseShortcutIsTextWhileEditingAndALiveShortcutAtRest(): void
    {
        // Uppercase follows the same capture-text law: shift produces a printable
        // rune, so while the Input is focused 'L' is content, not a command. The
        // a11y critical-1 advertised-pair law (testUppercaseLKeyEmitsLogoutMsg)
        // pins that the very same key still fires at rest on the Select.
        $screen = SettingsScreen::create($this->makeAuthStore(), $this->makeConfig('Nocturne', 4));
        $screen = $this->toInterval($screen);

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'L'));
        $msg = $cmd !== null ? $cmd() : null;
        self::assertNotInstanceOf(LogoutMsg::class, $msg, "'L' while editing must type, not log out");

        $rested = SettingsScreen::create($this->makeAuthStore(), $this->makeConfig('Nocturne', 4));
        [, $cmd] = $rested->update(new KeyMsg(KeyType::Char, 'L'));
        self::assertInstanceOf(\Closure::class, $cmd);
        self::assertInstanceOf(LogoutMsg::class, $cmd(), 'at rest the advertised uppercase shortcut stays live');
    }
}
