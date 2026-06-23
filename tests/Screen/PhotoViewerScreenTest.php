<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\PhotoAlbum;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\PhotoExifLoadedMsg;
use Phlix\Console\Msg\PhotoImageLoadedMsg;
use Phlix\Console\Msg\PhotoSlideTickMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Screen\PhotoViewerScreen;
use Phlix\Console\Store\PhotosStore;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Mosaic\Mosaic;

final class PhotoViewerScreenTest extends TestCase
{
    private ?SocketServer $socket = null;

    protected function tearDown(): void
    {
        $this->socket?->close();
        $this->socket = null;
        parent::tearDown();
    }

    /** An album of $photoCount photos, each with the same thumbnail/full URL. */
    private function album(int $photoCount, ?string $full = 'https://srv/f.png', string $date = '2026-06-23'): PhotoAlbum
    {
        $photos = [];
        for ($i = 0; $i < $photoCount; $i++) {
            $photos[] = [
                'id' => "p{$i}",
                'name' => "p{$i}.jpg",
                'thumbnail_url' => $full,
                'full_url' => $full,
            ];
        }

        return PhotoAlbum::fromArray([
            'id' => 'a0',
            'date' => $date,
            'photo_count' => $photoCount,
            'photos' => $photos,
        ]);
    }

    /** A `/photo/photos/{id}` detail response with a full EXIF block. */
    private function exifResponse(string $id = 'p0'): array
    {
        return ['photo' => [
            'id' => $id,
            'name' => "{$id}.jpg",
            'full_url' => 'https://srv/f.png',
            'exif' => [
                'camera_make' => 'Canon',
                'camera_model' => 'EOS R5',
                'lens' => 'RF 50mm F1.2',
                'aperture' => 'f/2.8',
                'iso' => 400,
                'shutter_speed' => '1/200',
                'focal_length' => '50mm',
                'width' => 8192,
                'height' => 5464,
                'date_taken_formatted' => '2026-06-23 10:30',
                'gps_display' => '48.8584, 2.2945',
            ],
        ]];
    }

    /** A `/photo/photos/{id}` detail response carrying NO exif block. */
    private function noExifResponse(string $id = 'p0'): array
    {
        return ['photo' => ['id' => $id, 'name' => "{$id}.jpg", 'full_url' => 'https://srv/f.png']];
    }

    private function store(?FakeTransport $transport = null): PhotosStore
    {
        $transport ??= (new FakeTransport())->json(200, $this->exifResponse());

        return new PhotosStore(new ApiClient('https://srv', $transport));
    }

    private function screen(
        PhotoAlbum $album,
        int $index = 0,
        ?PhotosStore $store = null,
        ?PosterLoader $posters = null,
        string $base = 'https://srv',
    ): PhotoViewerScreen {
        return new PhotoViewerScreen(
            $album,
            $index,
            $store ?? $this->store(),
            $posters ?? new PosterLoader(Mosaic::halfBlock()),
            $base,
            cols: 120,
            rows: 40,
        );
    }

    // ---- construction / clamp ------------------------------------------

    public function testIndexIsClampedIntoBounds(): void
    {
        self::assertSame(2, $this->screen($this->album(3), 99)->index(), 'an over-range index clamps to the last');
        self::assertSame(0, $this->screen($this->album(3), -5)->index(), 'a negative index clamps to 0');
        self::assertSame(0, $this->screen($this->album(0), 4)->index(), 'an empty album floors to 0');
    }

    // ---- init: image + exif --------------------------------------------

    public function testInitLoadsTheImageAndExif(): void
    {
        $port = $this->startImageServer();
        $screen = $this->screen($this->album(3, "http://127.0.0.1:{$port}/f.png"), posters: new PosterLoader(Mosaic::halfBlock()));

        $msgs = $this->runBatch($screen->init());

        $image = $this->firstOf($msgs, PhotoImageLoadedMsg::class);
        self::assertInstanceOf(PhotoImageLoadedMsg::class, $image, 'the full image rendered');
        self::assertSame($screen->gen(), $image->generation);

        [$withImage] = $screen->update($image);
        self::assertTrue($withImage->hasImage());

        $exif = $this->firstOf($msgs, PhotoExifLoadedMsg::class);
        self::assertInstanceOf(PhotoExifLoadedMsg::class, $exif, 'the EXIF detail resolved');
        self::assertSame('Canon EOS R5', $exif->exif?->displayPairs()[0][1] ?? null);
    }

    public function testInitResolvesARelativeFullUrlAgainstTheBase(): void
    {
        $port = $this->startImageServer();
        $base = "http://127.0.0.1:{$port}";
        $screen = $this->screen($this->album(1, '/f.png'), posters: new PosterLoader(Mosaic::halfBlock()), base: $base);

        $image = $this->firstOf($this->runBatch($screen->init()), PhotoImageLoadedMsg::class);
        self::assertInstanceOf(PhotoImageLoadedMsg::class, $image, 'a relative full_url resolved against the base and rendered');
    }

    public function testPlaceholderShowsBeforeTheImageArrives(): void
    {
        // No image applied yet → the body carries the dim ░ placeholder block.
        $view = $this->screen($this->album(3))->view();

        self::assertStringContainsString('░', $view, 'the placeholder block renders until the image loads');
        self::assertStringContainsString('p0.jpg', $view, 'the caption shows the photo name');
        self::assertStringContainsString('1/3', $view, 'the caption shows position/total');
    }

    public function testImageReplacesThePlaceholderOnceLoaded(): void
    {
        $screen = $this->screen($this->album(3));
        self::assertFalse($screen->hasImage());

        [$withImage] = $screen->update(new PhotoImageLoadedMsg($screen->gen(), "▀▀▀\n▄▄▄"));

        self::assertTrue($withImage->hasImage());
        self::assertStringContainsString('▀▀▀', $withImage->view());
    }

    public function testFullUrlNullLoadsNoImageAndKeepsThePlaceholder(): void
    {
        // A photo with no full_url → only the EXIF cmd is built (no image load).
        $store = $this->store((new FakeTransport())->json(200, $this->noExifResponse()));
        $screen = $this->screen($this->album(1, null), store: $store);

        $msgs = $this->runBatch($screen->init());

        self::assertNull($this->firstOf($msgs, PhotoImageLoadedMsg::class), 'no image is loaded with a null full_url');
        self::assertInstanceOf(PhotoExifLoadedMsg::class, $this->firstOf($msgs, PhotoExifLoadedMsg::class), 'EXIF still loads');
        self::assertFalse($screen->hasImage());
        self::assertStringContainsString('░', $screen->view(), 'the placeholder stays');
    }

    // ---- EXIF panel ----------------------------------------------------

    public function testExifPanelRendersTheDisplayPairsWhenLoaded(): void
    {
        $screen = $this->screen($this->album(1));
        [$withExif] = $screen->update(new PhotoExifLoadedMsg($screen->gen(), $this->loadedExif($screen)));

        // i toggles the panel on; the panel shows the EXIF header + pairs.
        [$shown] = $withExif->update(new KeyMsg(KeyType::Char, 'i'));
        self::assertTrue($shown->showExif());

        $view = $shown->view();
        self::assertStringContainsString('EXIF', $view);
        self::assertStringContainsString('Camera', $view);
        self::assertStringContainsString('Canon EOS R5', $view);
        self::assertStringContainsString('Aperture', $view);
        self::assertStringContainsString('f/2.8', $view);
    }

    public function testExifPanelShowsLoadingBeforeExifResolves(): void
    {
        $screen = $this->screen($this->album(1));
        [$shown] = $screen->update(new KeyMsg(KeyType::Char, 'i'));

        self::assertStringContainsString('Loading EXIF', $shown->view(), 'before EXIF resolves the panel shows a loading notice');
    }

    public function testExifPanelShowsNoDataWhenExifIsNull(): void
    {
        $screen = $this->screen($this->album(1));
        [$loaded] = $screen->update(new PhotoExifLoadedMsg($screen->gen(), null));
        [$shown] = $loaded->update(new KeyMsg(KeyType::Char, 'i'));

        self::assertNull($shown->exif());
        self::assertStringContainsString('No EXIF data', $shown->view());
    }

    public function testTogglingExifBumpsTheGenerationAndDropsTheStaleImage(): void
    {
        $screen = $this->screen($this->album(1));
        // An image is showing at the wide width.
        [$wide] = $screen->update(new PhotoImageLoadedMsg($screen->gen(), 'WIDE'));
        self::assertTrue($wide->hasImage());
        $oldGen = $wide->gen();

        // Toggle EXIF on: the generation bumps, the image is invalidated, and a
        // reload Cmd is returned (the image reloads at the narrower width).
        [$shown, $cmd] = $wide->update(new KeyMsg(KeyType::Char, 'i'));
        self::assertTrue($shown->showExif());
        self::assertFalse($shown->hasImage(), 'the old-width image is dropped');
        self::assertGreaterThan($oldGen, $shown->gen(), 'the load generation advanced');
        self::assertInstanceOf(\Closure::class, $cmd, 'the image reloads at the new width');

        // A stale image from the OLD generation is ignored; the live one applies.
        [$afterStale] = $shown->update(new PhotoImageLoadedMsg($oldGen, 'STALE'));
        self::assertFalse($afterStale->hasImage(), 'the stale-gen image is dropped');
        [$afterLive] = $shown->update(new PhotoImageLoadedMsg($shown->gen(), 'NARROW'));
        self::assertTrue($afterLive->hasImage());
        self::assertStringContainsString('NARROW', $afterLive->view());
    }

    // ---- navigation ----------------------------------------------------

    public function testRightAndLeftMoveAndClampAtTheEnds(): void
    {
        $screen = $this->screen($this->album(3), 0);

        [$right, $rightCmd] = $screen->update(new KeyMsg(KeyType::Right));
        self::assertSame(1, $right->index());
        self::assertInstanceOf(\Closure::class, $rightCmd, 'moving loads the new photo');

        [$left] = $right->update(new KeyMsg(KeyType::Left));
        self::assertSame(0, $left->index());

        // Left at the first photo clamps (no-op).
        [$clampedLeft, $clampCmd] = $left->update(new KeyMsg(KeyType::Left));
        self::assertSame($left, $clampedLeft, 'clamped at the first photo');
        self::assertNull($clampCmd);

        // Right at the last photo clamps (no-op).
        [$last] = $screen->update(new KeyMsg(KeyType::Right));
        [$last] = $last->update(new KeyMsg(KeyType::Right));
        self::assertSame(2, $last->index());
        [$clampedRight, $rClampCmd] = $last->update(new KeyMsg(KeyType::Right));
        self::assertSame($last, $clampedRight, 'clamped at the last photo');
        self::assertNull($rClampCmd);
    }

    public function testNavigationLoadsTheNewPhotoImageAndExifDroppingStale(): void
    {
        $port = $this->startImageServer();
        // Two EXIF detail responses: p0 then p1 (distinct cameras).
        $transport = (new FakeTransport())
            ->json(200, ['photo' => ['id' => 'p0', 'name' => 'p0.jpg', 'full_url' => 'https://srv/f.png', 'exif' => ['camera_make' => 'Canon']]])
            ->json(200, ['photo' => ['id' => 'p1', 'name' => 'p1.jpg', 'full_url' => 'https://srv/f.png', 'exif' => ['camera_make' => 'Nikon']]]);
        $screen = $this->screen(
            $this->album(3, "http://127.0.0.1:{$port}/f.png"),
            store: $this->wrap($transport),
            posters: new PosterLoader(Mosaic::halfBlock()),
        );
        $this->runBatch($screen->init()); // p0 loads

        $staleGen = $screen->gen();
        [$right, $cmd] = $screen->update(new KeyMsg(KeyType::Right));
        self::assertSame(1, $right->index());
        self::assertGreaterThan($staleGen, $right->gen(), 'the generation advanced for the new photo');

        $msgs = $this->runBatch($cmd);
        $image = $this->firstOf($msgs, PhotoImageLoadedMsg::class);
        $exif = $this->firstOf($msgs, PhotoExifLoadedMsg::class);
        self::assertInstanceOf(PhotoImageLoadedMsg::class, $image);
        self::assertSame($right->gen(), $image->generation, 'the new image carries the new generation');
        self::assertInstanceOf(PhotoExifLoadedMsg::class, $exif);
        self::assertSame('Nikon', $exif->exif?->displayPairs()[0][1] ?? null, 'the new photo EXIF loaded');

        // A leftover image from the previous photo's generation is dropped.
        [$afterStale] = $right->update(new PhotoImageLoadedMsg($staleGen, 'STALE'));
        self::assertFalse($afterStale->hasImage(), 'a stale-gen image is ignored after navigating');
    }

    // ---- slideshow -----------------------------------------------------

    public function testSlideshowArmsATickThatAdvancesAndReArms(): void
    {
        $screen = $this->screen($this->album(3), 0);

        // s arms the first tick.
        [$on, $tickCmd] = $screen->update(new KeyMsg(KeyType::Char, 's'));
        self::assertTrue($on->slideshow());
        $armed = $this->slideTick($tickCmd);
        self::assertInstanceOf(PhotoSlideTickMsg::class, $armed, 'the slideshow arms a tick');
        self::assertSame($on->slideEpoch(), $armed->epoch);

        // The tick advances the index and re-arms the next (same epoch).
        [$advanced, $next] = $on->update(new PhotoSlideTickMsg($on->slideEpoch()));
        self::assertSame(1, $advanced->index());
        self::assertSame($on->slideEpoch(), $advanced->slideEpoch(), 'an auto-advance keeps the same epoch');
        $reArmed = $this->slideTick($next);
        self::assertInstanceOf(PhotoSlideTickMsg::class, $reArmed, 'the next tick is re-armed');
        self::assertSame($advanced->slideEpoch(), $reArmed->epoch);
    }

    public function testSlideshowWrapsPastTheLastPhoto(): void
    {
        $screen = $this->screen($this->album(2), 1); // start on the last photo
        [$on] = $screen->update(new KeyMsg(KeyType::Char, 's'));

        [$advanced] = $on->update(new PhotoSlideTickMsg($on->slideEpoch()));
        self::assertSame(0, $advanced->index(), 'the slideshow wraps to the first photo');
    }

    public function testTogglingTheSlideshowOffDropsThePendingTick(): void
    {
        $screen = $this->screen($this->album(3), 0);
        [$on] = $screen->update(new KeyMsg(KeyType::Char, 's'));
        $liveEpoch = $on->slideEpoch();

        // s again turns it off and bumps the epoch.
        [$off, $cmd] = $on->update(new KeyMsg(KeyType::Char, 's'));
        self::assertFalse($off->slideshow());
        self::assertNull($cmd, 'turning the slideshow off arms no tick');
        self::assertGreaterThan($liveEpoch, $off->slideEpoch(), 'the epoch bumped');

        // The pending tick from the live epoch is now stale and dropped (the
        // slideshow is off anyway).
        [$afterTick, $tickCmd] = $off->update(new PhotoSlideTickMsg($liveEpoch));
        self::assertSame($off, $afterTick, 'a tick while stopped is inert');
        self::assertNull($tickCmd);
    }

    public function testManualNavDuringSlideshowResetsTheTimer(): void
    {
        $screen = $this->screen($this->album(3), 0);
        [$on] = $screen->update(new KeyMsg(KeyType::Char, 's'));
        $oldEpoch = $on->slideEpoch();

        // Manual right while the slideshow runs: the epoch bumps (resetting the
        // countdown) and a fresh tick is armed from the new photo.
        [$moved, $cmd] = $on->update(new KeyMsg(KeyType::Right));
        self::assertSame(1, $moved->index());
        self::assertGreaterThan($oldEpoch, $moved->slideEpoch(), 'manual nav resets (bumps) the slide epoch');

        $tick = $this->slideTick($cmd);
        self::assertInstanceOf(PhotoSlideTickMsg::class, $tick, 'a fresh tick is armed');
        self::assertSame($moved->slideEpoch(), $tick->epoch);

        // The tick from the OLD epoch is now stale and dropped.
        [$afterStale, $staleCmd] = $moved->update(new PhotoSlideTickMsg($oldEpoch));
        self::assertSame($moved, $afterStale, 'a stale slide tick does not advance');
        self::assertNull($staleCmd, 'a stale slide tick does not re-arm');
    }

    public function testSlideTickOnASinglePhotoKeepsTheTimerWithoutChanging(): void
    {
        $screen = $this->screen($this->album(1), 0);
        [$on] = $screen->update(new KeyMsg(KeyType::Char, 's'));

        [$same, $cmd] = $on->update(new PhotoSlideTickMsg($on->slideEpoch()));

        self::assertSame(0, $same->index(), 'a single photo does not advance');
        $tick = $this->slideTick($cmd);
        self::assertInstanceOf(PhotoSlideTickMsg::class, $tick, 'the timer stays alive on a single photo');
        self::assertSame($on->slideEpoch(), $tick->epoch);
    }

    public function testStaleSlideTickFromASupersededEpochIsDropped(): void
    {
        $screen = $this->screen($this->album(3), 0);
        [$on] = $screen->update(new KeyMsg(KeyType::Char, 's'));
        $staleEpoch = $on->slideEpoch();

        // Toggle off then on → the epoch advances twice.
        [$off] = $on->update(new KeyMsg(KeyType::Char, 's'));
        [$reOn] = $off->update(new KeyMsg(KeyType::Char, 's'));
        self::assertGreaterThan($staleEpoch, $reOn->slideEpoch());

        [$afterStale, $cmd] = $reOn->update(new PhotoSlideTickMsg($staleEpoch));
        self::assertSame($reOn, $afterStale, 'a stale-epoch slide tick is dropped');
        self::assertNull($cmd);
    }

    public function testSlideshowCaptionShowsTheRunningIndicator(): void
    {
        $screen = $this->screen($this->album(3), 0);
        [$on] = $screen->update(new KeyMsg(KeyType::Char, 's'));

        self::assertStringContainsString('slideshow', $on->view(), 'the caption flags the running slideshow');
    }

    public function testDefaultSlideIntervalArmsAFourSecondTick(): void
    {
        $screen = $this->screen($this->album(3), 0); // no slideInterval → default 4.0
        [$on, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 's'));

        self::assertSame(4.0, $this->tickSeconds($cmd), 'the default slideshow tick fires every 4s');
    }

    public function testCustomSlideIntervalArmsATickAtThatInterval(): void
    {
        $screen = new PhotoViewerScreen(
            $this->album(3),
            0,
            $this->store(),
            new PosterLoader(Mosaic::halfBlock()),
            'https://srv',
            cols: 120,
            rows: 40,
            slideInterval: 12.5,
        );

        [$on, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 's'));
        self::assertSame(12.5, $this->tickSeconds($cmd), 'the configured interval drives the slide tick');

        // An auto-advance re-arms at the SAME custom interval.
        [, $next] = $on->update(new PhotoSlideTickMsg($on->slideEpoch()));
        self::assertSame(12.5, $this->tickSeconds($next), 'the re-armed tick keeps the custom interval');
    }

    // ---- exif auth / failure -------------------------------------------

    public function testExifAuthErrorSurfacesSessionExpired(): void
    {
        $screen = $this->screen($this->album(1), store: $this->store((new FakeTransport())->json(401, ['error' => 'unauthorized'])));

        $msgs = $this->runBatch($screen->init());

        self::assertInstanceOf(SessionExpiredMsg::class, $this->firstOf($msgs, SessionExpiredMsg::class), 'an EXIF auth error becomes a session expiry');
    }

    public function testExifNonAuthErrorBecomesNullExif(): void
    {
        $screen = $this->screen($this->album(1, null), store: $this->store((new FakeTransport())->fail(new \RuntimeException('boom'))));

        $exif = $this->firstOf($this->runBatch($screen->init()), PhotoExifLoadedMsg::class);
        self::assertInstanceOf(PhotoExifLoadedMsg::class, $exif, 'a non-auth EXIF error still resolves a (null) result');
        self::assertNull($exif->exif);

        // Applying it marks EXIF loaded with a null value → "No EXIF data".
        [$loaded] = $screen->update($exif);
        [$shown] = $loaded->update(new KeyMsg(KeyType::Char, 'i'));
        self::assertNull($shown->exif());
        self::assertStringContainsString('No EXIF data', $shown->view());
    }

    public function testStaleExifIsDropped(): void
    {
        $screen = $this->screen($this->album(1));

        [$afterStale] = $screen->update(new PhotoExifLoadedMsg($screen->gen() + 99, $this->loadedExif($screen)));

        self::assertNull($afterStale->exif(), 'a stale-gen EXIF result is ignored');
    }

    // ---- esc / resize / empty / misc -----------------------------------

    public function testEscEmitsNavigateBack(): void
    {
        [, $cmd] = $this->screen($this->album(3))->update(new KeyMsg(KeyType::Escape));

        self::assertInstanceOf(NavigateBackMsg::class, $cmd?->__invoke());
    }

    public function testResizeReloadsTheImageAtTheNewSize(): void
    {
        $screen = $this->screen($this->album(1));
        [$withImage] = $screen->update(new PhotoImageLoadedMsg($screen->gen(), 'OLD'));
        self::assertTrue($withImage->hasImage());
        $oldGen = $withImage->gen();

        [$resized, $cmd] = $withImage->update(new WindowSizeMsg(80, 24));

        self::assertInstanceOf(PhotoViewerScreen::class, $resized);
        self::assertFalse($resized->hasImage(), 'the old-size image is invalidated');
        self::assertGreaterThan($oldGen, $resized->gen(), 'the generation advanced on resize');
        self::assertInstanceOf(\Closure::class, $cmd, 'the image reloads at the new size');
        self::assertIsString($resized->view());
    }

    public function testEmptyAlbumRendersNoPhotos(): void
    {
        $screen = $this->screen($this->album(0));

        self::assertNull($screen->init(), 'nothing to load for an empty album');
        self::assertStringContainsString('No photos', $screen->view());
        self::assertNull($screen->currentPhoto());
    }

    public function testEmptyAlbumMoveIsANoOp(): void
    {
        $screen = $this->screen($this->album(0));

        [$same, $cmd] = $screen->update(new KeyMsg(KeyType::Right));

        self::assertSame($screen, $same);
        self::assertNull($cmd);
    }

    public function testUnhandledKeyAndMessageAreNoOps(): void
    {
        $screen = $this->screen($this->album(3));

        [$afterKey, $keyCmd] = $screen->update(new KeyMsg(KeyType::Tab));
        self::assertSame($screen, $afterKey);
        self::assertNull($keyCmd);

        [$afterMsg, $msgCmd] = $screen->update(new \Phlix\Console\Msg\OpenSearchMsg());
        self::assertSame($screen, $afterMsg);
        self::assertNull($msgCmd);
    }

    public function testBreadcrumbLabelIsThePhotoNameOrAlbumDate(): void
    {
        self::assertSame('p0.jpg', $this->screen($this->album(3))->crumbLabel());
        self::assertSame('2026-06-23', $this->screen($this->album(0))->crumbLabel(), 'an empty album falls back to the date');

        $view = $this->screen($this->album(3))->withCrumbs(['Home', 'Photos', '2026-06-23', 'p0.jpg'])->view();
        self::assertStringContainsString('p0.jpg', $view);
    }

    // ---- helpers -------------------------------------------------------

    /** The cached/forced EXIF from the default detail response (for direct update()s). */
    private function loadedExif(PhotoViewerScreen $screen): \Phlix\Console\Api\Dto\PhotoExif
    {
        $exif = \Phlix\Console\Api\Dto\PhotoExif::fromArray($this->exifResponse()['photo']['exif']);
        self::assertFalse($exif->isEmpty());

        return $exif;
    }

    private function wrap(FakeTransport $transport): PhotosStore
    {
        return new PhotosStore(new ApiClient('https://srv', $transport));
    }

    /**
     * Extract the {@see PhotoSlideTickMsg} a Cmd arms — handling either a bare
     * tick Cmd or a batch (image + EXIF + tick). `Cmd::tick` produces a
     * {@see \SugarCraft\Core\TickRequest} whose `produce` closure yields the Msg;
     * the batch's other children are async loads (not ticks) and are skipped.
     */
    private function slideTick(?\Closure $cmd): ?PhotoSlideTickMsg
    {
        if ($cmd === null) {
            return null;
        }

        $result = $cmd();
        $children = $result instanceof BatchMsg ? $result->cmds : [static fn () => $result];

        foreach ($children as $child) {
            $produced = $child instanceof \Closure ? $child() : $child;
            if ($produced instanceof \SugarCraft\Core\TickRequest) {
                $msg = ($produced->produce)();
                if ($msg instanceof PhotoSlideTickMsg) {
                    return $msg;
                }
            }
        }

        return null;
    }

    /**
     * The wall-clock delay (seconds) of the slide {@see \SugarCraft\Core\TickRequest}
     * a Cmd arms — handling either a bare tick Cmd or a batch (image + EXIF +
     * tick); the non-tick children are async loads and are skipped. Returns null
     * when no slide tick is present.
     */
    private function tickSeconds(?\Closure $cmd): ?float
    {
        if ($cmd === null) {
            return null;
        }

        $result = $cmd();
        $children = $result instanceof BatchMsg ? $result->cmds : [static fn () => $result];

        foreach ($children as $child) {
            $produced = $child instanceof \Closure ? $child() : $child;
            if ($produced instanceof \SugarCraft\Core\TickRequest && ($produced->produce)() instanceof PhotoSlideTickMsg) {
                return $produced->seconds;
            }
        }

        return null;
    }

    /**
     * @template T of Msg
     * @param list<Msg> $msgs
     * @param class-string<T> $class
     * @return T|null
     */
    private function firstOf(array $msgs, string $class): ?Msg
    {
        foreach ($msgs as $msg) {
            if ($msg instanceof $class) {
                return $msg;
            }
        }

        return null;
    }

    // ---- async Cmd runners (mirror AlbumScreenTest's flushing await) ----

    /** @return list<Msg> */
    private function runBatch(?\Closure $cmd): array
    {
        if ($cmd === null) {
            return [];
        }

        $result = $cmd();

        if ($result instanceof BatchMsg) {
            $msgs = [];
            foreach ($result->cmds as $child) {
                $msg = $this->runCmd($child);
                if ($msg !== null) {
                    $msgs[] = $msg;
                }
            }

            return $msgs;
        }

        if ($result instanceof AsyncCmd) {
            $msg = $this->await($result->promise);

            return $msg instanceof Msg ? [$msg] : [];
        }

        return $result instanceof Msg ? [$result] : [];
    }

    private function runCmd(\Closure $cmd): ?Msg
    {
        $result = $cmd();
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
            // The promise settled synchronously (the PhotosStore wraps the sync
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

    private function startImageServer(): int
    {
        $img = imagecreatetruecolor(16, 12);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 70, 120, 180));
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        $server = new HttpServer(static function (ServerRequestInterface $r) use ($png): Response {
            return str_contains((string) $r->getUri()->getPath(), 'nope')
                ? new Response(404, [], 'not found')
                : new Response(200, ['Content-Type' => 'image/png'], $png);
        });
        $this->socket = new SocketServer('127.0.0.1:0');
        $server->listen($this->socket);

        return (int) parse_url((string) $this->socket->getAddress(), PHP_URL_PORT);
    }
}
