<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Screen\MusicScreen;
use Phlix\Console\Screen\Themed;
use Phlix\Console\Store\MusicStore;
use Phlix\Console\Tests\Api\FakeTransport;
use Phlix\Console\Ui\Theme;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the {@see \Phlix\Console\Screen\ThemedScreen} trait through a concrete
 * screen (MusicScreen — a simple, deterministic body). The two load-bearing
 * properties: (1) a Nocturne-defaulted screen renders BYTE-IDENTICALLY to a
 * screen that never knew about themes (identity), and (2) a colour theme tints
 * the brand.
 */
final class ThemedScreenTest extends TestCase
{
    private function screen(): MusicScreen
    {
        $api = new ApiClient('https://srv', new FakeTransport());

        // No init() run → the screen is in its deterministic "Loading music…" state.
        return new MusicScreen(new MusicStore($api), cols: 80, rows: 24);
    }

    public function testScreenIsThemed(): void
    {
        self::assertInstanceOf(Themed::class, $this->screen());
    }

    public function testDefaultRenderIsNocturneIdentity(): void
    {
        $screen = $this->screen();

        // The un-themed default render and an explicit Nocturne render are equal,
        // byte-for-byte, AND carry no SGR in the brand — the monochrome look is
        // unchanged by the theme system.
        $default = $screen->view();
        $nocturne = $screen->withTheme(Theme::nocturne())->view();

        self::assertSame($default, $nocturne);
        self::assertStringNotContainsString("\e[", $default, 'the default render has zero SGR');
    }

    public function testWithThemeIsAClonePreservingTheOriginal(): void
    {
        $screen = $this->screen();
        $themed = $screen->withTheme(Theme::daylight());

        self::assertNotSame($screen, $themed, 'withTheme clones');
        // The original is untouched — its render still has no SGR.
        self::assertStringNotContainsString("\e[", $screen->view());
    }

    public function testDaylightThemeColoursTheBrand(): void
    {
        $out = $this->screen()->withTheme(Theme::daylight())->view();

        self::assertStringContainsString("\e[", $out, 'a colour theme tints the chrome');
        self::assertMatchesRegularExpression('/\e\[[0-9;]*m Phlix \e\[0m/', $out, 'the brand token is colour-wrapped');
        // The visible content is unchanged once SGR is stripped.
        $stripped = preg_replace('/\e\[[0-9;]*m/', '', $out) ?? $out;
        self::assertStringContainsString('Loading music', $stripped);
    }
}
