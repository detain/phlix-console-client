<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Tests\Graphics;

use Phlix\Console\Media\MosaicFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Testing\Graphics\GraphicsAssertions;
use SugarCraft\Testing\Graphics\Iterm2Stream;
use SugarCraft\Testing\Graphics\KittyStream;
use SugarCraft\Testing\Graphics\Mode;
use SugarCraft\Testing\Graphics\SixelStream;

/**
 * Asserts the client's graphics-mode mosaics emit *real protocol bytes*.
 *
 * These replace hand-made Sixel/Kitty/Iterm2 "decoder" doubles that merely
 * implemented the sugar-reel Decoder interface over canned frames. Driving the
 * actual candy-mosaic renderer and decoding the result with candy-testing's
 * protocol streams proves the bytes the terminal would receive are well-formed
 * and carry the intended raster — which a hand-made double never could.
 */
final class PosterGraphicsStreamTest extends TestCase
{
    /** @return iterable<string, array{string, Mode}> */
    public static function graphicsModeProvider(): iterable
    {
        yield 'sixel'  => ['sixel', Mode::Sixel];
        yield 'kitty'  => ['kitty', Mode::Kitty];
        yield 'iterm2' => ['iterm2', Mode::Iterm2];
    }

    /**
     * A poster is portrait: the cell box it lands in is taller than wide, and
     * every protocol must still emit a decodable, non-empty raster.
     */
    #[DataProvider('graphicsModeProvider')]
    public function testEmittedStreamRoundTripsThroughItsOwnProtocolDecoder(string $mode, Mode $protocol): void
    {
        $stream = $this->renderPoster($mode);

        self::assertNotSame('', $stream, "{$mode} emitted no bytes");
        GraphicsAssertions::assertProtocolRoundTrip($stream, $protocol);
    }

    /**
     * The emitted bytes must actually speak the requested protocol — a mode that
     * silently fell back to another renderer would otherwise look green here.
     */
    #[DataProvider('graphicsModeProvider')]
    public function testStreamIsSniffedAsTheModeItWasRenderedIn(string $mode, Mode $expected): void
    {
        self::assertSame($expected, Mode::detect($this->renderPoster($mode)));
    }

    /**
     * Fill scaling crops to the cell box, so the raster the terminal allocates
     * must match the requested cell geometry exactly — never the source aspect.
     *
     * Sixel is painted per-pixel and so reports cells × cell-box (12×10 by 6×20).
     * Kitty and iTerm2 hand the terminal a PNG and let it scale, so the wire
     * bytes carry the source raster plus a 12×6 cell hint; asserting the emitted
     * cell geometry is what pins the intent for those two.
     *
     * @return iterable<string, array{string, Mode, int|null, int|null, int|null, int|null}>
     */
    public static function graphicsGeometryProvider(): iterable
    {
        yield 'sixel'  => ['sixel', Mode::Sixel, 120, 120, null, null];
        yield 'kitty'  => ['kitty', Mode::Kitty, 40, 80, 12, 6];
        yield 'iterm2' => ['iterm2', Mode::Iterm2, 40, 80, 12, 6];
    }

    #[DataProvider('graphicsGeometryProvider')]
    public function testRasterMatchesRequestedCellGeometry(
        string $mode,
        Mode $protocol,
        ?int $expectedWidth,
        ?int $expectedHeight,
        ?int $expectedCellsWide,
        ?int $expectedCellsTall,
    ): void {
        $stream = MosaicFactory::forMode($mode)->render($this->posterSource(), 12, 6);

        GraphicsAssertions::assertInlineImageDimensions($stream, $protocol, $expectedWidth, $expectedHeight);

        if ($expectedCellsWide === null || $expectedCellsTall === null) {
            return;
        }

        $grid = GraphicsAssertions::renderGrid($stream, $protocol);

        self::assertNotEmpty($grid->paintedPixels(), 'raster decoded with nothing painted');

        if ($protocol === Mode::Kitty) {
            self::assertSame($expectedCellsWide, KittyStream::decode($stream)->cols());
            self::assertSame($expectedCellsTall, KittyStream::decode($stream)->rows());

            return;
        }

        $iterm2 = Iterm2Stream::decode($stream);
        self::assertSame($expectedCellsWide, $iterm2->cellsWidth());
        self::assertSame($expectedCellsTall, $iterm2->cellsHeight());
    }

    /**
     * Sixel is palette-quantised; a two-tone poster must come back with those
     * two colours defined and referenced, proving the band actually encoded.
     */
    public function testSixelCarriesThePosterColoursInItsPalette(): void
    {
        $sixel = SixelStream::decode(MosaicFactory::forMode('sixel')->render($this->posterSource(), 12, 6));

        $palette = array_values($sixel->palette());
        $nearestToRed = self::nearestPaletteEntry($palette, [200, 30, 30]);

        self::assertNotNull($nearestToRed, 'no palette entry at all');
        self::assertLessThan(
            40,
            self::channelDistance($nearestToRed, [200, 30, 30]),
            'the poster red is missing from the sixel palette',
        );
    }

    /**
     * Render a poster through the client's own factory.
     *
     * Deliberately a small cell box: the sixel round-trip assertion walks every
     * painted pixel, and a realistic poster would dominate the whole suite's
     * assertion count for no extra signal.
     */
    private function renderPoster(string $mode, int $cells = 4, int $rows = 2): string
    {
        return MosaicFactory::forMode($mode)->render($this->posterSource(), $cells, $rows);
    }

    /**
     * A tall two-tone poster image, as the rails would request one.
     */
    private function posterSource(): ImageSource
    {
        $image = imagecreatetruecolor(40, 80);
        imagefilledrectangle($image, 0, 0, 39, 39, imagecolorallocate($image, 200, 30, 30));
        imagefilledrectangle($image, 0, 40, 39, 79, imagecolorallocate($image, 20, 40, 180));

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return ImageSource::fromString($png);
    }

    /**
     * @param list<array{int, int, int}> $palette
     * @param array{int, int, int}       $rgb
     *
     * @return array{int, int, int}|null
     */
    private static function nearestPaletteEntry(array $palette, array $rgb): ?array
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($palette as $entry) {
            $distance = self::channelDistance($entry, $rgb);
            if ($distance < $bestDistance) {
                $best = $entry;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * Sum of per-channel deviations between two colours.
     *
     * @param array{int, int, int} $a
     * @param array{int, int, int} $b
     */
    private static function channelDistance(array $a, array $b): int
    {
        return abs($a[0] - $b[0]) + abs($a[1] - $b[1]) + abs($a[2] - $b[2]);
    }
}
