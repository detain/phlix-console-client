<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Media;

use Phlix\Console\Media\MosaicFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
        self::assertSame($protocol, MosaicFactory::modeName(MosaicFactory::forMode($mode)));
    }

    public function testForcedModesProduceDistinctCacheProtocols(): void
    {
        // The poster cache is keyed by the render mode, so distinct modes must report
        // distinct protocol names — otherwise one mode could serve an image
        // cached in another.
        $protocols = array_map(
            static fn (string $m): string => MosaicFactory::modeName(MosaicFactory::forMode($m)),
            ['halfblock', 'quarterblock', 'ascii', 'ansi256', 'truecolor', 'sixel'],
        );

        self::assertSame($protocols, array_values(array_unique($protocols)));
    }

    public function testAutoAndNullDefaultToTheUniversalHalfBlockRenderer(): void
    {
        // [review console__qa CONSOLIDATED M17] `Mosaic::auto()` probed the
        // terminal and, on tmux/generic hosts, returned a renderer whose
        // output buried the caller under tens of thousands of malformed
        // lines — the spike's default must be the safe cell renderer the
        // manual advertises, with graphics protocols reachable explicitly.
        // Terminal *reporting* keeps the probe: `doctor` delegates to
        // Mosaic::auto() via Capabilities (pinned in TerminalGraphicsDocsTest).
        foreach ([null, 'auto'] as $mode) {
            self::assertSame(
                'halfblock',
                MosaicFactory::modeName(MosaicFactory::forMode($mode)),
                'the unforced poster render mode must resolve to the universal half-block renderer',
            );
        }
    }

    public function testUnknownModeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown render mode: bogus');
        MosaicFactory::forMode('bogus');
    }

    public function testPosterGridHonoursCellModes(): void
    {
        // Cell-based modes tile as text (Mosaic::isInline() drives routing).
        foreach (['ascii', 'ansi256', 'truecolor', 'quarterblock', 'halfblock'] as $mode) {
            $mosaic = MosaicFactory::forPosterGrid($mode);
            self::assertSame($mode, MosaicFactory::modeName($mosaic));
            self::assertTrue($mosaic->isInline(), "{$mode} is a cell renderer");
        }
    }

    public function testPosterGridKeepsGraphicsModes(): void
    {
        // Graphics modes are kept (they tile via the image overlay), not downgraded.
        $mosaic = MosaicFactory::forPosterGrid('sixel');

        self::assertSame('sixel', MosaicFactory::modeName($mosaic));
        self::assertFalse($mosaic->isInline(), 'sixel is painted as an overlay');
    }

    public function testPosterGridDefaultsToInlineHalfBlock(): void
    {
        foreach ([null, 'auto'] as $mode) {
            $mosaic = MosaicFactory::forPosterGrid($mode);
            self::assertSame('halfblock', MosaicFactory::modeName($mosaic));
            self::assertTrue($mosaic->isInline());
        }
    }
}
