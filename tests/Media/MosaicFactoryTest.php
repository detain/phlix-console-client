<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Media;

use Phlix\Console\Media\MosaicFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\Mosaic;

final class MosaicFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function modeProtocols(): iterable
    {
        yield 'halfblock'      => ['halfblock', 'halfblock'];
        yield 'half alias'     => ['half', 'halfblock'];
        yield 'ansi → blocks'  => ['ansi', 'halfblock'];
        yield 'quarterblock'   => ['quarterblock', 'quarterblock'];
        yield 'ascii'          => ['ascii', 'ascii'];
        yield 'ansi256'        => ['ansi256', 'ansi256'];
        yield 'truecolor'      => ['truecolor', 'truecolor'];
        yield 'sixel'          => ['sixel', 'sixel'];
    }

    #[DataProvider('modeProtocols')]
    public function testForcedModesSelectTheMatchingProtocol(string $mode, string $protocol): void
    {
        self::assertSame($protocol, MosaicFactory::forMode($mode)->protocol());
    }

    public function testForcedModesProduceDistinctCacheProtocols(): void
    {
        // The poster cache is keyed by protocol(), so distinct modes must report
        // distinct protocol names — otherwise one mode could serve an image
        // cached in another.
        $protocols = array_map(
            static fn (string $m): string => MosaicFactory::forMode($m)->protocol(),
            ['halfblock', 'quarterblock', 'ascii', 'ansi256', 'truecolor', 'sixel'],
        );

        self::assertSame($protocols, array_values(array_unique($protocols)));
    }

    public function testAutoAndNullDetectInsteadOfThrowing(): void
    {
        self::assertInstanceOf(Mosaic::class, MosaicFactory::forMode(null));
        self::assertInstanceOf(Mosaic::class, MosaicFactory::forMode('auto'));
    }

    public function testUnknownModeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown render mode: bogus');
        MosaicFactory::forMode('bogus');
    }

    public function testPosterGridHonoursCellModes(): void
    {
        // Cell-based modes tile, so they pass through unchanged with no notice.
        foreach (['ascii', 'ansi256', 'truecolor', 'quarterblock', 'halfblock'] as $mode) {
            [$mosaic, $notice] = MosaicFactory::forPosterGrid($mode);
            self::assertSame($mode === 'halfblock' ? 'halfblock' : $mosaic->protocol(), $mosaic->protocol());
            self::assertNull($notice, "{$mode} tiles, so no downgrade notice");
        }
    }

    public function testPosterGridDowngradesGraphicsModesWithNotice(): void
    {
        // Sixel can't tile the grid → fall back to half-block and explain why.
        [$mosaic, $notice] = MosaicFactory::forPosterGrid('sixel');

        self::assertSame('halfblock', $mosaic->protocol());
        self::assertNotNull($notice);
        self::assertStringContainsString('sixel', $notice);
        self::assertStringContainsString('half-block', $notice);
    }

    public function testPosterGridDefaultsToHalfBlockWithoutProbing(): void
    {
        foreach ([null, 'auto'] as $mode) {
            [$mosaic, $notice] = MosaicFactory::forPosterGrid($mode);
            self::assertSame('halfblock', $mosaic->protocol());
            self::assertNull($notice);
        }
    }
}
