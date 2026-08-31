<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\StreamSubtitleTrack;
use PHPUnit\Framework\TestCase;

/**
 * The `subtitle_tracks[]` element of `GET /api/v1/media/{id}/playback-info`
 * (and the `/playback` twin — both dispatch through phlix-server's
 * `Media/Library/StreamTrackShaper::subtitleTracks()`).
 *
 * Every fixture row here is the shaper's VERBATIM wire emission (the same
 * golden vectors `@phlix/contracts` pins in
 * `test/fixtures/stream-track-vectors.json` at server `01340633`): keys
 * `{id, index, stream_index, language, label, codec, source,
 * hearing_impaired, url}`. The server derives `label = title ?? language ??
 * 'Subtitle N'` and NEVER emits `title`/`is_forced`/`is_default` on subtitles
 * (S404: the pre-fix DTO parsed exactly those three ghosts). The
 * "never-emitted keys are ignored" test is the mutation guard: were this DTO
 * ever repointed at a non-emitted key, a wire row would no longer hydrate.
 */
final class StreamSubtitleTrackTest extends TestCase
{
    /**
     * @param array<string,mixed> $overrides keys to replace on the base row
     * @return array<string,mixed> a full server-shaped subtitle-track row
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => 'ss-1',
            'index' => 0,
            'stream_index' => 1,
            'language' => 'eng',
            'label' => 'eng',
            'codec' => 'subrip',
            'source' => null,
            'hearing_impaired' => true,
            'url' => '/api/v1/media/11111111-2222-3333-4444-555555555555/subtitles/0?exp=1800000000&sig=dGVzdC1zaWctYmFzZTY0dXJs',
        ], $overrides);
    }

    public function testFromArrayMapsTheShaperRow(): void
    {
        $track = StreamSubtitleTrack::fromArray($this->row());

        self::assertSame('ss-1', $track->id);
        self::assertSame('subrip', $track->codec);
        self::assertSame('eng', $track->language);
        self::assertSame('eng', $track->label);
        self::assertNull($track->source);
        self::assertTrue($track->hearingImpaired);
    }

    public function testLabelCarriesTheServerDerivedDisplayString(): void
    {
        // Golden vector: title wins over language server-side; the DTO reads
        // only the derived `label` — the raw `title` is not on the wire.
        $track = StreamSubtitleTrack::fromArray($this->row([
            'index' => 2,
            'stream_index' => 4,
            'language' => 'spa',
            'label' => 'Español (Forzada)',
            'codec' => 'mov_text',
            'hearing_impaired' => false,
        ]));

        self::assertSame('Español (Forzada)', $track->label);
        self::assertFalse($track->hearingImpaired);
    }

    public function testExternalRowKeepsItsSourceProvenance(): void
    {
        $track = StreamSubtitleTrack::fromArray($this->row([
            'id' => 'ss-ext',
            'index' => 1,
            'stream_index' => 1,
            'language' => 'und',
            'label' => 'Subtitle 1',
            'source' => 'opensubtitles',
            'url' => '/api/v1/media/11111111-2222-3333-4444-555555555555/subtitles/external/ss-ext?exp=1800000000&sig=dGVzdC1zaWctYmFzZTY0dXJs',
        ]));

        self::assertSame('opensubtitles', $track->source);
        self::assertSame('Subtitle 1', $track->label);
    }

    public function testMissingKeysFallBackToTheDocumentedDefaults(): void
    {
        $track = StreamSubtitleTrack::fromArray([]);

        self::assertSame('', $track->id);
        self::assertSame('', $track->codec);
        self::assertSame('und', $track->language, 'an absent language is the undetermined tag');
        self::assertSame('', $track->label);
        self::assertNull($track->source);
        self::assertFalse($track->hearingImpaired);
    }

    /**
     * The S404 fiction keys: `title`, `is_forced` and `is_default` are NOT
     * emitted by the shaper. A row carrying them (hand-written or from a
     * rogue server) must hydrate EXACTLY like the same row without them —
     * proof the DTO reads only wire keys. If anyone re-points fromArray at
     * `$data['title']` etc., the first assertion reddens.
     */
    public function testNeverEmittedKeysAreIgnoredVerbatim(): void
    {
        $withFiction = StreamSubtitleTrack::fromArray($this->row([
            'title' => 'A title that is NOT on the wire',
            'is_forced' => true,
            'is_default' => true,
        ]));
        $without = StreamSubtitleTrack::fromArray($this->row());

        self::assertEquals($without, $withFiction);
    }

    public function testDisplayLabelLeadsWithLanguageAndAppendsRicherLabel(): void
    {
        $same = StreamSubtitleTrack::fromArray($this->row());
        self::assertSame('eng [HI]', $same->displayLabel(), 'label equal to language adds no separator; the HI flag does');

        $richer = StreamSubtitleTrack::fromArray($this->row([
            'language' => 'spa',
            'label' => 'Español (Forzada)',
            'hearing_impaired' => false,
        ]));
        self::assertSame('spa - Español (Forzada)', $richer->displayLabel());

        $bare = StreamSubtitleTrack::fromArray($this->row(['label' => '', 'hearing_impaired' => false]));
        self::assertSame('eng', $bare->displayLabel());
    }

    public function testListFromArrayMapsEveryRowInOrderAndSkipsGarbage(): void
    {
        $tracks = StreamSubtitleTrack::listFromArray([
            $this->row(),
            'garbage',
            $this->row(['id' => 'ss-2', 'index' => 2, 'stream_index' => 4, 'label' => 'spa']),
        ]);

        self::assertCount(2, $tracks);
        self::assertSame(['ss-1', 'ss-2'], array_map(static fn (StreamSubtitleTrack $t): string => $t->id, $tracks));
        self::assertSame('spa', $tracks[1]->label);
    }

    public function testAbsentOrEmptySubtitleTracksYieldNoTracks(): void
    {
        self::assertSame([], StreamSubtitleTrack::listFromArray([]));
        self::assertSame([], StreamSubtitleTrack::listFromArray(null));
        self::assertSame([], StreamSubtitleTrack::listFromArray('nonsense'));
    }
}
