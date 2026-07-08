<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Api\Dto\ContinueWatchingItem;
use Phlix\Console\Api\Dto\Library;
use Phlix\Console\Api\Dto\MediaPage;
use Phlix\Console\Api\Dto\PlaybackInfo;
use PHPUnit\Framework\TestCase;

final class DtoMappingTest extends TestCase
{
    public function testAuthUserMapsAndCoercesIsAdmin(): void
    {
        $user = AuthUser::fromArray([
            'id' => 'u1',
            'username' => 'joe',
            'email' => 'joe@x.tld',
            'display_name' => 'Joe',
            'is_admin' => 1,         // server sends 0|1
            'status' => 'active',
            'last_login' => '2026-06-22 10:00:00',   // raw users-row column name
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2026-06-22 10:00:00',
        ]);

        self::assertSame('u1', $user->id);
        self::assertSame('joe', $user->username);
        self::assertSame('Joe', $user->displayName);
        self::assertTrue($user->isAdmin);
        self::assertTrue($user->isActive());
        self::assertSame('2026-06-22 10:00:00', $user->lastLoginAt);
    }

    public function testAuthUserDefaultsDisplayNameToUsernameAndNonAdmin(): void
    {
        $user = AuthUser::fromArray([
            'id' => 'u2',
            'username' => 'sam',
            'email' => 'sam@x.tld',
            'is_admin' => 0,
            'status' => 'pending',
        ]);

        self::assertSame('sam', $user->displayName);
        self::assertFalse($user->isAdmin);
        self::assertFalse($user->isActive());
        self::assertNull($user->lastLoginAt);
    }

    public function testAuthUserAcceptsLastLoginAtFallbackSpelling(): void
    {
        $user = AuthUser::fromArray([
            'id' => 'u3',
            'username' => 'kim',
            'last_login_at' => '2026-06-22 12:00:00',
        ]);

        self::assertSame('2026-06-22 12:00:00', $user->lastLoginAt);
    }

    public function testLibraryMaps(): void
    {
        $lib = Library::fromArray([
            'id' => 'lib-1',
            'name' => 'Movies',
            'type' => 'movie',
            'paths' => ['/media/movies'],
            'options' => ['scan' => true],
            'display_order' => '2',
            'item_count' => '512',
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        self::assertSame('lib-1', $lib->id);
        self::assertSame('Movies', $lib->name);
        self::assertSame('movie', $lib->type);
        self::assertSame(['/media/movies'], $lib->paths);
        self::assertSame(['scan' => true], $lib->options);
        self::assertSame(2, $lib->displayOrder);
        self::assertSame(512, $lib->itemCount);
    }

    public function testLibraryDefaults(): void
    {
        $lib = Library::fromArray(['id' => 'l', 'name' => 'Bare']);

        self::assertSame('', $lib->type);
        self::assertSame([], $lib->paths);
        self::assertSame([], $lib->options);
        self::assertSame(0, $lib->displayOrder);
        self::assertSame(0, $lib->itemCount);
    }

    public function testMediaPageMapsItemsAndPaging(): void
    {
        $page = MediaPage::fromArray([
            'items' => [
                ['id' => 'a', 'name' => 'A', 'type' => 'movie'],
                ['id' => 'b', 'name' => 'B', 'type' => 'movie'],
                'not-an-array',
            ],
            'total' => 50,
            'offset' => 0,
            'limit' => 18,
        ]);

        self::assertCount(2, $page->items, 'non-array rows are skipped');
        self::assertSame('a', $page->items[0]->id);
        self::assertSame(50, $page->total);
        self::assertSame(0, $page->offset);
        self::assertSame(18, $page->limit);
        self::assertTrue($page->hasMore());
    }

    public function testMediaPageHasMoreIsFalseOnLastPage(): void
    {
        $page = MediaPage::fromArray([
            'items' => [['id' => 'a', 'name' => 'A']],
            'total' => 10,
            'offset' => 9,
            'limit' => 18,
        ]);

        self::assertFalse($page->hasMore());
    }

    public function testMediaPageDefaultsTotalAndLimitToItemCount(): void
    {
        $page = MediaPage::fromArray(['items' => [['id' => 'a', 'name' => 'A']]]);

        self::assertSame(1, $page->total);
        self::assertSame(1, $page->limit);
        self::assertFalse($page->hasMore());
    }

    public function testContinueWatchingItemProgress(): void
    {
        $entry = ContinueWatchingItem::fromArray([
            'media_item_id' => 'm1',
            'name' => 'Half Watched',
            'type' => 'movie',
            'position_ticks' => 50,
            'duration_ticks' => 100,
            'playback_status' => 'paused',
            'metadata' => ['poster_url' => 'https://srv/p.jpg'],
        ]);

        self::assertSame('m1', $entry->item->id);
        self::assertSame('https://srv/p.jpg', $entry->item->posterUrl);
        self::assertSame(50, $entry->positionTicks);
        self::assertSame('paused', $entry->playbackStatus);
        self::assertEqualsWithDelta(0.5, $entry->progress(), 0.0001);
    }

    public function testContinueWatchingProgressClampsAndGuardsZeroDuration(): void
    {
        $zero = ContinueWatchingItem::fromArray([
            'media_item_id' => 'm',
            'name' => 'X',
            'position_ticks' => 10,
            'duration_ticks' => 0,
        ]);
        self::assertSame(0.0, $zero->progress());

        $over = ContinueWatchingItem::fromArray([
            'media_item_id' => 'm',
            'name' => 'X',
            'position_ticks' => 150,
            'duration_ticks' => 100,
        ]);
        self::assertSame(1.0, $over->progress());
    }

    public function testPlaybackInfoMaps(): void
    {
        $info = PlaybackInfo::fromArray([
            'id' => 'm1',
            'name' => 'The Matrix',
            'type' => 'movie',
            'media_sources' => [
                ['id' => 'default', 'container' => 'mkv', 'direct_play' => true],
                'bogus',
            ],
            'markers' => ['intro' => ['start' => 0, 'end' => 90]],
        ]);

        self::assertSame('m1', $info->id);
        self::assertSame('The Matrix', $info->name);
        self::assertCount(1, $info->mediaSources, 'non-array sources are dropped');
        self::assertSame('default', $info->mediaSources[0]['id']);
        self::assertArrayHasKey('intro', $info->markers);
    }

    public function testPlaybackInfoDefaults(): void
    {
        $info = PlaybackInfo::fromArray(['id' => 'm']);

        self::assertSame('', $info->name);
        self::assertSame('', $info->type);
        self::assertSame([], $info->mediaSources);
        self::assertSame([], $info->markers);
        self::assertSame([], $info->qualityLadder, 'absent quality_ladder → empty list');
    }

    public function testPlaybackInfoDecodesTheQualityLadderPreview(): void
    {
        $info = PlaybackInfo::fromArray([
            'id' => 'm1',
            'quality_ladder' => [
                ['id' => '1080p', 'label' => '1080p', 'width' => 1920, 'height' => 1080, 'url' => null],
                ['id' => '720p', 'label' => '720p', 'width' => 1280, 'height' => 720, 'url' => null],
                'bogus',
            ],
        ]);

        self::assertCount(2, $info->qualityLadder, 'non-array rungs are dropped');
        self::assertSame('1080p', $info->qualityLadder[0]->id);
        self::assertNull($info->qualityLadder[0]->url, 'the pre-flight preview carries no signed urls');
    }

    public function testPlaybackInfoNullLadderIsEmpty(): void
    {
        $info = PlaybackInfo::fromArray(['id' => 'm1', 'quality_ladder' => null]);

        self::assertSame([], $info->qualityLadder, 'a null (unscanned) ladder → empty list');
    }
}
