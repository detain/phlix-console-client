<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Audio;

use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Audio\NowPlaying;
use Phlix\Console\Tests\Reel\FakeAudioPlayer;
use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\AudioPlayer;

final class NowPlayingTest extends TestCase
{
    private function album(?string $artist = 'The Beatles'): Album
    {
        return Album::fromArray([
            'name' => 'Abbey Road',
            'artist' => $artist,
            'year' => 1969,
            'track_count' => 2,
            'tracks' => [
                ['id' => 't1', 'metadata' => ['title' => 'Come Together', 'track_number' => 1, 'duration_secs' => 259]],
                ['id' => 't2', 'metadata' => ['title' => 'Something', 'track_number' => 2, 'duration_secs' => 182]],
            ],
        ]);
    }

    private function nowPlaying(?Album $album = null, int $index = 0, bool $paused = false, int $position = 0, int $epoch = 1): NowPlaying
    {
        return new NowPlaying(new FakeAudioPlayer('u'), $album ?? $this->album(), $index, $paused, $position, $epoch);
    }

    // ---- accessors -----------------------------------------------------

    public function testAccessorsReflectTheConstructorArguments(): void
    {
        $player = new FakeAudioPlayer('u');
        $album = $this->album();
        $np = new NowPlaying($player, $album, 1, true, 42, 7);

        self::assertSame($player, $np->player());
        self::assertSame($album, $np->album());
        self::assertSame(1, $np->trackIndex());
        self::assertTrue($np->paused());
        self::assertSame(42, $np->positionSecs());
        self::assertSame(7, $np->epoch());
    }

    public function testTrackTitleSubtitleAndDurationComeFromTheCurrentTrack(): void
    {
        $np = $this->nowPlaying(index: 1);

        self::assertSame('Something', $np->track()?->title);
        self::assertSame('Something', $np->title());
        self::assertSame('Abbey Road · The Beatles', $np->subtitle());
        self::assertSame(182, $np->durationSecs());
    }

    public function testSubtitleOmitsAMissingArtist(): void
    {
        $np = $this->nowPlaying($this->album(artist: null));

        self::assertSame('Abbey Road', $np->subtitle(), 'no " · artist" when the album has no artist');
    }

    public function testAnOutOfRangeIndexYieldsAnEmptyTitleAndNullDuration(): void
    {
        $np = $this->nowPlaying(index: 9);

        self::assertNull($np->track());
        self::assertSame('', $np->title());
        self::assertNull($np->durationSecs());
        // The subtitle still reflects the album (it does not depend on the track).
        self::assertSame('Abbey Road · The Beatles', $np->subtitle());
    }

    // ---- clone-mutate --------------------------------------------------

    public function testWithPausedIsImmutable(): void
    {
        $np = $this->nowPlaying(paused: false);
        $paused = $np->withPaused(true);

        self::assertNotSame($np, $paused);
        self::assertFalse($np->paused(), 'the original is unchanged');
        self::assertTrue($paused->paused());
    }

    public function testWithPositionSecsIsImmutable(): void
    {
        $np = $this->nowPlaying(position: 0);
        $advanced = $np->withPositionSecs(5);

        self::assertNotSame($np, $advanced);
        self::assertSame(0, $np->positionSecs());
        self::assertSame(5, $advanced->positionSecs());
    }

    public function testWithEpochIsImmutable(): void
    {
        $np = $this->nowPlaying(epoch: 1);
        $bumped = $np->withEpoch(2);

        self::assertNotSame($np, $bumped);
        self::assertSame(1, $np->epoch());
        self::assertSame(2, $bumped->epoch());
    }

    public function testWithTrackResetsPositionAndPauseUnderANewPlayer(): void
    {
        $np = $this->nowPlaying(index: 0, paused: true, position: 99, epoch: 3);
        $newPlayer = new FakeAudioPlayer('v');

        $next = $np->withTrack(1, $newPlayer);

        self::assertNotSame($np, $next);
        self::assertSame(1, $next->trackIndex());
        self::assertSame($newPlayer, $next->player(), 'the new track plays under the new player');
        self::assertSame(0, $next->positionSecs(), 'position resets on a new track');
        self::assertFalse($next->paused(), 'a new track starts unpaused');
        self::assertSame(3, $next->epoch(), 'withTrack leaves the epoch to the caller');
        self::assertSame('Something', $next->title());
    }

    // ---- teardown ------------------------------------------------------

    public function testTeardownStopsThePlayer(): void
    {
        $player = new FakeAudioPlayer('u');
        $np = new NowPlaying($player, $this->album(), 0, false, 0, 1);

        $np->teardown();

        self::assertSame(1, $player->stopCalls);
    }

    public function testTeardownIsIdempotent(): void
    {
        $player = new FakeAudioPlayer('u');
        $np = new NowPlaying($player, $this->album(), 0, false, 0, 1);

        $np->teardown();
        $np->teardown(); // must not double-stop or throw

        self::assertSame(1, $player->stopCalls);
    }

    public function testProductionAudioFactoryBuildsAnAudioPlayer(): void
    {
        $factory = NowPlaying::productionAudioFactory();

        $player = $factory('https://srv/s/t1', null);

        self::assertInstanceOf(AudioPlayer::class, $player);
    }
}
