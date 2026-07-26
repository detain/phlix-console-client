<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\PlaybackInfo;
use Phlix\Console\Api\Dto\StreamAudioTrack;
use PHPUnit\Framework\TestCase;

/**
 * The `audio_tracks[]` element of `GET /api/v1/media/{id}/playback` (and of the
 * `/playback-info` route, which shares the shaper).
 *
 * Every fixture here mirrors phlix-server
 * `Media/Library/StreamTrackShaper::audioTracks()`, the single shaper both
 * server dispatch paths emit through: it always writes
 * `{id, index, stream_index, codec, language, channels, bitrate, title, default}`,
 * defaults `language` to `'und'`, and marks exactly one element `default: true`.
 * The extra `index`/`stream_index`/`default` keys are carried in the fixtures
 * (as they are on the wire) to prove the DTO ignores them without choking.
 */
final class StreamAudioTrackTest extends TestCase
{
    /**
     * @param array<string,mixed> $overrides keys to replace on the base row
     * @return array<string,mixed> a full server-shaped audio-track row
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => 'as1',
            'index' => 0,
            'stream_index' => 1,
            'codec' => 'eac3',
            'language' => 'en',
            'channels' => 6,
            'bitrate' => 640000,
            'title' => null,
            'default' => true,
        ], $overrides);
    }

    public function testFromArrayMapsTheShaperRow(): void
    {
        $track = StreamAudioTrack::fromArray($this->row());

        self::assertSame('as1', $track->id);
        self::assertSame('eac3', $track->codec);
        self::assertSame('en', $track->language);
        self::assertSame(6, $track->channels);
        self::assertSame(640000, $track->bitrate);
        self::assertNull($track->title);
    }

    public function testMissingKeysFallBackToTheDocumentedDefaults(): void
    {
        $track = StreamAudioTrack::fromArray([]);

        self::assertSame('', $track->id);
        self::assertSame('', $track->codec);
        self::assertSame('und', $track->language, 'an absent language is the undetermined tag');
        self::assertSame(0, $track->channels);
        self::assertNull($track->bitrate);
        self::assertNull($track->title);
    }

    /**
     * `'und'` is the fallback for a NON-STRING language
     * ({@see \Phlix\Console\Api\Dto\Coerce::str}'s `$default`), not for the empty string — an explicit `''` is a scalar and
     * survives verbatim. Documented rather than "fixed" because the server's
     * shaper already guarantees a non-empty tag
     * (`nonEmptyString($stream['language']) ?? 'und'`), so `''` never reaches a
     * real client; the only visible effect would be a `' (6 ch)'` menu label.
     */
    public function testAnEmptyLanguageSurvivesButANonStringOneBecomesUnd(): void
    {
        self::assertSame('', StreamAudioTrack::fromArray($this->row(['language' => '']))->language);
        self::assertSame('und', StreamAudioTrack::fromArray($this->row(['language' => null]))->language);
        self::assertSame('und', StreamAudioTrack::fromArray($this->row(['language' => ['en']]))->language);
    }

    public function testDisplayLabelIsLanguageAndChannelCount(): void
    {
        self::assertSame('en (6 ch)', StreamAudioTrack::fromArray($this->row())->displayLabel());
    }

    public function testDisplayLabelAppendsTheTrackTitleWhenPresent(): void
    {
        $track = StreamAudioTrack::fromArray($this->row([
            'title' => 'Director Commentary',
            'channels' => 2,
        ]));

        self::assertSame('en - Director Commentary (2 ch)', $track->displayLabel());
    }

    public function testDisplayLabelSkipsAnEmptyTitle(): void
    {
        $track = StreamAudioTrack::fromArray($this->row(['title' => '']));

        self::assertSame('en (6 ch)', $track->displayLabel(), 'an empty title adds no separator');
    }

    public function testListFromArrayMapsEveryRowInOrder(): void
    {
        $tracks = StreamAudioTrack::listFromArray([
            $this->row(),
            $this->row(['id' => 'as2', 'index' => 1, 'stream_index' => 2, 'codec' => 'aac', 'channels' => 2, 'default' => false]),
        ]);

        self::assertCount(2, $tracks);
        self::assertSame(['as1', 'as2'], array_map(static fn (StreamAudioTrack $t): string => $t->id, $tracks));
        self::assertSame('aac', $tracks[1]->codec);
    }

    public function testListFromArraySkipsNonArrayRows(): void
    {
        $tracks = StreamAudioTrack::listFromArray([$this->row(), 'garbage', 42, null]);

        self::assertCount(1, $tracks);
        self::assertSame('as1', $tracks[0]->id);
    }

    /**
     * An item with no audio streams — the shaper returns `[]`, and an older
     * server omits the key entirely. Both must yield no tracks (which is what
     * makes `PlayerScreen`'s audio-track overlay a no-op).
     */
    public function testAbsentOrEmptyAudioTracksYieldNoTracks(): void
    {
        self::assertSame([], StreamAudioTrack::listFromArray([]));
        self::assertSame([], StreamAudioTrack::listFromArray(null));
        self::assertSame([], StreamAudioTrack::listFromArray('nonsense'));
    }

    public function testPlaybackInfoCarriesTheShapedAudioTracks(): void
    {
        // The exact `playback_info` object WebPortalRouter::getPlaybackInfo() builds.
        $info = PlaybackInfo::fromArray([
            'id' => 'm1',
            'name' => 'The Matrix',
            'type' => 'movie',
            'media_sources' => [['id' => 'default', 'container' => 'mkv', 'path' => '/m/m1.mkv', 'direct_play' => true]],
            'markers' => [
                'skip_intro_start' => 5.0,
                'skip_intro_end' => 30.0,
                'skip_outro_start' => 90.0,
                'skip_outro_end' => 100.0,
            ],
            'audio_tracks' => [$this->row(), $this->row(['id' => 'as2', 'title' => 'Commentary', 'channels' => 2, 'default' => false])],
            'subtitle_tracks' => [],
        ]);

        self::assertSame('m1', $info->id);
        self::assertSame('movie', $info->type);
        self::assertCount(1, $info->mediaSources);
        self::assertSame(5.0, $info->markers['skip_intro_start']);
        self::assertContainsOnlyInstancesOf(StreamAudioTrack::class, $info->audioTracks);
        self::assertCount(2, $info->audioTracks);
        self::assertSame('en - Commentary (2 ch)', $info->audioTracks[1]->displayLabel());
    }

    public function testPlaybackInfoWithoutAudioTracksIsEmpty(): void
    {
        $info = PlaybackInfo::fromArray(['id' => 'm1', 'name' => 'X', 'type' => 'movie', 'media_sources' => [['id' => 'default']]]);

        self::assertSame([], $info->audioTracks, 'an older server omitting the key is not an error');
        self::assertSame([], $info->markers);
    }
}
