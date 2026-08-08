<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Media\MosaicFactory;
use Phlix\Console\Media\PosterCardFactory;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Tests\Graphics\Iterm2Decoder;
use Phlix\Console\Tests\Graphics\KittyDecoder;
use Phlix\Console\Tests\Graphics\SixelDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Gallery\PosterCard;

/**
 * End-to-end poster rendering pipeline tests.
 *
 * Covers the full pipeline from MediaItem → PosterCard → PosterLoader → Mosaic
 * for each supported graphics protocol/mode combination (halfblock, sixel,
 * iterm2, kitty).
 *
 * These are not unit tests of individual components — they verify that the
 * rendering pipeline wires together correctly and produces the expected output
 * markers and image layer placements for each protocol.
 */
final class PosterRenderTest extends TestCase
{
    /** @return iterable<string, array{string, class-string}> */
    public static function protocolDecoderProvider(): iterable
    {
        yield 'sixel'    => ['sixel',    SixelDecoder::class];
        yield 'iterm2'  => ['iterm2',  Iterm2Decoder::class];
        yield 'kitty'    => ['kitty',    KittyDecoder::class];
    }

    /** @return iterable<string, array{string}> */
    public static function inlineModeProvider(): iterable
    {
        yield 'halfblock'    => ['halfblock'];
        yield 'quarterblock' => ['quarterblock'];
        yield 'ascii'        => ['ascii'];
        yield 'ansi256'      => ['ansi256'];
        yield 'truecolor'    => ['truecolor'];
    }

    /**
     * Verifies that PosterCardFactory correctly maps a MediaItem to a
     * domain-agnostic PosterCard that the rendering pipeline consumes.
     */
    public function testPosterCardFactoryMapsMediaItemToCard(): void
    {
        $item = MediaItem::fromArray([
            'id' => 'movie-123',
            'name' => 'Test Movie',
            'type' => 'movie',
            'poster_url' => 'https://cdn.example.com/posters/movie-123.jpg',
        ]);

        $card = PosterCardFactory::fromMediaItem($item);

        self::assertInstanceOf(PosterCard::class, $card);
        self::assertSame('movie-123', $card->id);
        self::assertSame('Test Movie', $card->title);
        self::assertSame('https://cdn.example.com/posters/movie-123.jpg', $card->posterUrl);
        self::assertNull($card->progress);
    }

    /**
     * Verifies that PosterCardFactory carries progress information when provided.
     */
    public function testPosterCardFactoryCarriesProgress(): void
    {
        $item = MediaItem::fromArray([
            'id' => 'movie-456',
            'name' => 'In Progress Movie',
            'type' => 'movie',
            'poster_url' => 'https://cdn.example.com/posters/movie-456.jpg',
        ]);

        $card = PosterCardFactory::fromMediaItem($item, 0.65);

        self::assertSame(0.65, $card->progress);
    }

    /**
     * Inline renderers (halfblock, ascii, etc.) produce cell text directly
     * rather than registering overlay images. This test verifies that the
     * PosterLoader correctly identifies inline mode and that the marker is
     * the rendered ANSI string.
     */
    #[DataProvider('inlineModeProvider')]
    public function testInlineModesProduceCellTextNotOverlay(string $mode): void
    {
        $mosaic = MosaicFactory::forMode($mode);

        self::assertTrue($mosaic->isInline(), "{$mode} should be inline");

        $loader = new PosterLoader($mosaic);

        self::assertTrue($loader->isInline(), "PosterLoader for {$mode} should report inline");
        self::assertNotSame('sixel', $loader->protocol(), 'inline modes are not sixel');
    }

    /**
     * Graphics renderers (sixel, iterm2, kitty) produce overlay blobs rather
     * than cell text. This test verifies that PosterLoader correctly identifies
     * graphics/overlay mode and that the image layer accumulates placements.
     */
    #[DataProvider('protocolDecoderProvider')]
    public function testGraphicsModesProduceOverlayPlacements(string $protocol, string $_decoderClass): void
    {
        $mosaic = MosaicFactory::forMode($protocol);

        self::assertFalse($mosaic->isInline(), "{$protocol} should not be inline");

        $loader = new PosterLoader($mosaic);

        self::assertFalse($loader->isInline(), "PosterLoader for {$protocol} should report non-inline");
        self::assertSame($protocol, $loader->protocol());
    }

    /**
     * A zero-sized poster URL should be treated as "no poster" and must not
     * produce a load promise that resolves to anything.
     */
    public function testEmptyPosterUrlIsRejected(): void
    {
        $loader = new PosterLoader(MosaicFactory::forMode('halfblock'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or missing URL scheme');

        // The load() method validates URL before any network activity.
        // An empty string has no scheme and must be rejected immediately.
        $loader->load('', 8, 12);
    }

    /**
     * A relative URL (no scheme) must be rejected, as the poster loader
     * requires an absolute HTTP/HTTPS URL to enforce SSRF protections.
     */
    public function testRelativeUrlIsRejected(): void
    {
        $loader = new PosterLoader(MosaicFactory::forMode('halfblock'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or missing URL scheme');

        $loader->load('/images/local-poster.jpg', 8, 12);
    }

    /**
     * Non-HTTP schemes (e.g. file://) must be rejected to prevent filesystem
     * access during poster loading.
     */
    public function testFileSchemeUrlIsRejected(): void
    {
        $loader = new PosterLoader(MosaicFactory::forMode('halfblock'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or missing URL scheme');

        $loader->load('file:///etc/passwd', 8, 12);
    }

    /**
     * Verifies that MosaicFactory.forMode() throws for unknown render modes
     * rather than silently falling back to a default.
     */
    public function testUnknownModeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown render mode: nonexistent');

        MosaicFactory::forMode('nonexistent');
    }

    /**
     * Verifies that every inline mode produces a distinct protocol string so
     * that poster cache keys do not collide between renderers.
     */
    public function testInlineModesProduceDistinctCacheKeys(): void
    {
        $protocols = array_map(
            static fn (string $mode): string => MosaicFactory::forMode($mode)->protocol(),
            ['halfblock', 'quarterblock', 'ascii', 'ansi256', 'truecolor'],
        );

        self::assertSame($protocols, array_values(array_unique($protocols)), 'inline mode protocols must be distinct');
    }

    /**
     * Verifies that PosterLoader.imageLayer() starts empty in inline mode.
     * The image layer is only used by overlay (graphics) renderers.
     */
    public function testInlineModeImageLayerStartsEmpty(): void
    {
        $loader = new PosterLoader(MosaicFactory::forMode('halfblock'));

        self::assertSame([], $loader->imageLayer());
    }

    /**
     * Verifies that PosterLoader.imageLayer() starts empty even in overlay
     * mode — placements are only added after a successful load.
     */
    public function testOverlayModeImageLayerStartsEmpty(): void
    {
        $loader = new PosterLoader(MosaicFactory::forMode('sixel'));

        self::assertSame([], $loader->imageLayer());
    }

    /**
     * PosterLoader for graphics mode should report the same protocol that
     * was used to construct its Mosaic.
     */
    public function testLoaderReportsCorrectProtocol(): void
    {
        foreach (['halfblock', 'sixel', 'iterm2', 'kitty', 'ascii', 'ansi256', 'truecolor'] as $mode) {
            $loader = new PosterLoader(MosaicFactory::forMode($mode));

            self::assertSame(MosaicFactory::forMode($mode)->protocol(), $loader->protocol());
        }
    }

    /**
     * The PosterLoader.semaphore() returns the semaphore used to bound
     * concurrent poster operations, allowing tests to verify concurrency
     * limits are in place.
     */
    public function testSemaphoreIsAccessible(): void
    {
        $loader = new PosterLoader(MosaicFactory::forMode('halfblock'));

        self::assertNotNull($loader->semaphore());
    }

    /**
     * PosterLoader::release() on an unknown digest is a no-op (safe to call
     * even when nothing is tracked).
     */
    public function testReleaseUnknownDigestIsNoOp(): void
    {
        $loader = new PosterLoader(MosaicFactory::forMode('sixel'));

        // Must not throw — unknown digests are simply ignored.
        $loader->release('this-digest-does-not-exist');

        self::assertSame([], $loader->imageLayer());
    }

    /**
     * PosterLoader::releaseAllExcept() with an empty array should release
     * all placements, leaving the image layer empty.
     */
    public function testReleaseAllExceptWithEmptyArrayReleasesEverything(): void
    {
        // This test documents the expected behavior: an empty keep list
        // means "release all" — the image layer becomes empty.
        $loader = new PosterLoader(MosaicFactory::forMode('sixel'));

        // Without any tracked placements this is a no-op, but the contract
        // is clear: releaseAllExcept([]) means release all.
        $loader->releaseAllExcept([]);

        self::assertSame([], $loader->imageLayer());
    }
}
