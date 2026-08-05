<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Ui;

use Phlix\Console\Ui\RatingBadge;
use PHPUnit\Framework\TestCase;

final class RatingBadgeTest extends TestCase
{
    public function testRenderWithValidScore(): void
    {
        $badge = new RatingBadge(7.5);

        $rendered = $badge->render();

        self::assertStringContainsString('7.5/10', $rendered);
        self::assertStringContainsString('★', $rendered);
    }

    public function testRenderWithNullScoreReturnsEmptyString(): void
    {
        $badge = new RatingBadge(null);

        $rendered = $badge->render();

        self::assertSame('', $rendered);
    }

    public function testRenderWithMaximumScore(): void
    {
        $badge = new RatingBadge(10.0);

        $rendered = $badge->render();

        self::assertStringContainsString('10.0/10', $rendered);
        self::assertStringContainsString('★★★★★', $rendered);
    }

    public function testRenderWithMinimumScore(): void
    {
        $badge = new RatingBadge(0.0);

        $rendered = $badge->render();

        self::assertStringContainsString('0.0/10', $rendered);
        self::assertStringNotContainsString('★', $rendered, 'no full stars for zero score');
    }

    public function testRenderClampsScoreToValidRange(): void
    {
        // Score above 10 should be clamped to 10
        $badge = new RatingBadge(15.0);

        $rendered = $badge->render();

        self::assertStringContainsString('10.0/10', $rendered);
    }

    public function testRenderClampsNegativeScoreToZero(): void
    {
        // Negative score should be clamped to 0
        $badge = new RatingBadge(-5.0);

        $rendered = $badge->render();

        self::assertStringContainsString('0.0/10', $rendered);
    }

    public function testRenderWithHalfStarScore(): void
    {
        // 5.0 should give 2.5 stars (2 full + 1 half)
        $badge = new RatingBadge(5.0);

        $rendered = $badge->render();

        self::assertStringContainsString('5.0/10', $rendered);
        self::assertStringContainsString('½', $rendered, 'half star indicator present');
    }
}
