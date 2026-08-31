<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\PlaybackInfo;
use Phlix\Console\Api\Dto\StreamAudioTrack;
use Phlix\Console\Api\Dto\StreamSubtitleTrack;
use Phlix\Console\Config\TokenBundle;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

/**
 * S404 wire-shape gate: `GET /api/v1/media/{id}/playback` carries the
 * StreamTrackShaper's golden track rows through the REAL ApiClient decode
 * path (FakeTransport — no live server). The row literals are the exact
 * captures `@phlix/contracts` pins in
 * `test/fixtures/stream-track-vectors.json` (server `01340633`).
 *
 * The `subtitle_tracks` side is asserted at DTO level: PlayerScreen's caption
 * flow rides the SEPARATE `/media/{id}/subtitles` rail and the subtitle menu
 * is currently unfed (dead picker) — decoding `subtitle_tracks` into the
 * player is functional wiring, filed as S407, deliberately NOT added here.
 */
final class PlaybackTrackWireShapeTest extends TestCase
{
    private const BASE = 'https://srv.example';

    /** Await a promise with a hard timeout (no real event-loop starvation). */
    private function await(PromiseInterface $promise, float $timeout = 2.0): mixed
    {
        $state = ['done' => false, 'value' => null, 'error' => null];
        $promise->then(
            function ($value) use (&$state): void {
                $state['value'] = $value;
                $state['done'] = true;
                Loop::stop();
            },
            function ($error) use (&$state): void {
                $state['error'] = $error;
                $state['done'] = true;
                Loop::stop();
            },
        );
        Loop::addTimer($timeout, static fn () => Loop::stop());
        Loop::run();
        if (!$state['done']) {
            self::fail('promise never settled');
        }
        if ($state['error'] !== null) {
            throw $state['error'];
        }

        return $state['value'];
    }

    public function testPlaybackRouteHydratesGoldenAudioTracks(): void
    {
        // Golden subtitle rows ride the same response; see class docblock
        // for why the console parses them at DTO level only.
        $subtitleRows = [
            [
                'id' => 'ss-1',
                'index' => 0,
                'stream_index' => 1,
                'language' => 'eng',
                'label' => 'eng',
                'codec' => 'subrip',
                'source' => null,
                'hearing_impaired' => true,
                'url' => '/api/v1/media/m1/subtitles/0?exp=1800000000&sig=dGVzdC1zaWc',
            ],
        ];
        $t = (new FakeTransport())->json(200, ['playback_info' => [
            'id' => 'm1',
            'name' => 'The Matrix',
            'type' => 'movie',
            'media_sources' => [['id' => 'default']],
            'audio_tracks' => [
                [
                    'id' => 'as-1',
                    'index' => 0,
                    'stream_index' => 1,
                    'codec' => 'eac3',
                    'language' => 'en',
                    'channels' => 6,
                    'bitrate' => 640000,
                    'title' => null,
                    'default' => false,
                ],
                [
                    'id' => 'as-2',
                    'index' => 1,
                    'stream_index' => 2,
                    'codec' => 'aac',
                    'language' => 'en',
                    'channels' => 2,
                    'bitrate' => 128000,
                    'title' => 'Commentary',
                    'default' => true,
                ],
            ],
            'subtitle_tracks' => $subtitleRows,
        ]]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $info = $this->await($client->playbackInfo('m1'));

        self::assertInstanceOf(PlaybackInfo::class, $info);
        self::assertStringEndsWith('/api/v1/media/m1/playback', $t->requestAt(0)['url']);

        self::assertContainsOnlyInstancesOf(StreamAudioTrack::class, $info->audioTracks);
        self::assertCount(2, $info->audioTracks);
        self::assertSame('en (6 ch)', $info->audioTracks[0]->displayLabel());
        self::assertSame('en - Commentary (2 ch)', $info->audioTracks[1]->displayLabel());

        $subtitles = StreamSubtitleTrack::listFromArray($subtitleRows);
        self::assertCount(1, $subtitles);
        self::assertSame('eng [HI]', $subtitles[0]->displayLabel());
    }

    public function testGoldenSubtitleRowsAllHydrateFromTheirWireKeys(): void
    {
        // Every distinct emission branch of subtitleTracks(): embedded text,
        // bitmap-gap ordinal, external download, and the url-null form.
        $rows = [
            ['id' => 'ss-1', 'index' => 0, 'stream_index' => 1, 'language' => 'eng', 'label' => 'eng', 'codec' => 'subrip', 'source' => null, 'hearing_impaired' => true, 'url' => '/api/v1/media/m1/subtitles/0?exp=1800000000&sig=aaa'],
            ['id' => 'ss-2', 'index' => 2, 'stream_index' => 4, 'language' => 'spa', 'label' => 'Español (Forzada)', 'codec' => 'mov_text', 'source' => null, 'hearing_impaired' => false, 'url' => '/api/v1/media/m1/subtitles/2?exp=1800000000&sig=bbb'],
            ['id' => 'ss-ext', 'index' => 1, 'stream_index' => 1, 'language' => 'und', 'label' => 'Subtitle 1', 'codec' => 'webvtt', 'source' => 'opensubtitles', 'hearing_impaired' => true, 'url' => '/api/v1/media/m1/subtitles/external/ss-ext?exp=1800000000&sig=ccc'],
            ['id' => 'ss-nul', 'index' => 0, 'stream_index' => 1, 'language' => 'pt', 'label' => 'pt', 'codec' => 'ass', 'source' => null, 'hearing_impaired' => false, 'url' => null],
        ];

        $tracks = StreamSubtitleTrack::listFromArray($rows);

        self::assertCount(4, $tracks);
        foreach ($tracks as $i => $track) {
            self::assertSame($rows[$i]['label'], $track->label, "row {$i} label must come from the wire key");
            self::assertSame($rows[$i]['hearing_impaired'], $track->hearingImpaired, "row {$i} HI flag");
            self::assertSame($rows[$i]['source'], $track->source, "row {$i} source");
        }
        self::assertSame('und - Subtitle 1 [HI]', $tracks[2]->displayLabel());
    }
}
