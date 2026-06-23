<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Screen\AlbumScreen;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Toast\ToastType;

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

    private function screen(): AlbumScreen
    {
        return new AlbumScreen($this->album(), cols: 120, rows: 40);
    }

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

    public function testMetaHeaderOmitsNullArtistAndYear(): void
    {
        $album = Album::fromArray([
            'name' => 'Untitled',
            'artist' => null,
            'year' => null,
            'tracks' => [['id' => 't1', 'metadata' => ['title' => 'One']]],
        ]);
        $view = (new AlbumScreen($album, cols: 120, rows: 40))->view();

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
        $screen = new AlbumScreen($album, cols: 120, rows: 40);

        self::assertNull($screen->selectedTrack()?->trackNumber, 'no explicit track number');
        $view = $screen->view();
        self::assertStringContainsString('First', $view);
        self::assertStringContainsString('Second', $view);
    }

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

    public function testEnterSurfacesThePlaybackComingSoonToast(): void
    {
        [$same, $cmd] = $this->screen()->update(new KeyMsg(KeyType::Enter));

        $msg = $cmd?->__invoke();
        self::assertInstanceOf(ShowToastMsg::class, $msg);
        self::assertSame(ToastType::Info, $msg->type);
        self::assertStringContainsString('next update', $msg->message);
        self::assertInstanceOf(AlbumScreen::class, $same);
    }

    public function testEnterOnAnEmptyAlbumIsANoOp(): void
    {
        $empty = new AlbumScreen(Album::fromArray(['name' => 'Empty', 'tracks' => []]), cols: 120, rows: 40);

        [, $cmd] = $empty->update(new KeyMsg(KeyType::Enter));

        self::assertNull($cmd, 'no tracks → nothing to play');
        self::assertStringContainsString('No tracks', $empty->view());
    }

    public function testArrowsOnAnEmptyAlbumAreNoOps(): void
    {
        $empty = new AlbumScreen(Album::fromArray(['name' => 'Empty', 'tracks' => []]), cols: 120, rows: 40);

        [$down, $downCmd] = $empty->update(new KeyMsg(KeyType::Down));
        [$up, $upCmd] = $empty->update(new KeyMsg(KeyType::Up));

        self::assertSame($empty, $down);
        self::assertSame($empty, $up);
        self::assertNull($downCmd);
        self::assertNull($upCmd);
    }

    public function testEscapeAndQNavigateBack(): void
    {
        $screen = $this->screen();

        [, $escCmd] = $screen->update(new KeyMsg(KeyType::Escape));
        [, $qCmd] = $screen->update(new KeyMsg(KeyType::Char, 'q'));

        self::assertInstanceOf(NavigateBackMsg::class, $escCmd?->__invoke());
        self::assertInstanceOf(NavigateBackMsg::class, $qCmd?->__invoke());
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
        // A non-key, non-resize Msg the album screen doesn't handle is ignored.
        $screen = $this->screen();

        [$same, $cmd] = $screen->update(new \Phlix\Console\Msg\SessionExpiredMsg('ignored here'));

        self::assertSame($screen, $same);
        self::assertNull($cmd);
    }

    public function testAlbumAccessorReturnsTheAlbum(): void
    {
        $screen = $this->screen();

        self::assertSame('Abbey Road', $screen->album()->name);
        self::assertCount(2, $screen->album()->tracks);
    }

    public function testResizeStillRenders(): void
    {
        // A small window may not have room for both the meta header and the full
        // table, but the screen must re-flow and render without error.
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
}
