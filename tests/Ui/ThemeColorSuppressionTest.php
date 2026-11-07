<?php

/**
 * M-CONSOLE-04 (review console__a11y item 5) — NO_COLOR and a basic TERM
 * ('dumb', unset, empty, or ncurses' 'unknown' — the CU-059 breadth) are
 * honoured by the theme's style factories: colour drops out of every emitter,
 * the structural attributes (bold, faint, reverse) stay, and Nocturne's zero-SGR
 * identity is what every suppressed surface renders as.
 *
 * The tests manipulate the process environment with putenv — the house env
 * doctrine's one true read (Theme::colorSuppressed consults exactly getenv()),
 * and every mutation is restored in tearDown so the suite never leaks state.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Ui\Theme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ThemeColorSuppressionTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $variable => $value) {
            if ($value === false) {
                putenv($variable);
            } else {
                putenv("{$variable}={$value}");
            }
        }

        $this->savedEnv = [];
    }

    private function setEnv(string $variable, ?string $value): void
    {
        if (!array_key_exists($variable, $this->savedEnv)) {
            $this->savedEnv[$variable] = getenv($variable);
        }

        if ($value === null) {
            putenv($variable);

            return;
        }

        putenv("{$variable}={$value}");
    }

    /** @return iterable<string,array{Theme}> */
    public static function colouredThemeProvider(): iterable
    {
        yield 'daylight' => [Theme::daylight()];
        yield 'midnight' => [Theme::midnight()];
    }

    // ---- clean environment: colour is emitted (positive control) ------

    #[DataProvider('colouredThemeProvider')]
    public function testWithoutSuppressionTheEmittersCarryColour(Theme $theme): void
    {
        $this->setEnv('NO_COLOR', null);
        $this->setEnv('TERM', 'xterm-256color');

        self::assertFalse(Theme::colorSuppressed());
        self::assertNotNull($theme->brandStyle()->getForeground(), 'brand keeps its accent');
        self::assertNotNull($theme->statusStyle()->getForeground(), 'status keeps its muted');
        self::assertNotNull($theme->widgetStyle(Theme::ROLE_STAR)->getForeground(), 'stars keep their gold');
        self::assertNotNull($theme->avatarStyle(0)->getBackground(), 'avatars keep their field');
    }

    // ---- NO_COLOR: presence disables ------------------------------------

    #[DataProvider('colouredThemeProvider')]
    public function testNoColorRendersChromeAsThePlainIdentity(Theme $theme): void
    {
        $this->setEnv('NO_COLOR', '1');

        self::assertTrue(Theme::colorSuppressed());
        self::assertSame(' Phlix ', $theme->brandStyle()->render(' Phlix '), 'brand: zero SGR');
        self::assertSame(' a hint', $theme->statusStyle()->render(' a hint'), 'status: zero SGR');
    }

    #[DataProvider('colouredThemeProvider')]
    public function testNoColorKeepsWidgetAttributesAndDropsColours(Theme $theme): void
    {
        $this->setEnv('NO_COLOR', '1');

        $star = $theme->widgetStyle(Theme::ROLE_STAR);
        self::assertTrue($star->isBold(), 'emphasis survives suppression');
        self::assertNull($star->getForeground(), 'gold drops out');

        $cursor = $theme->widgetStyle(Theme::ROLE_TITLE, reversed: true);
        self::assertTrue($cursor->isReverse(), 'selection stays visible without colour');

        $avatar = $theme->avatarStyle(2);
        self::assertTrue($avatar->isFaint(), 'the dimmed-initials attribute survives');
        self::assertNull($avatar->getBackground(), 'the coloured field drops out');
    }

    public function testAnyNonEmptyNoColorValueDisablesIncludingZero(): void
    {
        $this->setEnv('NO_COLOR', '0');

        self::assertTrue(
            Theme::colorSuppressed(),
            'no-color.org: the VALUE is irrelevant — a literal "0" still disables'
        );
    }

    public function testEmptyNoColorValueDoesNotDisable(): void
    {
        $this->setEnv('NO_COLOR', '');
        $this->setEnv('TERM', 'xterm-256color');

        self::assertFalse(
            Theme::colorSuppressed(),
            'an empty NO_COLOR is the spec\'s explicit no-op'
        );
    }

    // ---- TERM=dumb: the classic line-mode terminal --------------------

    public function testDumbTermSuppressesColour(): void
    {
        $this->setEnv('NO_COLOR', null);
        $this->setEnv('TERM', 'dumb');

        self::assertTrue(Theme::colorSuppressed());

        $theme = Theme::midnight();
        self::assertNull($theme->brandStyle()->getForeground());
        self::assertNull($theme->widgetStyle(Theme::ROLE_DANGER)->getForeground());
    }

    // ---- basic-TERM breadth [review console__a11y #6 / CU-059] ---------
    //
    // 'dumb' was the only literal the arm honoured. A shell that never
    // advertises a terminal type — TERM unset or empty, common in minimal
    // containers and some tmux/screen misconfigs — or advertises ncurses'
    // own fallback name 'unknown' is exactly as line-mode as 'dumb', yet
    // used to receive the full truecolor SGR stream. The ncurses convention
    // treats an unset TERM as the most basic terminal; so does the client.

    public function testUnsetTermSuppressesColour(): void
    {
        $this->setEnv('NO_COLOR', null);
        $this->setEnv('TERM', null);

        self::assertTrue(
            Theme::colorSuppressed(),
            'TERM unset means "no terminal type advertised" — assume basic'
        );
    }

    public function testEmptyTermSuppressesColour(): void
    {
        $this->setEnv('NO_COLOR', null);
        $this->setEnv('TERM', '');

        self::assertTrue(Theme::colorSuppressed(), 'TERM= is as uninformative as TERM unset');
    }

    public function testUnknownTermSuppressesColour(): void
    {
        $this->setEnv('NO_COLOR', null);
        $this->setEnv('TERM', 'unknown');

        self::assertTrue(
            Theme::colorSuppressed(),
            "ncurses' own fallback name is a basic-terminal signal"
        );
    }

    public function testOrdinaryTermsDoNotTriggerTheDumbArm(): void
    {
        $this->setEnv('NO_COLOR', null);

        foreach (['xterm-256color', 'screen-256color', 'alacritty', 'dumb-ish'] as $term) {
            $this->setEnv('TERM', $term);
            self::assertFalse(Theme::colorSuppressed(), "TERM={$term} must keep colour");
        }
    }

    // ---- Nocturne stays the identity under suppression too -------------

    public function testNocturneIsUnaffectedBecauseItNeverCarriedColour(): void
    {
        $this->setEnv('NO_COLOR', '1');

        self::assertSame(' Phlix ', Theme::nocturne()->brandStyle()->render(' Phlix '));
    }
}
