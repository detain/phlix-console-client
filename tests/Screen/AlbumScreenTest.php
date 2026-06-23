<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Msg\AudioSkipMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\PlayTrackMsg;
use Phlix\Console\Msg\ToggleAudioMsg;
use Phlix\Console\Screen\AlbumScreen;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;

/**
 * The AlbumScreen is now a PURE LIST: it owns no playback (the App owns the music
 * audio). These tests assert the list behaviour + the play/control Msgs it emits;
 * the audio lifecycle (resolve, tick, pause, auto-advance, teardown, persistence)
 * is exercised in {@see \Phlix\Console\Tests\AppTest}, where the audio now lives.
 */
final class AlbumScreenTest extends TestCase
{
    /** An album with two tracks (raw album-track rows: fields under `metadata`). */
    private function album(): Album
    {
        return Album::fromArray([
            'name' => 'Abbey Road',
            'artist' => 'The Beatles',
            'year' => 1969,
            'track_count' => 2,
            'tracks' => [
                ['id' => 't1', 'metadata' => ['title' => 'Come Together', 'track_number' => 1, 'duration_secs' => 259]],
                ['id' => 't2', 'metadata' => ['title' => 'Something', 'track_number' => 2, 'duration_secs' => 182]],
            ],
        ]);
    }

    private function screen(?Album $album = null): AlbumScreen
    {
        return new AlbumScreen($album ?? $this->album(), cols: 120, rows: 40);
    }

    // ---- list rendering ------------------------------------------------

    public function testInitDoesNotFetch(): void
    {
        // The album carries its tracks, so there is nothing to load.
        self::assertNull($this->screen()->init());
    }

    public function testRendersMetaHeaderAndTrackRows(): void
    {
        $view = $this->screen()->view();

        // Title bar shows the album name; the meta line shows artist · year · count.
        self::assertStringContainsString('Abbey Road', $view);
        self::assertStringContainsString('The Beatles', $view);
        self::assertStringContainsString('1969', $view);
        self::assertStringContainsString('2 tracks', $view);

        // The track table: headers + each track's title and human duration.
        self::assertStringContainsString('Title', $view);
        self::assertStringContainsString('Duration', $view);
        self::assertStringContainsString('Come Together', $view);
        self::assertStringContainsString('Something', $view);
        self::assertStringContainsString('4:19', $view, '259s → 4:19');
        self::assertStringContainsString('3:02', $view, '182s → 3:02');
    }

    public function testTheSelectedTrackRowRendersReverseVideo(): void
    {
        // Selection is real ANSI reverse-video (sugar-table), not a plain cursor.
        // The first track is selected, so its row — and only its row — is reversed.
        $screen = $this->screen();

        self::assertTrue(self::hasReverse($this->lineContaining($screen->view(), 'Come Together')), 'the selected track row is reversed');
        self::assertFalse(self::hasReverse($this->lineContaining($screen->view(), 'Something')), 'the unselected row is not reversed');

        // Move down: the highlight follows the selection to the second track.
        [$down] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertTrue(self::hasReverse($this->lineContaining($down->view(), 'Something')));
        self::assertFalse(self::hasReverse($this->lineContaining($down->view(), 'Come Together')));
    }

    /** True if a rendered line carries the SGR reverse attribute (7), however encoded. */
    private static function hasReverse(string $line): bool
    {
        return preg_match('/\e\[(?:[0-9;]*;)?7(?:;[0-9;]*)?m/', $line) === 1;
    }

    private function lineContaining(string $view, string $needle): string
    {
        foreach (explode("\n", $view) as $line) {
            if (str_contains($line, $needle)) {
                return $line;
            }
        }
        self::fail("no line contains [{$needle}]");
    }

    public function testMetaHeaderOmitsNullArtistAndYear(): void
    {
        $album = Album::fromArray([
            'name' => 'Untitled',
            'artist' => null,
            'year' => null,
            'tracks' => [['id' => 't1', 'metadata' => ['title' => 'One']]],
        ]);
        $view = $this->screen($album)->view();

        self::assertStringContainsString('1 track', $view);
        self::assertStringNotContainsString('  ·  ·  ', $view, 'no empty meta segments for null parts');
    }

    public function testTrackNumberFallsBackToRowOrdinal(): void
    {
        // Tracks with no track_number get their 1-based position instead.
        $album = Album::fromArray([
            'name' => 'Mix',
            'tracks' => [
                ['id' => 'a', 'metadata' => ['title' => 'First']],
                ['id' => 'b', 'metadata' => ['title' => 'Second']],
            ],
        ]);
        $screen = $this->screen($album);

        self::assertNull($screen->selectedTrack()?->trackNumber, 'no explicit track number');
        $view = $screen->view();
        self::assertStringContainsString('First', $view);
        self::assertStringContainsString('Second', $view);
    }

    public function testAlbumAccessorReturnsTheAlbum(): void
    {
        $screen = $this->screen();

        self::assertSame('Abbey Road', $screen->album()->name);
        self::assertCount(2, $screen->album()->tracks);
    }

    public function testResizeStillRenders(): void
    {
        // A small window may not have room for both the header and the full table,
        // but the screen must re-flow and render without error.
        [$small] = $this->screen()->update(new WindowSizeMsg(60, 20));
        self::assertIsString($small->view());

        // At a comfortable size the track rows are visible after the re-flow.
        [$big] = $this->screen()->update(new WindowSizeMsg(100, 40));
        self::assertStringContainsString('Come Together', $big->view());
    }

    public function testBreadcrumbLabelIsTheAlbumName(): void
    {
        $screen = $this->screen();
        self::assertSame('Abbey Road', $screen->crumbLabel());

        $withCrumbs = $screen->withCrumbs(['Home', 'Music', 'Abbey Road']);
        self::assertStringContainsString('Home', $withCrumbs->view());
        self::assertStringContainsString('Music', $withCrumbs->view());
        self::assertStringContainsString('›', $withCrumbs->view());
    }

    // ---- selection -----------------------------------------------------

    public function testDownAndUpMoveTheSelectionAndClamp(): void
    {
        $screen = $this->screen();
        self::assertSame(0, $screen->selectedIndex());

        [$down] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $down->selectedIndex());
        self::assertSame('Something', $down->selectedTrack()?->title);

        [$clamped, $cmd] = $down->update(new KeyMsg(KeyType::Down));
        self::assertSame($down, $clamped, 'clamped at the last track');
        self::assertNull($cmd);

        [$up] = $down->update(new KeyMsg(KeyType::Up));
        self::assertSame(0, $up->selectedIndex());
        [$topClamped] = $up->update(new KeyMsg(KeyType::Up));
        self::assertSame($up, $topClamped, 'clamped at the first track');
    }

    public function testArrowsOnAnEmptyAlbumAreNoOps(): void
    {
        $empty = $this->screen(Album::fromArray(['name' => 'Empty', 'tracks' => []]));

        [$down, $downCmd] = $empty->update(new KeyMsg(KeyType::Down));
        [$up, $upCmd] = $empty->update(new KeyMsg(KeyType::Up));

        self::assertSame($empty, $down);
        self::assertSame($empty, $up);
        self::assertNull($downCmd);
        self::assertNull($upCmd);
    }

    public function testUnhandledKeyIsANoOp(): void
    {
        $screen = $this->screen();

        [$same, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'z'));

        self::assertSame($screen, $same);
        self::assertNull($cmd);
    }

    public function testUnhandledMessageIsANoOp(): void
    {
        // A non-key Msg the album screen doesn't handle is ignored.
        $screen = $this->screen();

        [$same, $cmd] = $screen->update(new \Phlix\Console\Msg\OpenSearchMsg());

        self::assertSame($screen, $same);
        self::assertNull($cmd);
    }

    // ---- emitted play / control Msgs -----------------------------------

    public function testEnterEmitsPlayTrackForTheSelectedTrack(): void
    {
        $screen = $this->screen();
        [$down] = $screen->update(new KeyMsg(KeyType::Down)); // select track index 1

        [$same, $cmd] = $down->update(new KeyMsg(KeyType::Enter));

        self::assertSame($down, $same, 'the screen is unchanged — it only emits a Msg');
        $msg = $this->msgOf($cmd);
        self::assertInstanceOf(PlayTrackMsg::class, $msg);
        self::assertSame(1, $msg->index, 'it carries the selected index');
        self::assertSame('Abbey Road', $msg->album->name, 'it carries the whole album');
    }

    public function testEnterOnAnEmptyAlbumIsANoOp(): void
    {
        $empty = $this->screen(Album::fromArray(['name' => 'Empty', 'tracks' => []]));

        [$same, $cmd] = $empty->update(new KeyMsg(KeyType::Enter));

        self::assertSame($empty, $same);
        self::assertNull($cmd, 'no tracks → nothing to play');
        self::assertStringContainsString('No tracks', $empty->view());
    }

    public function testSpaceEmitsToggleAudio(): void
    {
        [$same, $cmd] = $this->screen()->update(new KeyMsg(KeyType::Char, ' '));

        self::assertInstanceOf(AlbumScreen::class, $same);
        self::assertInstanceOf(ToggleAudioMsg::class, $this->msgOf($cmd));
    }

    public function testNEmitsAudioSkipForward(): void
    {
        [, $cmd] = $this->screen()->update(new KeyMsg(KeyType::Char, 'n'));

        $msg = $this->msgOf($cmd);
        self::assertInstanceOf(AudioSkipMsg::class, $msg);
        self::assertSame(1, $msg->delta);
    }

    public function testPEmitsAudioSkipBackward(): void
    {
        [, $cmd] = $this->screen()->update(new KeyMsg(KeyType::Char, 'p'));

        $msg = $this->msgOf($cmd);
        self::assertInstanceOf(AudioSkipMsg::class, $msg);
        self::assertSame(-1, $msg->delta);
    }

    public function testEscapeNavigatesBack(): void
    {
        [$same, $cmd] = $this->screen()->update(new KeyMsg(KeyType::Escape));

        self::assertInstanceOf(AlbumScreen::class, $same);
        self::assertInstanceOf(NavigateBackMsg::class, $this->msgOf($cmd));
    }

    public function testQAlsoNavigatesBack(): void
    {
        [, $cmd] = $this->screen()->update(new KeyMsg(KeyType::Char, 'q'));

        self::assertInstanceOf(NavigateBackMsg::class, $this->msgOf($cmd));
    }

    /** Run a (synchronous) Cmd and return the Msg it produces. */
    private function msgOf(?\Closure $cmd): ?Msg
    {
        if ($cmd === null) {
            return null;
        }
        $result = $cmd();

        return $result instanceof Msg ? $result : null;
    }
}
