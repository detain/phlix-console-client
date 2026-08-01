<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Api\Dto\StreamAudioTrack;
use Phlix\Console\Ui\AudioTrackList;
use PHPUnit\Framework\TestCase;

final class AudioTrackListTest extends TestCase
{
    /**
     * @return list<StreamAudioTrack>
     */
    private function tracks(array $langs): array
    {
        return array_map(
            static fn (string $lang, int $i): StreamAudioTrack => StreamAudioTrack::fromArray([
                'id' => "track-{$i}",
                'codec' => 'aac',
                'language' => $lang,
                'channels' => 2,
                'title' => null,
            ]),
            $langs,
            array_keys($langs),
        );
    }

    public function testOpenStartsAtFirstTrack(): void
    {
        $tracks = $this->tracks(['en-US', 'es-ES', 'fr-FR']);
        $menu = AudioTrackList::open($tracks, null, 80, 24);

        self::assertSame(0, $menu->cursor());
        self::assertSame('en-US', $menu->selectedTrack()->language);
        self::assertSame('track-0', $menu->selectedId());
    }

    public function testOpenPreselectsKnownTrack(): void
    {
        $tracks = $this->tracks(['en-US', 'es-ES', 'fr-FR']);
        $menu = AudioTrackList::open($tracks, 'track-1', 80, 24);

        self::assertSame(1, $menu->cursor());
        self::assertSame('es-ES', $menu->selectedTrack()->language);
        self::assertSame('track-1', $menu->selectedId());
    }

    public function testOpenFallsBackToFirstForUnknownId(): void
    {
        $tracks = $this->tracks(['en-US', 'es-ES']);
        $menu = AudioTrackList::open($tracks, 'unknown-track', 80, 24);

        self::assertSame(0, $menu->cursor());
    }

    public function testUpClampsAtZero(): void
    {
        $tracks = $this->tracks(['en-US', 'es-ES']);
        $menu = AudioTrackList::open($tracks, null, 80, 24);

        self::assertSame(0, $menu->up()->cursor());
    }

    public function testDownAdvancesCursor(): void
    {
        $tracks = $this->tracks(['en-US', 'es-ES', 'fr-FR']);
        $menu = AudioTrackList::open($tracks, null, 80, 24);

        self::assertSame(1, $menu->down()->cursor());
        self::assertSame(2, $menu->down()->down()->cursor());
    }

    public function testDownClampsAtLastTrack(): void
    {
        $tracks = $this->tracks(['en-US', 'es-ES', 'fr-FR']);
        $menu = AudioTrackList::open($tracks, null, 80, 24);

        $bottom = $menu->down()->down()->down()->down()->down();
        self::assertSame(2, $bottom->cursor());
    }

    public function testEmptyTracksList(): void
    {
        $menu = AudioTrackList::open([], null, 80, 24);

        self::assertSame(0, $menu->cursor());
        self::assertNull($menu->selectedTrack());
        self::assertNull($menu->selectedId());
        self::assertSame([], $menu->tracks());
    }

    public function testResizedToRefitsBoxPreservingCursor(): void
    {
        $tracks = $this->tracks(['en-US', 'es-ES', 'fr-FR']);
        $menu = AudioTrackList::open($tracks, 'track-1', 120, 40);
        self::assertSame(1, $menu->cursor());

        $resized = $menu->resizedTo(30, 12);

        self::assertSame(1, $resized->cursor());
        self::assertSame('track-1', $resized->selectedId());
    }

    public function testRenderCompositesOverBackground(): void
    {
        $tracks = $this->tracks(['en-US', 'es-ES']);
        $menu = AudioTrackList::open($tracks, null, 80, 24);
        $background = implode("\n", array_fill(0, 24, str_repeat('x', 80)));

        $out = $menu->render($background);

        self::assertStringContainsString('en-US', $out);
        self::assertStringContainsString('es-ES', $out);
        self::assertStringContainsString('Audio Tracks', $out);
    }

    public function testSelectedTrackReturnsCorrectTrack(): void
    {
        $tracks = $this->tracks(['en-US', 'es-ES', 'fr-FR']);
        $menu = AudioTrackList::open($tracks, 'track-2', 80, 24);

        $selected = $menu->selectedTrack();

        self::assertNotNull($selected);
        self::assertSame('track-2', $selected->id);
        self::assertSame('fr-FR', $selected->language);
    }

    public function testSelectedIdOnEmptyList(): void
    {
        $menu = AudioTrackList::open([], null, 80, 24);
        self::assertNull($menu->selectedId());
    }

    public function testTracksReturnsAllTracks(): void
    {
        $tracks = $this->tracks(['en-US', 'es-ES']);
        $menu = AudioTrackList::open($tracks, null, 80, 24);

        $result = $menu->tracks();

        self::assertCount(2, $result);
        self::assertSame('en-US', $result[0]->language);
        self::assertSame('es-ES', $result[1]->language);
    }

    public function testRenderShowsSelectedTrackHighlighted(): void
    {
        $tracks = $this->tracks(['en-US', 'es-ES']);
        $menu = AudioTrackList::open($tracks, 'track-0', 80, 24);
        $background = implode("\n", array_fill(0, 24, str_repeat('x', 80)));

        $out = $menu->render($background);

        // Selected track should be highlighted with reverse/bold
        self::assertStringContainsString("\033[", $out, 'active track is styled');
    }
}
