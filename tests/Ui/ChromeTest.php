<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Ui\Chrome;
use PHPUnit\Framework\TestCase;

final class ChromeTest extends TestCase
{
    public function testFrameWithoutATrailShowsTheBareTitle(): void
    {
        $out = Chrome::frame('Movies', 'the body', 'a hint', 80, 24);

        self::assertStringContainsString('Movies', $out);
        self::assertStringContainsString('the body', $out);
        self::assertStringNotContainsString('›', $out, 'no breadcrumb separator without a trail');
    }

    public function testFrameWithATrailRendersTheBreadcrumb(): void
    {
        $out = Chrome::frame('The Matrix', 'body', 'hint', 100, 24, ['Home', 'Movies', 'The Matrix']);

        self::assertStringContainsString('Home', $out);
        self::assertStringContainsString('Movies', $out);
        self::assertStringContainsString('The Matrix', $out);
        self::assertStringContainsString('›', $out, 'breadcrumb separators are present');
    }

    public function testADeepTrailTruncatesFromTheLeftKeepingTheCurrentCrumb(): void
    {
        $trail = ['Home', 'Movies', 'Very Long Series Title', 'Season 10', 'S10E24 A Long Episode Title'];

        // A narrow terminal can't fit the whole path; the deepest crumb must stay.
        $out = Chrome::frame('S10E24', 'body', 'hint', 48, 24, $trail);

        self::assertStringContainsString('S10E24', $out, 'the current location stays visible');
    }

    public function testAnEmptyTrailIsTreatedAsNoTrail(): void
    {
        $out = Chrome::frame('Login', 'body', 'hint', 80, 24, []);

        self::assertStringContainsString('Login', $out);
        self::assertStringNotContainsString('›', $out);
    }
}
