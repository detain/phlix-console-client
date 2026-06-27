<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Media;

use Phlix\Console\Media\MosaicFactory;
use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\Mosaic;

final class MosaicFactoryTest extends TestCase
{
    public function testForcedModesSelectTheMatchingProtocol(): void
    {
        self::assertSame('sixel', MosaicFactory::forMode('sixel')->protocol());
        self::assertSame('halfblock', MosaicFactory::forMode('halfblock')->protocol());
        self::assertSame('halfblock', MosaicFactory::forMode('half')->protocol());
        self::assertSame('kitty', MosaicFactory::forMode('kitty')->protocol());
    }

    public function testForcedModesProduceDistinctCacheProtocols(): void
    {
        // The poster cache is keyed by protocol(), so distinct modes must report
        // distinct protocol names — otherwise a sixel run could serve a
        // half-block image cached in another mode.
        $protocols = [
            MosaicFactory::forMode('sixel')->protocol(),
            MosaicFactory::forMode('halfblock')->protocol(),
            MosaicFactory::forMode('kitty')->protocol(),
        ];

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
}
