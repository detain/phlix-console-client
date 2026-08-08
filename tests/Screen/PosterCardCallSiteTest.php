<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\GridPosterLoadedMsg;
use Phlix\Console\Msg\MediaRangeLoadedMsg;
use Phlix\Console\Msg\SearchDebouncedMsg;
use Phlix\Console\Screen\LibraryScreen;
use Phlix\Console\Screen\SearchScreen;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\ImagePlacement;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Mosaic\ImageLayer;
use SugarCraft\Mosaic\Mosaic;

/**
 * Regression tests ensuring that {@see \SugarCraft\Gallery\PosterCard::withImage()}
 * is called with actual pixel bytes from the poster loader's image layer, NOT with
 * the ANSI marker block that {@see GridPosterLoadedMsg::$ansi} carries.
 *
 * @see https://github.com/phlix/phlix-console-client/issues/C4.7
 */
final class PosterCardCallSiteTest extends TestCase
{
    /**
     * Seeds the given PosterLoader's internal image layer with a single
     * {@see ImagePlacement} at the requested id, bypassing the normal async
     * load pipeline so we can test the call-site argument directly.
     *
     * @param PosterLoader&object $loader
     */
    private static function seedImageLayer(object $loader, int $imageId, string $bytes): void
    {
        $imagesProp = (new \ReflectionProperty($loader, 'images'));
        $imagesProp->setAccessible(true);
        /** @var ImageLayer $images */
        $images = $imagesProp->getValue($loader);

        $placementProp = (new \ReflectionProperty($images, 'placementById'));
        $placementProp->setAccessible(true);
        $placements = $placementProp->getValue($images);
        $placements[$imageId] = new ImagePlacement($bytes, 14, 9);
        $placementProp->setValue($images, $placements);
    }

    /**
     * Builds a one-item media page that the screen will consume.
     *
     * @return array{items: list<array{id:string,name:string,type:string,poster_url:string}>,total:int,limit:int,offset:int}
     */
    private function pageResponse(): array
    {
        return [
            'items' => [['id' => '0', 'name' => 'Movie', 'type' => 'movie', 'poster_url' => 'https://p/0.jpg']],
            'total' => 1,
            'limit' => 50,
            'offset' => 0,
        ];
    }

    // -------------------------------------------------------------------------
    // LibraryScreen
    // -------------------------------------------------------------------------

    private function libraryScreenWith(FakeTransport $transport, PosterLoader $posters): LibraryScreen
    {
        $api = new ApiClient('https://srv', $transport);

        return new LibraryScreen(
            'lib-a',
            'Movies',
            new MediaStore($api),
            $posters,
            'https://srv',
            cols: 120,
            rows: 40,
        );
    }

    /**
     * @dataProvider libraryScreenOverlayModesProvider
     */
    public function testLibraryScreenPassesPixelBytesToWithImageNotMarkerBlock(Mosaic $mosaic): void
    {
        $transport = (new FakeTransport())->json(200, $this->pageResponse());
        $loader = new PosterLoader($mosaic);

        // Pre-populate image id 3 with known pixel bytes.
        $expectedBytes = "\x1bP0;0;0;s14;9#0;0;0;0;0;0;0;0;0MM\xb0\x1b\\";
        self::seedImageLayer($loader, 3, $expectedBytes);

        $screen = $this->libraryScreenWith($transport, $loader);

        // Prime the screen with the range so the grid exists.
        $range = $this->runBatch($screen->init())[0];
        [$loaded] = $screen->update($range);

        // Dispatch a poster-loaded message with imageId=3 and a marker block.
        // The marker is NOT what should be passed to withImage().
        $markerBlock = "\x1b[0;1;0m░░░\x1b[0m";
        [$next] = $loaded->update(new GridPosterLoadedMsg(0, $markerBlock, 3, 'digest'));

        // The card at index 0 must carry the pixel bytes, not the marker.
        $card = $next->grid()->item(0);
        self::assertNotNull($card);
        self::assertSame($expectedBytes, $card->posterImage, 'withImage must receive pixel bytes from imageLayer, not the ANSI marker block');
        self::assertSame(3, $card->imageId);
    }

    /** @return array<array{Mosaic}> */
    public static function libraryScreenOverlayModesProvider(): array
    {
        return [
            'sixel' => [Mosaic::sixel()],
            'iterm2' => [Mosaic::iterm2()],
        ];
    }

    public function testLibraryScreenFallsBackToMarkerWhenImageIdNotInLayer(): void
    {
        $transport = (new FakeTransport())->json(200, $this->pageResponse());
        $loader = new PosterLoader(Mosaic::sixel()); // overlay mode

        // No images seeded — imageId 99 will not be found.
        $screen = $this->libraryScreenWith($transport, $loader);

        $range = $this->runBatch($screen->init())[0];
        [$loaded] = $screen->update($range);

        $markerBlock = '░░░';
        [$next] = $loaded->update(new GridPosterLoadedMsg(0, $markerBlock, 99, 'digest'));

        $card = $next->grid()->item(0);
        self::assertNotNull($card);
        // Falls back to the marker block when imageId is not in the layer.
        self::assertSame($markerBlock, $card->posterImage);
        self::assertSame(99, $card->imageId);
    }

    // -------------------------------------------------------------------------
    // SearchScreen
    // -------------------------------------------------------------------------

    private function searchScreenWith(FakeTransport $transport, PosterLoader $posters): SearchScreen
    {
        $api = new ApiClient('https://srv', $transport);

        return new SearchScreen(
            new MediaStore($api),
            $posters,
            'https://srv',
            cols: 120,
            rows: 40,
        );
    }

    /**
     * @dataProvider searchScreenOverlayModesProvider
     */
    public function testSearchScreenPassesPixelBytesToWithImageNotMarkerBlock(Mosaic $mosaic): void
    {
        $transport = (new FakeTransport())->json(200, $this->pageResponse());
        $loader = new PosterLoader($mosaic);

        $expectedBytes = "\x1bP0;0;0;s14;9#0;0;0;0;0;0;0;0;0MM\xb0\x1b\\";
        self::seedImageLayer($loader, 7, $expectedBytes);

        $screen = $this->searchScreenWith($transport, $loader);

        // SearchScreen.init() returns null (no fetch until user types).
        // Simulate the search flow: type a char, fire debounce, run fetch.
        [$typed] = $screen->update(new KeyMsg(KeyType::Char, 'm'));
        [$typed, $fetch] = $typed->update(new SearchDebouncedMsg(1));
        self::assertInstanceOf(\Closure::class, $fetch);
        $range = $this->runCmd($fetch);
        self::assertInstanceOf(\Phlix\Console\Msg\MediaRangeLoadedMsg::class, $range);
        [$loaded] = $typed->update($range);

        // Now the grid has items — dispatch GridPosterLoadedMsg to trigger onPoster.
        $markerBlock = "\x1b[0;1;0m▓▓▓\x1b[0m";
        [$next] = $loaded->update(new GridPosterLoadedMsg(0, $markerBlock, 7, 'digest'));

        $card = $next->grid()->item(0);
        self::assertNotNull($card);
        self::assertSame($expectedBytes, $card->posterImage, 'withImage must receive pixel bytes from imageLayer, not the ANSI marker block');
        self::assertSame(7, $card->imageId);
    }

    /** @return array<array{Mosaic}> */
    public static function searchScreenOverlayModesProvider(): array
    {
        return [
            'sixel' => [Mosaic::sixel()],
            'iterm2' => [Mosaic::iterm2()],
        ];
    }

    public function testSearchScreenFallsBackToMarkerWhenImageIdNotInLayer(): void
    {
        $transport = (new FakeTransport())->json(200, $this->pageResponse());
        $loader = new PosterLoader(Mosaic::sixel());

        $screen = $this->searchScreenWith($transport, $loader);

        // SearchScreen.init() returns null (no fetch until user types).
        // Simulate the search flow: type a char, fire debounce, run fetch.
        [$typed] = $screen->update(new KeyMsg(KeyType::Char, 'm'));
        [$typed, $fetch] = $typed->update(new SearchDebouncedMsg(1));
        $range = $this->runCmd($fetch);
        [$loaded] = $typed->update($range);

        // No images seeded — imageId 55 will not be found in the layer.
        $markerBlock = '▓▓▓';
        [$next] = $loaded->update(new GridPosterLoadedMsg(0, $markerBlock, 55, 'digest'));

        $card = $next->grid()->item(0);
        self::assertNotNull($card);
        self::assertSame($markerBlock, $card->posterImage);
        self::assertSame(55, $card->imageId);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Runs a batch of async commands and returns the resulting messages.
     * Mirrors the pattern used by LibraryScreenTest to properly handle BatchMsg.
     *
     * @return list<Msg>
     */
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
        $timer = null;
        $settle = static function () use (&$timer): void {
            if ($timer !== null) {
                Loop::cancelTimer($timer);
                $timer = null;
            }
            Loop::stop();
        };
        $promise->then(
            function ($v) use (&$state, $settle): void {
                $state['value'] = $v;
                $state['done'] = true;
                $settle();
            },
            function ($e) use (&$state, $settle): void {
                $state['error'] = $e;
                $state['done'] = true;
                $settle();
            },
        );

        if (!$state['done']) {
            $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
            Loop::run();
            if ($timer !== null) {
                Loop::cancelTimer($timer);
            }
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
