<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Api\Dto\Chapter;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\Dto\StreamAudioTrack;
use Phlix\Console\Api\Dto\PlaybackMarkers;
use Phlix\Console\Api\SyncPlay\SyncPlayService;
use Phlix\Console\Audio\MusicSession;
use Phlix\Console\Config\Config;
use Phlix\Console\Config\ServerEntry;
use Phlix\Console\Config\TokenStore;
use Phlix\Console\Msg\PlaybackMarkersLoadedMsg;
use Phlix\Console\Msg\PlayerReadyMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Msg\ToggleMetricsMsg;
use Phlix\Console\Screen\LineAligned;
use Phlix\Console\Screen\PlayerScreen;
use Phlix\Console\Store\AuthStore;
use Phlix\Console\Store\LibrariesStore;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Tests\Api\FakeTransport;
use Phlix\Console\Tests\Video\FakeAudioPlayer;
use Phlix\Console\Ui\AudioTrackList;
use Phlix\Console\Ui\ChapterList;
use Phlix\Console\Ui\CommandPalette;
use Phlix\Console\Ui\MetricsOverlay;
use Phlix\Console\Ui\PaletteAction;
use Phlix\Console\Ui\SyncPlayOverlay;
use Phlix\Console\App;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Route;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Image\Mosaic;
use SugarCraft\Video\Decode\RgbFrame;
use SugarCraft\Video\Render\Mode;
use SugarCraft\Video\Player;
use SugarCraft\Video\Tests\FakeDecoder;

/**
 * Regression law for M-CONSOLE-42: a graphics-mode player frame carries its
 * sixel/kitty/iTerm2 blob on ONE logical line (the frame is OPAQUE-FIRST-LINE —
 * see {@see LineAligned}), and every compositor the App or the player layers on
 * top must GRAFT it with absolute cursor moves instead of splicing lines. On
 * the parent, `explode("\n")` saw the blob as the whole screen and each
 * compositor (HUD splice, bottom-bar replace, Veil canvases, the toast) either
 * severed the DCS payload or replaced the video outright.
 */
final class GraphicsFrameCompositingTest extends TestCase
{
    /** A miniature sixel payload on one logical line — structurally what the player frame's line 0 is. */
    private const BLOB = "\x1bPq\"0;1;800;480#0;1;65;65;65;120;120;120;0;0;0#1;2;200;200;200-$!0!1!2\x1b\\";

    /** The player's own absolute-move status chrome appended to the blob (graphicsChrome idiom). */
    private function opaqueFrame(): string
    {
        return self::BLOB
            . Ansi::cursorTo(22, 1) . Ansi::eraseLine() . 'caption: subtitle text'
            . Ansi::cursorTo(23, 1) . Ansi::eraseLine() . '1:23 [-|--|---] 4:56'
            . Ansi::cursorTo(24, 1) . Ansi::eraseLine() . '[space] play  [q] quit';
    }

    /** @return list<string> */
    private function metricsLines(): array
    {
        return ['Mem    3.2 / 4.1 MB', 'Route  Player'];
    }

    // ---- App-level compositors (opaque frame is the TOP screen) ----------------

    public function testMetricsHudIsGraftedOverTheGraphicsFrameInsteadOfSplicingIt(): void
    {
        $frame = $this->opaqueFrame();
        $app = $this->appWith(new OpaqueFrameScreen($frame), metricsVisible: true);
        $rendered = $app->view();

        // Parent: explode+splice cut the DCS payload (dropAnsi only passes CSI/OSC).
        self::assertStringStartsWith($frame, $rendered);
        // The box still lands top-left, via absolute moves, content intact.
        self::assertStringContainsString('Mem    ', $rendered);
        self::assertStringContainsString('Route  Player', $rendered);
        self::assertStringContainsString(Ansi::cursorTo(1, 1), $rendered);
    }

    public function testNowPlayingBarIsWrittenToTheLastScreenRowInsteadOfReplacingTheFrame(): void
    {
        $frame = $this->opaqueFrame();
        $app = $this->appWith(new OpaqueFrameScreen($frame), nowPlaying: $this->musicSession());
        $rendered = $app->view();

        // Parent: `$lines[last] = $bar` replaced the SINGLE line — the whole
        // video+chrome — with the bar. Now the bar rides cursorTo(rows, 1).
        self::assertStringStartsWith($frame, $rendered);
        self::assertStringContainsString('Come Together', $rendered);
        self::assertStringContainsString(Ansi::cursorTo(24, 1) . Ansi::eraseLine(), $rendered);
        // The bar emits AFTER the chrome's own bottom-row write → it wins the row.
        self::assertGreaterThan(
            strpos($rendered, '4:56'),
            strpos($rendered, 'Come Together'),
        );
    }

    public function testCommandPaletteIsGraftedOverTheGraphicsFrame(): void
    {
        $frame = $this->opaqueFrame();
        $app = $this->appWith(
            new OpaqueFrameScreen($frame),
            palette: CommandPalette::open([new PaletteAction('Toggle metrics', new ToggleMetricsMsg())], 80, 24),
        );
        $rendered = $app->view();

        // Parent: Veil resolved a 1-row canvas and spliced the box INTO the blob.
        self::assertStringStartsWith($frame, $rendered);
        self::assertStringContainsString('Toggle metrics', $rendered);
    }

    public function testToastIsSkippedOverTheGraphicsFrameButUnchangedOverPlainOnes(): void
    {
        $frame = self::BLOB;
        $opaqueApp = $this->appWith(new OpaqueFrameScreen($frame));
        [$toasted] = $opaqueApp->update(ShowToastMsg::error('GRAPHICS_PROOF_TOAST'));
        $opaqueRendered = $toasted->view();
        // sugar-toast splices lines; the only safe in-repo law over an opaque
        // frame is to skip it — the toast waits instead of corrupting the blob.
        self::assertStringNotContainsString('GRAPHICS_PROOF_TOAST', $opaqueRendered);
        self::assertStringStartsWith($frame, $opaqueRendered);

        // Control: the very same toast over a line-ALIGNED frame still composites.
        $plain = "row0\nrow1\nrow2";
        $plainApp = $this->appWith(new PlainFrameScreen($plain));
        [$toastedPlain] = $plainApp->update(ShowToastMsg::error('GRAPHICS_PROOF_TOAST'));
        $plainRendered = $toastedPlain->view();
        self::assertStringContainsString('GRAPHICS_PROOF_TOAST', $plainRendered);
    }

    // ---- Player-owned compositors ----------------------------------------------

    public function testMenusGraftCenteredBoxesWhenTheFrameIsOpaque(): void
    {
        $frame = $this->opaqueFrame();

        $menu = ChapterList::open([new Chapter(0.0, 50.0, 'Part One'), new Chapter(50.0, 100.0, 'Part Two')], 10.0, 80, 24);
        $out = $menu->render($frame, true);

        self::assertStringStartsWith($frame, $out);
        self::assertStringNotContainsString("\n", substr($out, strlen($frame)));
        self::assertStringContainsString('Part One', $out);

        $audio = AudioTrackList::open([StreamAudioTrack::fromArray(['id' => 'a1', 'codec' => 'aac', 'language' => 'en-US', 'channels' => 2, 'title' => null])], 'a1', 80, 24);
        self::assertStringStartsWith($frame, $audio->render($frame, true));

        $hud = MetricsOverlay::render($frame, $this->metricsLines(), 80, 24, null, true);
        self::assertStringStartsWith($frame, $hud);

        $sync = SyncPlayOverlay::render($frame, 'Movie Night', 2, 'in sync', 80, 24, null, true);
        self::assertStringStartsWith($frame, $sync);
        self::assertStringContainsString('Movie Night', $sync);
    }

    public function testTheLegacySplicePathIsPreservedWhenTheFlagIsFalse(): void
    {
        // The seam is opt-in: with the frame declared LINE-ALIGNED the compositors
        // behave exactly as before. Over an opaque frame without the flag the
        // corruption the seam exists for still happens (pin: the flag, not an
        // inference, decides).
        $plain = "row0-row0\nrow1-row1\nrow2-row2";
        $box = ChapterList::open([new Chapter(0.0, 5.0, 'Chapter A')], 1.0, 80, 24);
        $spliced = $box->render($plain);
        self::assertNotSame($plain, $spliced);
        self::assertStringContainsString('───', $spliced);

        $frame = $this->opaqueFrame();
        $corrupted = $box->render($frame);
        self::assertFalse(str_starts_with($corrupted, $frame));
    }

    // ---- PlayerScreen: the authority on the frame model ------------------------

    public function testPlayerScreenDeclaresOnlyItsGraphicsFrameNonLineAligned(): void
    {
        $screen = $this->playerScreen(Mode::HalfBlock);
        self::assertTrue($screen->isLineAligned());

        $graphics = $this->playerScreen(Mode::Sixel);
        self::assertFalse($graphics->isLineAligned());
    }

    public function testChapterMenuOverALiveSixelFrameIsGraftedNotSpliced(): void
    {
        $screen = $this->playerScreen(Mode::Sixel);
        [$screen] = $screen->update(new PlaybackMarkersLoadedMsg(new PlaybackMarkers(
            'm1',
            null,
            null,
            [new Chapter(0.0, 50.0, 'Part One')],
        )));
        [$screen] = $screen->update(new KeyMsg(KeyType::Char, 'C'));

        $view = $screen->view();

        // The frame really is one opaque line: the base carries a DCS blob head.
        self::assertStringStartsWith("\x1bP", $view);
        self::assertSame(1, count(explode("\n", $view)));
        // Parent spliced the box into that line (Veil saw a 1-row canvas); the
        // cure grafts it — box content present, blob head untouched.
        self::assertStringContainsString('Part One', $view);
        // Every appended box row is an absolute move; the blob bytes between the
        // DSC/ST markers must survive contiguously.
        $stPos = strpos($view, "\x1b\\");
        self::assertIsInt($stPos);
        self::assertStringStartsWith("\x1bP", substr($view, 0, $stPos + 2));
    }

    // ---- Controls ---------------------------------------------------------------

    public function testTextModeFramesStillFlowThroughThePlainSpliceCompositors(): void
    {
        $screen = $this->playerScreen(Mode::HalfBlock);
        [$screen] = $screen->update(new PlaybackMarkersLoadedMsg(new PlaybackMarkers(
            'm1',
            null,
            null,
            [new Chapter(0.0, 50.0, 'Part One')],
        )));
        [$screen] = $screen->update(new KeyMsg(KeyType::Char, 'C'));

        $view = $screen->view();

        // HalfBlock frames are line-addressable — the Veil canvas path renders the
        // dimmed composite, and the frame keeps one line per row.
        self::assertGreaterThan(1, count(explode("\n", $view)));
        self::assertStringContainsString('Part One', $view);
        self::assertStringNotContainsString("\x1bP", $view);
    }

    // ---- Harness ----------------------------------------------------------------

    /**
     * @param array<string,mixed> $extras
     */
    private function appWith(Model $screen, ...$extras): App
    {
        $api = new ApiClient('https://srv', new FakeTransport());

        return new App(
            new Config(servers: [new ServerEntry(id: 's1', label: 'Server', url: 'https://srv')], activeServerId: 's1'),
            new AuthStore($api, TokenStore::default()),
            $api,
            new LibrariesStore($api),
            new MediaStore($api),
            new PosterLoader(Mosaic::halfBlock()),
            [['route' => Route::Player, 'screen' => $screen]],
            ...$extras,
        );
    }

    private function musicSession(): MusicSession
    {
        $album = Album::fromArray([
            'name' => 'Abbey Road',
            'artist' => 'The Beatles',
            'year' => 1969,
            'track_count' => 1,
            'tracks' => [
                ['id' => 't1', 'metadata' => ['title' => 'Come Together', 'track_number' => 1, 'duration_secs' => 259]],
            ],
        ]);

        return new MusicSession(new FakeAudioPlayer('https://srv/stream/t1'), $album, 0, false, 0, 1);
    }

    /** A PlayerScreen whose inner is mounted in the given render mode. */
    private function playerScreen(Mode $mode): PlayerScreen
    {
        $transport = (new FakeTransport())->json(200, ['success' => true]);
        $api = new ApiClient('https://srv', $transport);
        $decoder = new FakeDecoder([new RgbFrame(str_repeat("\x20\x30\x40", 64), 8, 8)]);
        $player = Player::fromDecoder(
            $decoder,
            fps: 24.0,
            totalFrames: 2400,
            mode: $mode,
            cellsW: 80,
            cellsH: 19,
            videoPath: '/fake',
            paused: true,
        );
        // A paused player carries no frame until one is sought; frame 1 hydrates
        // the real direct-render path (the graphics blob is then live in view()).
        $player = $player->withSeek(1);
        $factory = static fn (): Player => $player;
        $item = MediaItem::fromArray([
            'id' => 'm1',
            'name' => 'The Matrix',
            'type' => 'movie',
            'stream_url' => 'https://srv/stream/m1',
        ]);

        $screen = new PlayerScreen($item, 'https://srv', $api, $factory, new SyncPlayService($api), cols: 80, rows: 24);
        [$screen] = $screen->update(new PlayerReadyMsg($player));

        return $screen;
    }
}

/**
 * A fake top-of-stack screen whose frame is declared OPAQUE: exactly what
 * App::view() consults via {@see LineAligned} to route graft vs. splice.
 */
final class OpaqueFrameScreen implements Model, LineAligned
{
    public function __construct(private string $frame)
    {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        return [$this, null];
    }

    public function view(): string
    {
        return $this->frame;
    }

    public function subscriptions(): ?Subscriptions
    {
        return null;
    }

    public function isLineAligned(): bool
    {
        return false;
    }
}

/** The same seam with an honest line-aligned frame — the control path. */
final class PlainFrameScreen implements Model, LineAligned
{
    public function __construct(private string $frame)
    {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        return [$this, null];
    }

    public function view(): string
    {
        return $this->frame;
    }

    public function subscriptions(): ?Subscriptions
    {
        return null;
    }

    public function isLineAligned(): bool
    {
        return true;
    }
}
