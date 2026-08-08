<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\Dto\MediaRatings;
use Phlix\Console\Api\Dto\Rating;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\RatingsLoadedMsg;
use Phlix\Console\Screen\DetailScreen;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\Mosaic;

/**
 * Unit tests for DetailScreen ratings display.
 *
 * @covers \Phlix\Console\Screen\DetailScreen
 */
final class DetailScreenRatingsTest extends TestCase
{
    /**
     * Create a DetailScreen with an already-loaded item and ratings via reflection.
     *
     * @param list<Rating> $ratings
     */
    private function screenWithRatings(array $ratings, ?MediaItem $item = null): DetailScreen
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $screen = new DetailScreen(
            'm1',
            'The Matrix',
            new MediaStore($api),
            new PosterLoader(Mosaic::halfBlock()),
            'https://srv',
            cols: 120,
            rows: 40,
        );

        // Use reflection to set the loaded item so the screen can render.
        $reflection = new \ReflectionClass($screen);

        $loadedProperty = $reflection->getProperty('loaded');
        $loadedProperty->setAccessible(true);
        $loadedProperty->setValue($screen, true);

        if ($item !== null) {
            $itemProperty = $reflection->getProperty('item');
            $itemProperty->setAccessible(true);
            $itemProperty->setValue($screen, $item);
        }

        // If ratings were provided, inject them via RatingsLoadedMsg update.
        if ($ratings !== []) {
            $mediaRatings = new MediaRatings(
                itemId: 'm1',
                ratings: $ratings,
                aggregateScore: 8.5,
            );
            $msg = new RatingsLoadedMsg($mediaRatings);
            [$updatedScreen] = $screen->update($msg);

            return $updatedScreen;
        }

        return $screen;
    }

    /**
     * Create a minimal loaded MediaItem for testing.
     */
    private function createTestItem(): MediaItem
    {
        return MediaItem::fromArray([
            'id' => 'm1',
            'name' => 'The Matrix',
            'type' => 'movie',
            'year' => 1999,
            'genres' => ['Action', 'Sci-Fi'],
            'overview' => 'A hacker discovers the shocking truth about his reality.',
            'poster_url' => null,
            'stream_url' => null,
        ]);
    }

    public function testScreenConstructsAndRendersWithoutThrowing(): void
    {
        $screen = $this->screenWithRatings([]);

        // Rendering with no ratings should not throw.
        $view = $screen->view();

        $this->assertIsString($view);
        $this->assertStringContainsString('The Matrix', $view);
    }

    public function testRatingsLoadedMsgUpdatesScreenState(): void
    {
        $screen = $this->screenWithRatings([]);

        $ratings = [
            new Rating(
                id: 1,
                mediaItemId: 'm1',
                source: 'tmdb',
                type: 'average',
                score: 8.7,
                votes: 1234,
            ),
            new Rating(
                id: 2,
                mediaItemId: 'm1',
                source: 'imdb',
                type: 'average',
                score: 8.5,
                votes: 5678,
            ),
        ];

        $mediaRatings = new MediaRatings(
            itemId: 'm1',
            ratings: $ratings,
            aggregateScore: 8.6,
        );

        $msg = new RatingsLoadedMsg($mediaRatings);
        [$updatedScreen] = $screen->update($msg);

        // The screen should still be the same instance (no error).
        $this->assertInstanceOf(DetailScreen::class, $updatedScreen);

        // Rendering should not throw with ratings present.
        $view = $updatedScreen->view();
        $this->assertIsString($view);
    }

    public function testRenderedViewContainsTmdbRatingBadge(): void
    {
        $ratings = [
            new Rating(
                id: 1,
                mediaItemId: 'm1',
                source: 'tmdb',
                type: 'average',
                score: 8.7,
                votes: 1234,
            ),
        ];

        $screen = $this->screenWithRatings($ratings, $this->createTestItem());

        $view = $screen->view();

        // The TMDB rating score should appear in the rendered view.
        $this->assertStringContainsString('8.7/10', $view, 'TMDB rating score should be rendered');
    }

    public function testRenderedViewContainsImdbRatingBadge(): void
    {
        $ratings = [
            new Rating(
                id: 1,
                mediaItemId: 'm1',
                source: 'imdb',
                type: 'average',
                score: 8.5,
                votes: 5678,
            ),
        ];

        $screen = $this->screenWithRatings($ratings, $this->createTestItem());

        $view = $screen->view();

        // The IMDb rating score should appear in the rendered view.
        $this->assertStringContainsString('8.5/10', $view, 'IMDb rating score should be rendered');
    }

    public function testMultipleRatingsRenderInMetaLine(): void
    {
        $ratings = [
            new Rating(
                id: 1,
                mediaItemId: 'm1',
                source: 'tmdb',
                type: 'average',
                score: 8.7,
                votes: 1234,
            ),
            new Rating(
                id: 2,
                mediaItemId: 'm1',
                source: 'imdb',
                type: 'average',
                score: 8.5,
                votes: 5678,
            ),
        ];

        $screen = $this->screenWithRatings($ratings, $this->createTestItem());

        $view = $screen->view();

        // Both ratings should appear in the rendered view.
        $this->assertStringContainsString('8.7/10', $view, 'TMDB rating should be rendered');
        $this->assertStringContainsString('8.5/10', $view, 'IMDb rating should be rendered');
    }

    public function testRatingsWithNullScoreRendersNothingForThatRating(): void
    {
        $ratings = [
            new Rating(
                id: 1,
                mediaItemId: 'm1',
                source: 'tmdb',
                type: 'average',
                score: 8.7,
                votes: 1234,
            ),
            new Rating(
                id: 2,
                mediaItemId: 'm1',
                source: 'imdb',
                type: 'average',
                score: 0.0,
                votes: 0,
            ),
        ];

        $screen = $this->screenWithRatings($ratings, $this->createTestItem());

        // Should not throw.
        $view = $screen->view();
        $this->assertIsString($view);

        // The 0.0 rating should still render (RatingBadge clamps to 0, not null).
        $this->assertStringContainsString('0.0/10', $view, 'Zero IMDb rating should still render');
    }

    public function testRatingsLoadedMsgWithNoRatingsRendersWithoutError(): void
    {
        $mediaRatings = new MediaRatings(
            itemId: 'm1',
            ratings: [],
            aggregateScore: null,
        );

        $api = new ApiClient('https://srv', new FakeTransport());
        $screen = new DetailScreen(
            'm1',
            'The Matrix',
            new MediaStore($api),
            new PosterLoader(Mosaic::halfBlock()),
            'https://srv',
            cols: 120,
            rows: 40,
        );

        // Set loaded state via reflection.
        $reflection = new \ReflectionClass($screen);
        $loadedProperty = $reflection->getProperty('loaded');
        $loadedProperty->setAccessible(true);
        $loadedProperty->setValue($screen, true);

        $msg = new RatingsLoadedMsg($mediaRatings);
        [$updatedScreen] = $screen->update($msg);

        // Rendering should not throw even with empty ratings.
        $view = $updatedScreen->view();
        $this->assertIsString($view);
        $this->assertStringContainsString('The Matrix', $view);
    }

    public function testRatingsFlowFromMessageToView(): void
    {
        // This test verifies the complete ratings data flow:
        // 1. Screen is constructed
        // 2. RatingsLoadedMsg is received
        // 3. Screen state is updated
        // 4. View renders with rating badges

        $ratings = [
            new Rating(
                id: 1,
                mediaItemId: 'm1',
                source: 'tmdb',
                type: 'average',
                score: 9.0,
                votes: 9999,
            ),
            new Rating(
                id: 2,
                mediaItemId: 'm1',
                source: 'imdb',
                type: 'average',
                score: 8.8,
                votes: 8888,
            ),
        ];

        $screen = $this->screenWithRatings($ratings, $this->createTestItem());

        // Verify rendering works end-to-end.
        $view = $screen->view();

        $this->assertIsString($view);
        $this->assertStringContainsString('The Matrix', $view, 'title is rendered');
        $this->assertStringContainsString('9.0/10', $view, 'TMDB rating is rendered');
        $this->assertStringContainsString('8.8/10', $view, 'IMDb rating is rendered');
    }
}
