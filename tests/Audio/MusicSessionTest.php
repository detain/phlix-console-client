<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Audio;

use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Audio\MusicSession;
use Phlix\Console\Tests\Reel\FakeAudioPlayer;
use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\AudioPlayer;

final class MusicSessionTest extends TestCase
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

    private function session(?Album $album = null, int $index = 0, bool $paused = false, int $position = 0, int $epoch = 1): MusicSession
    {
        return new MusicSession(new FakeAudioPlayer('u'), $album ?? $this->album(), $index, $paused, $position, $epoch);
    }

    // ---- accessors -----------------------------------------------------

    public function testAccessorsReflectTheConstructorArguments(): void
    {
        $player = new FakeAudioPlayer('u');
        $album = $this->album();
        $np = new MusicSession($player, $album, 1, true, 42, 7);

        self::assertSame($player, $np->player());
        self::assertSame($album, $np->album());
        self::assertSame(1, $np->trackIndex());
        self::assertTrue($np->paused());
        self::assertSame(42, $np->positionSecs());
        self::assertSame(7, $np->epoch());
    }

    public function testTrackTitleSubtitleAndDurationComeFromTheCurrentTrack(): void
    {
        $np = $this->session(index: 1);

        self::assertSame('Something', $np->track()?->title);
        self::assertSame('Something', $np->title());
        self::assertSame('Abbey Road · The Beatles', $np->subtitle());
        self::assertSame(182, $np->durationSecs());
    }

    public function testSubtitleOmitsAMissingArtist(): void
    {
        $np = $this->session($this->album(artist: null));

        self::assertSame('Abbey Road', $np->subtitle(), 'no " · artist" when the album has no artist');
    }

    public function testAnOutOfRangeIndexYieldsAnEmptyTitleAndNullDuration(): void
    {
        $np = $this->session(index: 9);

        self::assertNull($np->track());
        self::assertSame('', $np->title());
        self::assertNull($np->durationSecs());
        // The subtitle still reflects the album (it does not depend on the track).
        self::assertSame('Abbey Road · The Beatles', $np->subtitle());
    }

    // ---- interface: labels / ticked / endReached -----------------------

    public function testPositionAndDurationLabelsFormatAsClocks(): void
    {
        $np = $this->session(index: 0, position: 83); // 1:23 of a 4:19 track

        self::assertSame('1:23', $np->positionLabel());
        self::assertSame('4:19', $np->durationLabel());
    }

    public function testDurationLabelIsADashWhenUnknown(): void
    {
        $album = Album::fromArray([
            'name' => 'Live',
            'tracks' => [['id' => 't1', 'metadata' => ['title' => 'Jam']]], // no duration
        ]);
        $np = $this->session($album, index: 0, position: 5);

        self::assertSame('0:05', $np->positionLabel());
        self::assertSame('—', $np->durationLabel(), 'an unknown duration renders as a dash');
    }

    public function testHourLongPositionsUseHmmss(): void
    {
        $album = Album::fromArray([
            'name' => 'Long',
            'tracks' => [['id' => 't1', 'metadata' => ['title' => 'Epic', 'duration_secs' => 7200]]],
        ]);
        $np = $this->session($album, index: 0, position: 3661); // 1:01:01

        self::assertSame('1:01:01', $np->positionLabel());
        self::assertSame('2:00:00', $np->durationLabel());
    }

    public function testTickedAdvancesThePositionByOneSecond(): void
    {
        $np = $this->session(position: 4);
        $ticked = $np->ticked();

        self::assertNotSame($np, $ticked);
        self::assertSame(4, $np->positionSecs(), 'the original is unchanged');
        self::assertSame(5, $ticked->positionSecs());
    }

    public function testEndReachedOnceThePositionMeetsTheDuration(): void
    {
        // Track 1 is a 182s song.
        self::assertFalse($this->session(index: 1, position: 181)->endReached());
        self::assertTrue($this->session(index: 1, position: 182)->endReached());
        self::assertTrue($this->session(index: 1, position: 200)->endReached());
    }

    public function testEndReachedIsFalseForAnUnknownDuration(): void
    {
        $album = Album::fromArray([
            'name' => 'Live',
            'tracks' => [['id' => 't1', 'metadata' => ['title' => 'Jam']]], // no duration
        ]);

        self::assertFalse($this->session($album, index: 0, position: 99_999)->endReached(), 'no duration → never ends');
    }

    // ---- clone-mutate --------------------------------------------------

    public function testWithPausedIsImmutable(): void
    {
        $np = $this->session(paused: false);
        $paused = $np->withPaused(true);

        self::assertNotSame($np, $paused);
        self::assertFalse($np->paused(), 'the original is unchanged');
        self::assertTrue($paused->paused());
    }

    public function testWithPositionSecsIsImmutable(): void
    {
        $np = $this->session(position: 0);
        $advanced = $np->withPositionSecs(5);

        self::assertNotSame($np, $advanced);
        self::assertSame(0, $np->positionSecs());
        self::assertSame(5, $advanced->positionSecs());
    }

    public function testWithEpochIsImmutable(): void
    {
        $np = $this->session(epoch: 1);
        $bumped = $np->withEpoch(2);

        self::assertNotSame($np, $bumped);
        self::assertSame(1, $np->epoch());
        self::assertSame(2, $bumped->epoch());
    }

    public function testWithTrackResetsPositionAndPauseUnderANewPlayer(): void
    {
        $np = $this->session(index: 0, paused: true, position: 99, epoch: 3);
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
        $np = new MusicSession($player, $this->album(), 0, false, 0, 1);

        $np->teardown();

        self::assertSame(1, $player->stopCalls);
    }

    public function testTeardownIsIdempotent(): void
    {
        $player = new FakeAudioPlayer('u');
        $np = new MusicSession($player, $this->album(), 0, false, 0, 1);

        $np->teardown();
        $np->teardown(); // must not double-stop or throw

        self::assertSame(1, $player->stopCalls);
    }

    public function testProductionAudioFactoryBuildsAnAudioPlayer(): void
    {
        $factory = MusicSession::productionAudioFactory();

        $player = $factory('https://srv/s/t1', null);

        self::assertInstanceOf(AudioPlayer::class, $player);
    }
}
