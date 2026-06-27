<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\Profile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProfileTest extends TestCase
{
    public function testMapsAFullHydratedRow(): void
    {
        $profile = Profile::fromArray([
            'id' => 7,
            'user_id' => 3,
            'name' => 'Kids',
            'avatar_url' => 'a.png',
            'is_active' => 1,
            'is_admin' => 0,
            'content_rating' => 'PG',
            'pin_required_for_admin' => true,
            'max_daily_watch_time' => 120,
            'allow_unrated' => false,
        ]);

        self::assertSame('7', $profile->id);
        self::assertSame('Kids', $profile->name);
        self::assertSame('PG', $profile->contentRating);
        self::assertTrue($profile->isActive);
        self::assertSame(120, $profile->maxDailyWatchTime);
        self::assertTrue($profile->pinRequiredForAdmin);
    }

    public function testToleratesMissingKeysWithSensibleDefaults(): void
    {
        $profile = Profile::fromArray([]);

        self::assertSame('', $profile->id);
        self::assertSame('', $profile->name);
        self::assertSame('R', $profile->contentRating, 'a missing rating defaults to R');
        self::assertFalse($profile->isActive);
        self::assertSame(0, $profile->maxDailyWatchTime);
        self::assertFalse($profile->pinRequiredForAdmin);
    }

    public function testCoercesTinyIntFlags(): void
    {
        $active = Profile::fromArray(['is_active' => 1, 'pin_required_for_admin' => '1']);
        self::assertTrue($active->isActive);
        self::assertTrue($active->pinRequiredForAdmin);

        $inactive = Profile::fromArray(['is_active' => 0, 'pin_required_for_admin' => 0]);
        self::assertFalse($inactive->isActive);
        self::assertFalse($inactive->pinRequiredForAdmin);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function ratingProvider(): iterable
    {
        yield 'G' => ['G', 0];
        yield 'PG' => ['PG', 1];
        yield 'PG-13' => ['PG-13', 2];
        yield 'R' => ['R', 3];
        yield 'NC-17' => ['NC-17', 4];
        yield 'X' => ['X', 5];
        yield 'UNRATED' => ['UNRATED', 6];
    }

    #[DataProvider('ratingProvider')]
    public function testRatingIndexInvertsEveryEnumLabel(string $label, int $index): void
    {
        $profile = Profile::fromArray(['content_rating' => $label]);

        self::assertSame($index, $profile->ratingIndex());
        self::assertSame($label, Profile::RATINGS[$index], 'the index round-trips back to the label');
    }

    public function testRatingIndexFallsBackToRForAnUnknownRating(): void
    {
        $profile = Profile::fromArray(['content_rating' => 'BOGUS']);

        self::assertSame(Profile::DEFAULT_RATING_INDEX, $profile->ratingIndex());
        self::assertSame(3, $profile->ratingIndex());
    }

    public function testTheRatingMapMirrorsTheServerOrderExactly(): void
    {
        self::assertSame(['G', 'PG', 'PG-13', 'R', 'NC-17', 'X', 'UNRATED'], Profile::RATINGS);
        self::assertSame(3, Profile::DEFAULT_RATING_INDEX);
    }
}
