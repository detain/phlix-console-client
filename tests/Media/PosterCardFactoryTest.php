<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Media;

use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Media\PosterCardFactory;
use PHPUnit\Framework\TestCase;
use SugarCraft\Gallery\PosterCard;

final class PosterCardFactoryTest extends TestCase
{
    private function item(): MediaItem
    {
        return MediaItem::fromArray([
            'id' => 'm1',
            'name' => 'The Matrix',
            'type' => 'movie',
            'poster_url' => 'https://p/1.jpg',
        ]);
    }

    public function testMapsMediaItemToGalleryCard(): void
    {
        $card = PosterCardFactory::fromMediaItem($this->item());

        self::assertInstanceOf(PosterCard::class, $card);
        self::assertSame('m1', $card->id);
        self::assertSame('The Matrix', $card->title);
        self::assertSame('https://p/1.jpg', $card->posterUrl);
        self::assertNull($card->progress);
    }

    public function testCarriesProgressWhenGiven(): void
    {
        $card = PosterCardFactory::fromMediaItem($this->item(), 0.42);

        self::assertSame(0.42, $card->progress);
    }
}
