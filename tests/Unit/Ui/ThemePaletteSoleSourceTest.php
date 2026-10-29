<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Ui;

use InvalidArgumentException;
use Phlix\Console\Ui\RatingBadge;
use Phlix\Console\Ui\RecommendationCard;
use Phlix\Console\Ui\Theme;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * M-CONSOLE-48 — the Theme palette is the SOLE colour source in src/.
 *
 * Four widgets used to hardcode truecolor hexes (`#ffffff` card titles
 * included), rendering identically under every theme and illegibly on light
 * terminals, bypassing both the Theme token system and the resolveProfile()
 * capability path. The cure routes every widget colour through
 * {@see Theme::widgetStyle()} / {@see Theme::avatarStyle()}; this pin keeps
 * the bypass class permanently dead:
 *
 *  1. CENSUS (red-on-parent: 14 hits at 65a31fa): no `#rrggbb` colour literal
 *     and no raw `38;2` truecolor sequence anywhere in src/ outside
 *     src/Ui/Theme.php — the palette tables are the single legal home.
 *  2. NOCTURNE GOLDENS: routing must not restyle the default theme. Every
 *     escape below was captured VERBATIM off the pre-fix tree (php -r against
 *     65a31fa) — bold-before-colour emission order, SGR `1;7` cursor merge,
 *     faint-before-bg avatar chip, byte for byte.
 *  3. DAYLIGHT LEGIBILITY: none of the five illegible-on-light emissions
 *     (white title, gold star, dark-blue badge, salmon danger, saturated
 *     avatar field) may appear under the light-terminal theme.
 *  4. TOTAL COVERAGE + FAIL-LOUD: every preset resolves every role (both
 *     attribute modes) and the whole avatar ramp; an unknown role, a
 *     palette-less theme id, or a negative slot throws at the boundary.
 */
final class ThemePaletteSoleSourceTest extends TestCase
{
    /** The palette home — the only src/ file allowed to carry colour hexes. */
    private const ALLOWLIST = 'src/Ui/Theme.php';

    // ---- 1. census --------------------------------------------------------

    public function testNoColourHexLiteralExistsInSrcOutsideTheThemePalette(): void
    {
        $root = dirname(__DIR__, 3) . '/src';
        $offenders = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace(dirname(__DIR__, 3) . '/', '', $file->getPathname());
            if ($relative === self::ALLOWLIST) {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());

            // Colour literals reach the widgets in every shape the parent
            // tree proved: inline fg('#ffcc00'), and array palettes assigned
            // later into ->bg($color) (the DetailScreen avatar form). The
            // literal census catches both; a raw-escape scan catches anyone
            // writing 38;2 by hand.
            if (preg_match_all('/([\'"])#(?:[0-9a-fA-F]{6}|[0-9a-fA-F]{3})\1/', $code, $m) === false) {
                self::fail('preg failure on ' . $relative);
            }
            foreach ($m[0] as $hit) {
                $offenders[] = $relative . ': ' . $hit;
            }
            if (str_contains($code, '38;2')) {
                $offenders[] = $relative . ': raw 38;2 truecolor sequence';
            }
        }

        self::assertSame([], $offenders, implode("\n", $offenders));
    }

    // ---- 2. nocturne byte-goldens (captured verbatim off 65a31fa) ---------

    public function testNocturneStarBadgeAndTitleEmitTheHistoricalBytes(): void
    {
        $nocturne = Theme::nocturne();

        self::assertSame("\e[1m\e[38;2;255;204;0mQ\e[0m", $nocturne->widgetStyle(Theme::ROLE_STAR)->render('Q'));
        self::assertSame(
            "\e[1;7m\e[38;2;255;204;0mQ\e[0m",
            $nocturne->widgetStyle(Theme::ROLE_STAR, reversed: true)->render('Q')
        );
        self::assertSame("\e[1m\e[38;2;0;102;204mQ\e[0m", $nocturne->widgetStyle(Theme::ROLE_BADGE)->render('Q'));
        self::assertSame("\e[38;2;255;255;255mQ\e[0m", $nocturne->widgetStyle(Theme::ROLE_TITLE)->render('Q'));
        self::assertSame("\e[1m\e[38;2;255;102;102mQ\e[0m", $nocturne->widgetStyle(Theme::ROLE_DANGER)->render('Q'));
        self::assertSame(
            "\e[1;7m\e[38;2;255;102;102mQ\e[0m",
            $nocturne->widgetStyle(Theme::ROLE_DANGER, reversed: true)->render('Q')
        );
    }

    public function testNocturneAvatarChipKeepsTheFaintBeforeBackgroundEmissionAndRampOrder(): void
    {
        $nocturne = Theme::nocturne();

        // Parent shape was Style::new()->faint()->bg('#ff0000'):
        self::assertSame("\e[2m\e[48;2;255;0;0m AB \e[0m", $nocturne->avatarStyle(0)->render(' AB '));
        // crc32 slotting wrapped over the historical six-hue order:
        $expected = [
            '48;2;255;0;0m',
            '48;2;0;255;0m',
            '48;2;255;255;0m',
            '48;2;0;0;255m',
            '48;2;255;0;255m',
            '48;2;0;255;255m',
        ];
        foreach ($expected as $slot => $sgr) {
            self::assertStringContainsString("\e[" . $sgr, $nocturne->avatarStyle($slot)->render('X'));
            self::assertStringContainsString(
                "\e[" . $sgr,
                $nocturne->avatarStyle($slot + 12)->render('X'),
                'ramp wraps over six hues'
            );
        }
    }

    public function testNocturneRecommendationCardIsByteIdenticalToThePreThemeTree(): void
    {
        $card = RecommendationCard::fromArray(['id' => 'x', 'title' => 'The Matrix', 'year' => 1999, 'score' => 0.87]);

        self::assertSame(
            "\e[1m\e[38;2;255;204;0m★ \e[0m"
            . "\e[38;2;255;255;255mThe Matrix                                        \e[0m\n"
            . "  \e[2m1999\e[0m"
            . "  \e[1m\e[38;2;0;102;204mBecause You Watched\e[0m"
            . "  \e[1m\e[38;2;255;204;0m87% match\e[0m",
            $card->render(Theme::nocturne())
        );
    }

    public function testNocturneRatingBadgeIsByteIdenticalToThePreThemeTree(): void
    {
        self::assertSame(
            "\e[1m\e[38;2;255;204;0m★★★\e[0m\e[1m\e[38;2;255;204;0m½\e[0m\e[2m☆\e[0m  7.5/10",
            (new RatingBadge(7.5))->render(Theme::nocturne())
        );
    }

    // ---- 3. daylight legibility --------------------------------------------

    public function testDaylightEmitsNoneOfTheHexesThatAreIllegibleOnLightTerminals(): void
    {
        $daylight = Theme::daylight();
        $forbidden = ['38;2;255;255;255', '38;2;255;204;0', '38;2;0;102;204', '38;2;255;102;102', '48;2;255;255;0'];

        foreach ([Theme::ROLE_STAR, Theme::ROLE_BADGE, Theme::ROLE_TITLE, Theme::ROLE_DANGER] as $role) {
            foreach ([false, true] as $reversed) {
                $rendered = $daylight->widgetStyle($role, reversed: $reversed)->render('X');
                foreach ($forbidden as $sgr) {
                    self::assertStringNotContainsString("\e[" . $sgr, $rendered, "daylight {$role} still emits {$sgr}");
                }
            }
        }
        for ($slot = 0; $slot < 6; $slot++) {
            $rendered = $daylight->avatarStyle($slot)->render('AB');
            $darkField = [
                '48;2;255;0;0',
                '48;2;0;255;0',
                '48;2;255;255;0',
                '48;2;0;0;255',
                '48;2;255;0;255',
                '48;2;0;255;255',
            ];
            foreach ($darkField as $sgr) {
                self::assertStringNotContainsString(
                    "\e[" . $sgr,
                    $rendered,
                    "daylight avatar {$slot} keeps the dark-field field colour"
                );
            }
        }
    }

    public function testDaylightTitleHonestlyDegradesToTheTerminalDefault(): void
    {
        // No hex at all under a null role — the text keeps its field contrast
        // by carrying no SGR whatsoever.
        self::assertSame('Title', Theme::daylight()->widgetStyle(Theme::ROLE_TITLE)->render('Title'));
    }

    // ---- 4. coverage + fail-loud --------------------------------------------

    public function testEveryPresetResolvesEveryRoleAndEveryAvatarSlot(): void
    {
        foreach (Theme::all() as $theme) {
            foreach ([Theme::ROLE_STAR, Theme::ROLE_BADGE, Theme::ROLE_TITLE, Theme::ROLE_DANGER] as $role) {
                foreach ([false, true] as $reversed) {
                    self::assertIsString(
                        $theme->widgetStyle($role, reversed: $reversed)->render('X'),
                        "{$theme->id}/{$role}"
                    );
                }
            }
            for ($slot = 0; $slot < 12; $slot++) {
                self::assertIsString($theme->avatarStyle($slot)->render('AB'), "{$theme->id}/avatar-{$slot}");
            }
        }
    }

    public function testUnknownRoleUnknownPaletteAndNegativeSlotFailLoud(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown widget colour role \'sparkle\'');
        Theme::nocturne()->widgetStyle('sparkle');
    }

    public function testThemeWithoutAPaletteFailsLoudRatherThanGuessing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Theme 'custom' has no widget palette");
        (new Theme('Custom', 'custom', '#abcdef', '#123456'))->widgetStyle(Theme::ROLE_STAR);
    }

    public function testNegativeAvatarSlotFailsLoud(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Avatar slot -1 must be non-negative');
        Theme::nocturne()->avatarStyle(-1);
    }
}
