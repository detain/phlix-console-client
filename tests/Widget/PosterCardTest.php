<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Widget;

use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Widget\PosterCard;
use PHPUnit\Framework\TestCase;

final class PosterCardTest extends TestCase
{
    public function testFromMediaItem(): void
    {
        $item = MediaItem::fromArray(['id' => 'm1', 'name' => 'The Matrix', 'poster_url' => 'https://p/1.jpg']);
        $card = PosterCard::fromMediaItem($item, 0.4);

        self::assertSame('m1', $card->id);
        self::assertSame('The Matrix', $card->title);
        self::assertSame('https://p/1.jpg', $card->posterUrl);
        self::assertSame(0.4, $card->progress);
        self::assertFalse($card->hasPoster());
    }

    public function testPlaceholderRenderHasPosterRowsPlusTitle(): void
    {
        $card = new PosterCard('m', 'Title');

        $render = $card->render(false, 10, 5);
        $lines = explode("\n", $render);

        self::assertCount(6, $lines, '5 placeholder rows + 1 title row');
        self::assertStringContainsString('Title', $render);
        self::assertStringContainsString('░', $lines[0]);
    }

    public function testRenderWithLoadedPosterUsesItsLines(): void
    {
        $card = (new PosterCard('m', 'T'))->withPoster("AAAA\nBBBB\nCCCC");

        self::assertTrue($card->hasPoster());
        $render = $card->render(false, 4, 3);
        self::assertStringContainsString('AAAA', $render);
        self::assertStringContainsString('CCCC', $render);
    }

    public function testFocusedRenderHasMarker(): void
    {
        $render = (new PosterCard('m', 'T'))->render(true, 10, 2);

        self::assertStringContainsString('▸', $render);
    }

    public function testProgressRowRenders(): void
    {
        $render = (new PosterCard('m', 'T', null, 0.5))->render(false, 8, 2);
        $lines = explode("\n", $render);

        $bar = $lines[array_key_last($lines)];
        self::assertStringContainsString('▓', $bar);
        self::assertStringContainsString('░', $bar);
    }

    public function testLongTitleIsTruncated(): void
    {
        $render = (new PosterCard('m', 'A Really Very Long Movie Title'))->render(false, 12, 1);

        self::assertStringContainsString('…', $render);
        // Title row must not exceed the card width.
        foreach (explode("\n", $render) as $line) {
            self::assertLessThanOrEqual(12, mb_strlen($line));
        }
    }
}
