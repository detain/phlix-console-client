<?php

declare(strict_types=1);

namespace Phlix\Console\Tests;

use Phlix\Console\Capabilities;
use PHPUnit\Framework\TestCase;

final class CapabilitiesTest extends TestCase
{
    /** The env vars the inference table reads — pinned to a known state per test. */
    private const CONTROLLED_ENV = ['TERM', 'TERM_PROGRAM', 'LC_TERMINAL', 'KITTY_WINDOW_ID', 'XTERM_VERSION', 'TMUX'];

    /** @var array<string, string|false> */
    private array $envRestore = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::CONTROLLED_ENV as $name) {
            $this->envRestore[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::CONTROLLED_ENV as $name) {
            $value = $this->envRestore[$name];
            putenv($value === false ? $name : $name . '=' . $value);
        }
        parent::tearDown();
    }

    public function testDetectReturnsAllKeys(): void
    {
        $d = (new Capabilities())->detect();

        foreach (['protocol', 'sixel', 'kitty', 'iterm2', 'halfblock', 'colorProfile', 'term', 'tmux'] as $key) {
            self::assertArrayHasKey($key, $d);
        }
        self::assertIsString($d['protocol']);
        self::assertNotSame('', $d['protocol']);
        self::assertIsBool($d['halfblock']);
    }

    public function testReportMentionsProtocolAndProtocolList(): void
    {
        $report = (new Capabilities())->report();

        self::assertStringContainsString('Render protocol', $report);
        self::assertStringContainsString('Supported image protocols', $report);
        self::assertStringContainsString('halfblock', $report);
    }

    /**
     * [review console__tuispec #1 / CU-053] The DEFAULT survey must be the
     * quiet env snapshot — the retired live probe (DA1/XTWINOPS query bytes +
     * non-canonical tty flips, CONSOLIDATED M17) may never run on a default
     * path again.
     */
    public function testDefaultReportIsTheEnvSnapshot(): void
    {
        putenv('TERM_PROGRAM=ghostty');

        $d = (new Capabilities())->detectFromEnv();
        self::assertSame('kitty', $d['protocol'], 'the env table must speak before any query would');
        self::assertTrue($d['kitty']);

        $report = (new Capabilities())->report();
        self::assertStringContainsString('Protocol source : environment', $report);
        self::assertStringNotContainsString('live DA1/XTWINOPS query', $report);
    }

    public function testLiveReportLabelsTheProbeArm(): void
    {
        $report = (new Capabilities())->report(liveProbe: true);

        // The non-tty phpunit venue keeps the live arm quiet, but the label
        // must tell the truth about which stage produced the numbers.
        self::assertStringContainsString('Protocol source : live DA1/XTWINOPS query', $report);
    }

    /**
     * @return array<string, array{array<string,string>, string, bool, bool, bool}>
     */
    public static function envTableProvider(): array
    {
        return [
            // tuples are (env, protocol, sixel, kitty, iterm2)
            'ghostty is kitty-family' => [['TERM_PROGRAM' => 'ghostty'], 'kitty', false, true, false],
            'wezterm is kitty-family' => [['TERM_PROGRAM' => 'WezTerm'], 'kitty', false, true, false],
            'kitty window id wins' => [['KITTY_WINDOW_ID' => '7'], 'kitty', false, true, false],
            'xterm-kitty term' => [['TERM' => 'xterm-kitty'], 'kitty', false, true, false],
            'iterm.app' => [['TERM_PROGRAM' => 'iTerm.app'], 'iterm2', false, false, true],
            'lc_terminal iterm2' => [['LC_TERMINAL' => 'iTerm2'], 'iterm2', false, false, true],
            'mintty speaks iterm2' => [['TERM_PROGRAM' => 'mintty'], 'iterm2', false, false, true],
            'foot-direct sixel' => [['TERM' => 'foot-direct'], 'sixel', true, false, false],
            'mlterm sixel' => [['TERM' => 'mlterm-256color'], 'sixel', true, false, false],
            'xterm with version sixel' => [['TERM' => 'xterm-256color', 'XTERM_VERSION' => 'XWindow system (version 391)'], 'sixel', true, false, false],
            'plain xterm no hints' => [['TERM' => 'xterm'], 'halfblock', false, false, false],
            'bare env falls back' => [[], 'halfblock', false, false, false],
        ];
    }

    /**
     * The snapshot table must mirror the vendor's retired probe's env rules
     * (SugarCraft\Image\Detect::probeEnv), Kitty → iTerm2 → Sixel precedence.
     *
     * @param array<string,string> $env
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('envTableProvider')]
    public function testEnvSnapshotMirrorsVendorTable(array $env, string $protocol, bool $sixel, bool $kitty, bool $iterm2): void
    {
        foreach ($env as $name => $value) {
            putenv($name . '=' . $value);
        }

        $d = (new Capabilities())->detectFromEnv();

        self::assertSame($protocol, $d['protocol']);
        self::assertSame($sixel, $d['sixel']);
        self::assertSame($kitty, $d['kitty']);
        self::assertSame($iterm2, $d['iterm2']);
        self::assertTrue($d['halfblock'], 'the half-block fallback is always available');
    }

    public function testEnvSnapshotSeesTmuxWithoutQuerying(): void
    {
        putenv('TMUX=/tmp/tmux-1000/default,123,0');
        putenv('TERM=screen');

        $d = (new Capabilities())->detectFromEnv();

        self::assertTrue($d['tmux']);
        self::assertSame('halfblock', $d['protocol'], 'tmux alone advertises no graphics protocol');
    }
}
