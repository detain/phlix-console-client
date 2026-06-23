<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Msg\AudioFailedMsg;
use Phlix\Console\Msg\AudioStartedMsg;
use Phlix\Console\Msg\AudioTickMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Screen\AlbumScreen;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Tests\Api\FakeTransport;
use Phlix\Console\Tests\Reel\FakeAudioPlayer;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Toast\ToastType;

final class AlbumScreenTest extends TestCase
{
    private const STREAM = 'https://srv/media/t1/stream?exp=1&sig=abc';

    /** The most recent fake audio player the factory produced (assert lifecycle calls). */
    private ?FakeAudioPlayer $lastPlayer = null;
    /** Every URL the audio factory was handed (in order). @var list<string> */
    private array $playedUrls = [];

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

    /**
     * Build a screen over a real MediaStore (FakeTransport) and a recording audio
     * factory. The transport, when not supplied, returns a `/media/{id}` detail
     * with a signed stream URL for every track-start fetch.
     */
    private function screen(?Album $album = null, ?FakeTransport $transport = null, ?string $streamUrl = self::STREAM): AlbumScreen
    {
        $transport ??= (new FakeTransport())->json(200, $this->itemResponse($streamUrl));
        $media = new MediaStore(new ApiClient('https://srv', $transport));
        $factory = function (string $url): FakeAudioPlayer {
            $this->playedUrls[] = $url;

            return $this->lastPlayer = new FakeAudioPlayer($url);
        };

        return new AlbumScreen($album ?? $this->album(), $media, 'https://srv', $factory, cols: 120, rows: 40);
    }

    /** A `/media/{id}` detail response carrying a (signed) stream URL. */
    private function itemResponse(?string $streamUrl): array
    {
        return ['item' => ['id' => 't1', 'name' => 'Come Together', 'type' => 'music', 'stream_url' => $streamUrl]];
    }

    // ---- browse (kept from M2, retargeted for M3) ----------------------

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
        // A non-key, non-audio Msg the album screen doesn't handle is ignored.
        $screen = $this->screen();

        [$same, $cmd] = $screen->update(new \Phlix\Console\Msg\OpenSearchMsg());

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

    // ---- audio: start --------------------------------------------------

    public function testEnterStartsPlaybackOfTheSelectedTrack(): void
    {
        $screen = $this->screen();

        // Enter fetches the stream URL; the resolved Msg starts playback.
        [$loading, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        $started = $this->runCmd($cmd);
        self::assertInstanceOf(AudioStartedMsg::class, $started);
        self::assertSame(0, $started->index);
        self::assertSame(self::STREAM, $started->url, 'the signed absolute URL is used verbatim');

        [$playing, $tick] = $loading->update($started);

        self::assertSame(0, $playing->playingIndex());
        self::assertFalse($playing->isPaused());
        self::assertSame(0, $playing->position());
        self::assertNotNull($this->lastPlayer);
        self::assertSame(1, $this->lastPlayer->startCalls, 'the player was started');
        self::assertNotNull($tick, 'the position tick is armed');

        // The now-playing line replaces the meta line with ▶ + title + 0:00 / dur.
        $view = $playing->view();
        self::assertStringContainsString('▶ Come Together', $view);
        self::assertStringContainsString('0:00 / 4:19', $view);
        self::assertStringNotContainsString('The Beatles', $view, 'the meta line is replaced while playing');
    }

    public function testEnterOnAnEmptyAlbumIsANoOp(): void
    {
        $empty = $this->screen(Album::fromArray(['name' => 'Empty', 'tracks' => []]));

        [$same, $cmd] = $empty->update(new KeyMsg(KeyType::Enter));

        self::assertSame($empty, $same);
        self::assertNull($cmd, 'no tracks → nothing to play');
        self::assertStringContainsString('No tracks', $empty->view());
    }

    public function testRelativeStreamUrlIsResolvedAgainstTheBase(): void
    {
        $screen = $this->screen(transport: (new FakeTransport())->json(200, $this->itemResponse('/media/t1/stream?sig=x')));

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        $started = $this->runCmd($cmd);

        self::assertInstanceOf(AudioStartedMsg::class, $started);
        self::assertSame('https://srv/media/t1/stream?sig=x', $started->url);
    }

    // ---- audio: pause / resume -----------------------------------------

    public function testEnterOnThePlayingTrackTogglesPause(): void
    {
        $playing = $this->playing();
        self::assertStringContainsString('▶ Come Together', $playing->view());

        // Enter again (the playing track is still selected) → pause.
        [$paused, $pauseCmd] = $playing->update(new KeyMsg(KeyType::Enter));
        self::assertTrue($paused->isPaused());
        self::assertSame(1, $this->lastPlayer?->pauseCalls);
        self::assertNull($pauseCmd, 'pausing stops the tick');
        self::assertStringContainsString('⏸ Come Together', $paused->view());

        // Enter once more → resume + re-arm the tick.
        [$resumed, $resumeCmd] = $paused->update(new KeyMsg(KeyType::Enter));
        self::assertFalse($resumed->isPaused());
        self::assertSame(1, $this->lastPlayer?->resumeCalls);
        self::assertNotNull($resumeCmd, 'resuming re-arms the tick');
        self::assertStringContainsString('▶ Come Together', $resumed->view());
    }

    public function testSpaceTogglesPause(): void
    {
        $playing = $this->playing();

        [$paused, $pauseCmd] = $playing->update(new KeyMsg(KeyType::Char, ' '));
        self::assertTrue($paused->isPaused());
        self::assertSame(1, $this->lastPlayer?->pauseCalls);
        self::assertNull($pauseCmd);

        [$resumed, $resumeCmd] = $paused->update(new KeyMsg(KeyType::Char, ' '));
        self::assertFalse($resumed->isPaused());
        self::assertSame(1, $this->lastPlayer?->resumeCalls);
        self::assertNotNull($resumeCmd);
    }

    public function testSpaceWithNothingPlayingIsANoOp(): void
    {
        $screen = $this->screen();

        [$same, $cmd] = $screen->update(new KeyMsg(KeyType::Char, ' '));

        self::assertSame($screen, $same);
        self::assertNull($cmd);
    }

    // ---- audio: tick / position / auto-advance -------------------------

    public function testAudioTickIncrementsTheEstimatedPosition(): void
    {
        $cur = $this->playing();
        for ($i = 0; $i < 5; $i++) {
            [$cur, $cmd] = $cur->update(new AudioTickMsg($cur->audioEpoch()));
            self::assertNotNull($cmd, 'each playing tick re-arms the next');
        }

        self::assertSame(5, $cur->position());
        self::assertStringContainsString('0:05 / 4:19', $cur->view(), 'position renders as m:ss');
    }

    public function testTickWhilePausedDoesNotAdvanceOrRearm(): void
    {
        $playing = $this->playing();
        [$paused] = $playing->update(new KeyMsg(KeyType::Char, ' '));

        [$same, $cmd] = $paused->update(new AudioTickMsg($paused->audioEpoch()));

        self::assertSame($paused, $same, 'a paused tick is inert');
        self::assertNull($cmd, 'no re-arm while paused');
        self::assertSame(0, $same->position());
    }

    public function testTickWithNothingPlayingIsANoOp(): void
    {
        $screen = $this->screen();

        [$same, $cmd] = $screen->update(new AudioTickMsg($screen->audioEpoch()));

        self::assertSame($screen, $same);
        self::assertNull($cmd);
    }

    public function testReachingTheDurationAutoAdvancesToTheNextTrack(): void
    {
        // A 2-second track followed by another, so the tick reaches the duration fast.
        $album = Album::fromArray([
            'name' => 'Short',
            'tracks' => [
                ['id' => 't1', 'metadata' => ['title' => 'One', 'duration_secs' => 2]],
                ['id' => 't2', 'metadata' => ['title' => 'Two', 'duration_secs' => 5]],
            ],
        ]);
        // Two item fetches: the first track (on Enter) then the second (on auto-advance).
        $transport = (new FakeTransport())
            ->json(200, ['item' => ['id' => 't1', 'name' => 'One', 'type' => 'music', 'stream_url' => 'https://srv/s/t1']])
            ->json(200, ['item' => ['id' => 't2', 'name' => 'Two', 'type' => 'music', 'stream_url' => 'https://srv/s/t2']]);
        $screen = $this->screen($album, $transport);
        $cur = $this->startAndPlay($screen);
        self::assertSame(0, $cur->playingIndex());
        $firstPlayer = $this->lastPlayer;

        // Tick to 2s == duration → auto-advance fetch fires.
        [$cur, $tick1] = $cur->update(new AudioTickMsg($cur->audioEpoch())); // 1
        self::assertSame(1, $cur->position());
        [$advancing, $advanceCmd] = $cur->update(new AudioTickMsg($cur->audioEpoch())); // 2 == duration → advance
        $next = $this->runCmd($advanceCmd);
        self::assertInstanceOf(AudioStartedMsg::class, $next, 'reaching the duration starts the next track');
        self::assertSame(1, $next->index);

        [$onTwo] = $advancing->update($next);
        self::assertSame(1, $onTwo->playingIndex());
        self::assertSame(0, $onTwo->position(), 'position resets on the new track');
        self::assertNotNull($firstPlayer);
        self::assertSame(1, $firstPlayer->stopCalls, 'the previous player was stopped');
        self::assertStringContainsString('▶ Two', $onTwo->view());
    }

    public function testReachingTheDurationOnTheLastTrackStopsPlayback(): void
    {
        $album = Album::fromArray([
            'name' => 'Solo',
            'tracks' => [['id' => 't1', 'metadata' => ['title' => 'Only', 'duration_secs' => 2]]],
        ]);
        $screen = $this->screen($album, (new FakeTransport())->json(200, ['item' => ['id' => 't1', 'name' => 'Only', 'type' => 'music', 'stream_url' => 'https://srv/s/t1']]));
        $cur = $this->startAndPlay($screen);
        $player = $this->lastPlayer;

        [$cur] = $cur->update(new AudioTickMsg($cur->audioEpoch())); // 1
        [$stopped, $cmd] = $cur->update(new AudioTickMsg($cur->audioEpoch())); // 2 == duration, no next → stop

        self::assertNull($stopped->playingIndex(), 'playback stops at the last track');
        self::assertNull($cmd, 'no further tick once stopped');
        self::assertSame(1, $player?->stopCalls);
        // The header reverts to the album meta line.
        self::assertStringContainsString('1 track', $stopped->view());
    }

    public function testTrackWithUnknownDurationNeverAutoAdvances(): void
    {
        $album = Album::fromArray([
            'name' => 'Live',
            'tracks' => [
                ['id' => 't1', 'metadata' => ['title' => 'Jam']], // no duration_secs
                ['id' => 't2', 'metadata' => ['title' => 'Next', 'duration_secs' => 3]],
            ],
        ]);
        $cur = $this->startAndPlay($this->screen($album));

        for ($i = 0; $i < 50; $i++) {
            [$cur, $cmd] = $cur->update(new AudioTickMsg($cur->audioEpoch()));
            self::assertNotNull($cmd, 'an unknown-duration track keeps ticking');
        }

        self::assertSame(0, $cur->playingIndex(), 'still on the first track');
        self::assertSame(50, $cur->position());
        self::assertStringContainsString('▶ Jam', $cur->view());
        self::assertStringContainsString('0:50 / —', $cur->view(), 'unknown duration shows a dash');
    }

    public function testAStaleTickFromASupersededGenerationIsDropped(): void
    {
        // Regression: a leftover tick from a previous heartbeat must NOT advance
        // the position or arm a second heartbeat (which would double playback
        // speed and auto-advance early). Reproduced via a pause/resume cycle,
        // which supersedes the running chain.
        $cur = $this->playing();
        $staleEpoch = $cur->audioEpoch();
        [$cur] = $cur->update(new AudioTickMsg($cur->audioEpoch())); // position 1, same generation

        [$paused] = $cur->update(new KeyMsg(KeyType::Char, ' '));   // bump epoch, pause
        [$resumed, $arm] = $paused->update(new KeyMsg(KeyType::Char, ' ')); // bump epoch, resume + new heartbeat
        self::assertNotNull($arm, 'resume arms a fresh heartbeat');
        self::assertNotSame($staleEpoch, $resumed->audioEpoch(), 'the generation advanced');

        // The leftover tick from the original generation is ignored.
        [$afterStale, $staleCmd] = $resumed->update(new AudioTickMsg($staleEpoch));
        self::assertSame($resumed->position(), $afterStale->position(), 'a stale tick does not advance the position');
        self::assertNull($staleCmd, 'a stale tick does not arm a second heartbeat');

        // The live generation's tick still advances exactly once.
        [$afterLive] = $resumed->update(new AudioTickMsg($resumed->audioEpoch()));
        self::assertSame($resumed->position() + 1, $afterLive->position());
    }

    // ---- audio: n / p --------------------------------------------------

    public function testNAdvancesPlaybackToTheNextTrack(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['item' => ['id' => 't1', 'name' => 'Come Together', 'type' => 'music', 'stream_url' => 'https://srv/s/t1']])
            ->json(200, ['item' => ['id' => 't2', 'name' => 'Something', 'type' => 'music', 'stream_url' => 'https://srv/s/t2']]);
        $cur = $this->startAndPlay($this->screen(transport: $transport));
        self::assertSame(0, $cur->playingIndex());

        [, $cmd] = $cur->update(new KeyMsg(KeyType::Char, 'n'));
        $next = $this->runCmd($cmd);

        self::assertInstanceOf(AudioStartedMsg::class, $next);
        self::assertSame(1, $next->index);
    }

    public function testPGoesToThePreviousTrack(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['item' => ['id' => 't2', 'name' => 'Something', 'type' => 'music', 'stream_url' => 'https://srv/s/t2']])
            ->json(200, ['item' => ['id' => 't1', 'name' => 'Come Together', 'type' => 'music', 'stream_url' => 'https://srv/s/t1']]);
        // Select + play the second track first.
        $screen = $this->screen(transport: $transport);
        [$down] = $screen->update(new KeyMsg(KeyType::Down));
        $cur = $this->startAndPlay($down);
        self::assertSame(1, $cur->playingIndex());

        [, $cmd] = $cur->update(new KeyMsg(KeyType::Char, 'p'));
        $prev = $this->runCmd($cmd);

        self::assertInstanceOf(AudioStartedMsg::class, $prev);
        self::assertSame(0, $prev->index);
    }

    public function testNAtTheLastTrackIsANoOp(): void
    {
        // Play the (last) second track, then n should do nothing.
        $screen = $this->screen();
        [$down] = $screen->update(new KeyMsg(KeyType::Down));
        $cur = $this->startAndPlay($down);
        self::assertSame(1, $cur->playingIndex());

        [$same, $cmd] = $cur->update(new KeyMsg(KeyType::Char, 'n'));

        self::assertSame($cur, $same);
        self::assertNull($cmd);
    }

    public function testNWithNothingPlayingIsANoOp(): void
    {
        $screen = $this->screen();

        [$same, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'n'));
        [$same2, $cmd2] = $screen->update(new KeyMsg(KeyType::Char, 'p'));

        self::assertSame($screen, $same);
        self::assertSame($screen, $same2);
        self::assertNull($cmd);
        self::assertNull($cmd2);
    }

    // ---- audio: failures -----------------------------------------------

    public function testMissingStreamUrlSurfacesAnErrorToast(): void
    {
        $screen = $this->screen(transport: (new FakeTransport())->json(200, $this->itemResponse(null)));

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        $failed = $this->runCmd($cmd);
        self::assertInstanceOf(AudioFailedMsg::class, $failed);

        [$after, $toastCmd] = $screen->update($failed);
        $toast = $toastCmd?->__invoke();
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
        self::assertStringContainsString('Could not play', $toast->message);
        self::assertNull($after->playingIndex(), 'nothing starts playing');
    }

    public function testAudioFailedLeavesCurrentPlaybackAlone(): void
    {
        // Already playing the first track; a later failed start must not stop it.
        $playing = $this->playing();

        [$after, $cmd] = $playing->update(new AudioFailedMsg('boom'));

        self::assertSame(0, $after->playingIndex(), 'the playing track is untouched');
        self::assertSame(0, $this->lastPlayer?->stopCalls ?? -1, 'the live player was not stopped');
        $toast = $cmd?->__invoke();
        self::assertInstanceOf(ShowToastMsg::class, $toast);
    }

    public function testFetchFailureBecomesAnAudioFailedMsg(): void
    {
        $screen = $this->screen(transport: (new FakeTransport())->fail(new \RuntimeException('network')));

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        self::assertInstanceOf(AudioFailedMsg::class, $this->runCmd($cmd));
    }

    public function testAuthErrorBecomesSessionExpired(): void
    {
        $screen = $this->screen(transport: (new FakeTransport())->json(401, ['error' => 'unauthorized']));

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        self::assertInstanceOf(SessionExpiredMsg::class, $this->runCmd($cmd));
    }

    // ---- teardown ------------------------------------------------------

    public function testEscapeTearsDownAndNavigatesBack(): void
    {
        $playing = $this->playing();
        $player = $this->lastPlayer;

        [, $cmd] = $playing->update(new KeyMsg(KeyType::Escape));

        self::assertInstanceOf(NavigateBackMsg::class, $cmd?->__invoke());
        self::assertSame(1, $player?->stopCalls, 'leaving the album stops the audio (no leaked ffplay)');
    }

    public function testQAlsoTearsDownAndNavigatesBack(): void
    {
        $playing = $this->playing();
        $player = $this->lastPlayer;

        [, $cmd] = $playing->update(new KeyMsg(KeyType::Char, 'q'));

        self::assertInstanceOf(NavigateBackMsg::class, $cmd?->__invoke());
        self::assertSame(1, $player?->stopCalls);
    }

    public function testTeardownIsIdempotent(): void
    {
        $playing = $this->playing();
        $player = $this->lastPlayer;

        $playing->teardown();
        $playing->teardown(); // must not double-stop or throw

        self::assertSame(1, $player?->stopCalls);
    }

    public function testEscapeWithNothingPlayingJustNavigatesBack(): void
    {
        $screen = $this->screen();

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Escape));

        self::assertInstanceOf(NavigateBackMsg::class, $cmd?->__invoke());
    }

    // ---- audio test helpers --------------------------------------------

    /** Drive Enter → fetch → AudioStarted on the default screen and return the playing screen. */
    private function playing(): AlbumScreen
    {
        return $this->startAndPlay($this->screen());
    }

    /** Drive Enter → resolve the fetch → feed AudioStarted; return the now-playing screen. */
    private function startAndPlay(AlbumScreen $screen): AlbumScreen
    {
        [$loading, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        $started = $this->runCmd($cmd);
        self::assertInstanceOf(AudioStartedMsg::class, $started);
        [$playing] = $loading->update($started);

        return $playing;
    }

    // ---- async harness (mirrors PlayerScreenTest) ----------------------

    private function runCmd(?\Closure $cmd): ?Msg
    {
        if ($cmd === null) {
            return null;
        }
        $result = $cmd();
        if ($result instanceof BatchMsg) {
            foreach ($result->cmds as $child) {
                $msg = $this->runCmd($child);
                if ($msg !== null) {
                    return $msg;
                }
            }

            return null;
        }
        if ($result instanceof AsyncCmd) {
            return $this->await($result->promise);
        }

        return $result instanceof Msg ? $result : null;
    }

    private function await(PromiseInterface $promise, float $timeout = 5.0): mixed
    {
        $state = ['done' => false, 'value' => null, 'error' => null];
        $promise->then(
            function ($v) use (&$state): void {
                $state['value'] = $v;
                $state['done'] = true;
                Loop::stop();
            },
            function ($e) use (&$state): void {
                $state['error'] = $e;
                $state['done'] = true;
                Loop::stop();
            },
        );

        if ($state['done']) {
            // The promise settled synchronously (the MediaStore wraps the sync
            // FakeTransport in a Deferred). React may still have enqueued the
            // Deferred's handler on the loop's futureTick queue — flush it with a
            // single immediate tick so no residual work leaks into a later test's
            // Loop::run(); a futureTick stop returns at once (no blocking wait).
            Loop::futureTick(static fn () => Loop::stop());
            Loop::run();
        } else {
            $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($timer);
        }

        if (!$state['done']) {
            throw new \RuntimeException('cmd did not settle in time');
        }
        if ($state['error'] !== null) {
            throw $state['error'];
        }

        return $state['value'];
    }
}
