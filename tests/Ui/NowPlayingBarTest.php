<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Api\Dto\Audiobook;
use Phlix\Console\Api\Dto\AudiobookChapter;
use Phlix\Console\Audio\AudiobookSession;
use Phlix\Console\Audio\MusicSession;
use Phlix\Console\Tests\Reel\FakeAudioPlayer;
use Phlix\Console\Ui\NowPlayingBar;
use Phlix\Console\Ui\Theme;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;

final class NowPlayingBarTest extends TestCase
{
    private function album(?string $artist = 'The Beatles', ?int $duration = 259): Album
    {
        return Album::fromArray([
            'name' => 'Abbey Road',
            'artist' => $artist,
            'year' => 1969,
            'track_count' => 1,
            'tracks' => [
                ['id' => 't1', 'metadata' => ['title' => 'Come Together', 'track_number' => 1, 'duration_secs' => $duration]],
            ],
        ]);
    }

    private function nowPlaying(bool $paused = false, int $position = 0, ?Album $album = null): MusicSession
    {
        return new MusicSession(new FakeAudioPlayer('u'), $album ?? $this->album(), 0, $paused, $position, 1);
    }

    /** An audiobook session for the interface-render tests. */
    private function audiobookSession(bool $paused = false, int $positionMs = 0): AudiobookSession
    {
        $book = Audiobook::fromArray([
            'id' => 'ab1',
            'title' => 'Dune',
            'author' => 'Frank Herbert',
            'narrator' => 'Scott Brick',
            'duration_ms' => 7_200_000, // 2:00:00
            'stream_url' => 'https://srv/s/ab1',
        ]);
        $chapters = [
            AudiobookChapter::fromArray(['index' => 0, 'title' => 'Beginnings', 'start_ms' => 0, 'end_ms' => 3_600_000, 'duration_ms' => 3_600_000], 0),
            AudiobookChapter::fromArray(['index' => 1, 'title' => 'The Spice', 'start_ms' => 3_600_000, 'end_ms' => 7_200_000, 'duration_ms' => 3_600_000], 1),
        ];

        return new AudiobookSession(new FakeAudioPlayer('u'), $book, $chapters, $positionMs, $paused, 1);
    }

    public function testRendersGlyphTitleSubtitleAndClock(): void
    {
        $bar = NowPlayingBar::render($this->nowPlaying(position: 83), 80);

        self::assertStringContainsString('▶', $bar, 'playing shows the play glyph');
        self::assertStringContainsString('Come Together', $bar);
        self::assertStringContainsString('Abbey Road · The Beatles', $bar);
        self::assertStringContainsString('1:23 / 4:19', $bar, '83s elapsed of a 259s track');
    }

    public function testPausedShowsThePauseGlyph(): void
    {
        $bar = NowPlayingBar::render($this->nowPlaying(paused: true), 80);

        self::assertStringContainsString('⏸', $bar);
        self::assertStringNotContainsString('▶', $bar);
    }

    public function testUnknownDurationRendersADash(): void
    {
        $bar = NowPlayingBar::render($this->nowPlaying(position: 5, album: $this->album(duration: null)), 80);

        self::assertStringContainsString('0:05 / —', $bar);
    }

    public function testHourLongPositionsUseHmmss(): void
    {
        $album = $this->album(duration: 7200); // 2:00:00
        $bar = NowPlayingBar::render($this->nowPlaying(position: 3661, album: $album), 100);

        self::assertStringContainsString('1:01:01 / 2:00:00', $bar);
    }

    /**
     * @param int<1, max> $width
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('widths')]
    public function testTheBarIsExactlyTheRequestedWidth(int $width): void
    {
        $bar = NowPlayingBar::render($this->nowPlaying(position: 83), $width);

        self::assertSame($width, Width::string($bar), "the bar fills exactly {$width} cells");
    }

    /** @return iterable<string, array{int}> */
    public static function widths(): iterable
    {
        yield 'narrow' => [20];
        yield 'tight (clock barely fits)' => [14];
        yield 'very narrow (clock dropped)' => [6];
        yield 'standard' => [80];
        yield 'wide' => [200];
    }

    public function testALongTitleIsTruncatedButTheClockSurvives(): void
    {
        $album = Album::fromArray([
            'name' => 'A Very Long Album Name That Goes On And On And On Forever',
            'artist' => 'Some Extremely Verbose Artist Name Indeed',
            'tracks' => [['id' => 't1', 'metadata' => ['title' => 'An Equally Long Track Title For Good Measure', 'duration_secs' => 259]]],
        ]);
        $bar = NowPlayingBar::render($this->nowPlaying(position: 83, album: $album), 40);

        self::assertSame(40, Width::string($bar));
        self::assertStringContainsString('1:23 / 4:19', $bar, 'the right-aligned clock is never truncated away');
    }

    public function testNocturneRendersNoSgr(): void
    {
        // The identity theme tints nothing — the bar carries zero escape sequences.
        $bar = NowPlayingBar::render($this->nowPlaying(position: 83), 80, Theme::nocturne());

        self::assertStringNotContainsString("\e[", $bar, 'Nocturne is a plain (no-SGR) bar');
        self::assertSame(80, Width::string($bar));
    }

    public function testDefaultThemeIsNocturneIdentity(): void
    {
        // Omitting the theme is identical to passing Nocturne.
        $np = $this->nowPlaying(position: 83);

        self::assertSame(
            NowPlayingBar::render($np, 80, Theme::nocturne()),
            NowPlayingBar::render($np, 80),
        );
    }

    public function testAColouredThemeTintsTheGlyphAndTitle(): void
    {
        // Under a non-Nocturne theme the brand accent wraps the ▶ + title.
        $bar = NowPlayingBar::render($this->nowPlaying(position: 83), 80, Theme::midnight());

        self::assertMatchesRegularExpression('/\e\[[0-9;]*m▶ Come Together\e\[0m/', $bar, 'the glyph + title are accent-wrapped');
        self::assertSame(80, Width::string($bar), 'the SGR does not count toward the cell width');
    }

    // ---- audiobook sessions render via the same interface --------------

    public function testRendersAnAudiobookSessionViaTheInterface(): void
    {
        // 1h30m into a 2h book → inside chapter 1 ("The Spice"); the subtitle is
        // author · narrator and the clock is the ms position / total.
        $bar = NowPlayingBar::render($this->audiobookSession(positionMs: 5_400_000), 80);

        self::assertStringContainsString('▶ The Spice', $bar, 'the current chapter title shows');
        self::assertStringContainsString('Frank Herbert · Scott Brick', $bar);
        self::assertStringContainsString('1:30:00 / 2:00:00', $bar, 'the ms clock for an audiobook');
    }

    public function testAPausedAudiobookShowsThePauseGlyph(): void
    {
        $bar = NowPlayingBar::render($this->audiobookSession(paused: true, positionMs: 0), 80);

        self::assertStringContainsString('⏸ Beginnings', $bar);
        self::assertStringNotContainsString('▶', $bar);
    }

    public function testAnAudiobookBarIsExactlyTheRequestedWidth(): void
    {
        $bar = NowPlayingBar::render($this->audiobookSession(positionMs: 5_400_000), 80);

        self::assertSame(80, Width::string($bar));
    }
}
