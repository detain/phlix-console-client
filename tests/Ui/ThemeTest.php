<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Ui\Theme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;

final class ThemeTest extends TestCase
{
    /** Strip SGR colour escapes so the visible text can be compared. */
    private static function stripAnsi(string $s): string
    {
        return Ansi::strip($s);
    }

    // ---- Nocturne is the identity (zero SGR) ---------------------------

    public function testNocturneBrandStyleRendersTextUnchanged(): void
    {
        $out = Theme::nocturne()->brandStyle()->render(' Phlix ');

        self::assertSame(' Phlix ', $out, 'Nocturne adds no SGR to the brand');
        self::assertStringNotContainsString("\e[", $out);
    }

    public function testNocturneStatusStyleRendersTextUnchanged(): void
    {
        $out = Theme::nocturne()->statusStyle()->render(' a hint');

        self::assertSame(' a hint', $out, 'Nocturne adds no SGR to the status');
        self::assertStringNotContainsString("\e[", $out);
    }

    public function testNocturneCarriesNoColourTokens(): void
    {
        $nocturne = Theme::nocturne();

        self::assertSame('Nocturne', $nocturne->name);
        self::assertNull($nocturne->accent);
        self::assertNull($nocturne->muted);
    }

    // ---- coloured themes emit SGR but preserve the text ----------------

    /** @return iterable<string,array{Theme}> */
    public static function colouredThemeProvider(): iterable
    {
        yield 'daylight' => [Theme::daylight()];
        yield 'midnight' => [Theme::midnight()];
    }

    #[DataProvider('colouredThemeProvider')]
    public function testColouredBrandStyleEmitsSgrAroundTheText(Theme $theme): void
    {
        $out = $theme->brandStyle()->render(' Phlix ');

        self::assertStringContainsString("\e[", $out, 'a coloured accent emits an SGR escape');
        self::assertSame(' Phlix ', self::stripAnsi($out), 'the brand text survives once the SGR is stripped');
    }

    #[DataProvider('colouredThemeProvider')]
    public function testColouredStatusStyleEmitsSgrAroundTheText(Theme $theme): void
    {
        $out = $theme->statusStyle()->render(' a hint');

        self::assertStringContainsString("\e[", $out);
        self::assertSame(' a hint', self::stripAnsi($out));
    }

    #[DataProvider('colouredThemeProvider')]
    public function testColouredThemesCarryBothColourTokens(Theme $theme): void
    {
        self::assertNotNull($theme->accent);
        self::assertNotNull($theme->muted);
    }

    // ---- registry --------------------------------------------------------

    public function testAllReturnsTheThreePresetsInPickerOrder(): void
    {
        $all = Theme::all();

        self::assertCount(3, $all);
        self::assertSame(['Nocturne', 'Daylight', 'Midnight'], array_map(static fn (Theme $t): string => $t->name, $all));
    }

    // ---- wire id (the server law) ----------------------------------------

    public function testTheWireIdsAreExactlyTheServerCanonicalList(): void
    {
        // phlix-server's BuiltInThemes::IDS — PUT /api/v1/users/me/settings
        // validates theme against these exact lowercase strings (or an enabled
        // plugin theme slug); a display name answers 400. If the server ever
        // renames an id, this pin is the client's alert to move with it.
        self::assertSame(
            ['nocturne', 'daylight', 'midnight'],
            array_map(static fn (Theme $t): string => $t->id, Theme::all()),
        );
    }

    public function testTheWireIdIsAlwaysTheLowercaseDisplayName(): void
    {
        // The picker renders names; the wire carries ids. Both must exist and
        // differ — name stays capitalised (UI untouched), id is pure lowercase.
        foreach (Theme::all() as $theme) {
            self::assertSame(strtolower($theme->name), $theme->id);
            self::assertNotSame($theme->name, $theme->id, 'display names keep their capitalisation');
        }
    }

    /** @return iterable<string,array{string,string}> */
    public static function byNameProvider(): iterable
    {
        yield 'exact nocturne'  => ['Nocturne', 'Nocturne'];
        yield 'exact daylight'  => ['Daylight', 'Daylight'];
        yield 'exact midnight'  => ['Midnight', 'Midnight'];
        yield 'lowercase'       => ['midnight', 'Midnight'];
        yield 'wire id nocturne' => ['nocturne', 'Nocturne'];
        yield 'wire id daylight' => ['daylight', 'Daylight'];
        yield 'uppercase'       => ['DAYLIGHT', 'Daylight'];
        yield 'mixed + spaces'  => ['  NoCtUrNe  ', 'Nocturne'];
        yield 'unknown→nocturne' => ['does-not-exist', 'Nocturne'];
        yield 'fossil phlix-dark→nocturne' => ['phlix-dark', 'Nocturne'];
        yield 'empty→nocturne'  => ['', 'Nocturne'];
    }

    #[DataProvider('byNameProvider')]
    public function testByNameIsCaseInsensitiveAndFallsBackToNocturne(string $input, string $expectedName): void
    {
        self::assertSame($expectedName, Theme::byName($input)->name);
    }

    public function testPresetsAreReadonlyValueObjects(): void
    {
        // A second call returns an equal preset (the factories are pure).
        self::assertEquals(Theme::midnight(), Theme::midnight());
        self::assertSame('#5fafff', Theme::midnight()->accent);
        self::assertSame('#005f87', Theme::daylight()->accent);
    }
}
