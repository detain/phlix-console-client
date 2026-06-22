<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api;

use Phlix\Console\Api\MediaQuery;
use PHPUnit\Framework\TestCase;

final class MediaQueryTest extends TestCase
{
    public function testDefaultsToLimitAndOffsetOnly(): void
    {
        self::assertSame(['limit' => 50, 'offset' => 0], (new MediaQuery())->toParams());
    }

    public function testForLibrary(): void
    {
        $params = MediaQuery::forLibrary('lib-1', limit: 18, offset: 36)->toParams();

        self::assertSame('lib-1', $params['libraryId']);
        self::assertSame(18, $params['limit']);
        self::assertSame(36, $params['offset']);
    }

    public function testOmitsUnsetFields(): void
    {
        $params = (new MediaQuery(libraryId: 'l', search: '', topLevel: false))->toParams();

        self::assertArrayHasKey('libraryId', $params);
        self::assertArrayNotHasKey('search', $params, 'empty search omitted');
        self::assertArrayNotHasKey('topLevel', $params, 'topLevel only when true');
    }

    public function testRendersAllFields(): void
    {
        $params = (new MediaQuery(
            libraryId: 'l1',
            search: 'matrix',
            parentId: 'p1',
            topLevel: true,
            sort: 'year',
            order: 'desc',
            genres: ['Action', 'Sci-Fi'],
            yearFrom: 1990,
            yearTo: 2000,
            ratings: ['R'],
            actors: ['Keanu Reeves'],
            match: 'matched',
            limit: 25,
            offset: 50,
        ))->toParams();

        self::assertSame('matrix', $params['search']);
        self::assertSame('p1', $params['parentId']);
        self::assertSame('1', $params['topLevel']);
        self::assertSame('year', $params['sort']);
        self::assertSame('desc', $params['order']);
        self::assertSame(['Action', 'Sci-Fi'], $params['genres']);
        self::assertSame(1990, $params['yearFrom']);
        self::assertSame(2000, $params['yearTo']);
        self::assertSame(['R'], $params['ratings']);
        self::assertSame(['Keanu Reeves'], $params['actors']);
        self::assertSame('matched', $params['match']);
        self::assertSame(25, $params['limit']);
        self::assertSame(50, $params['offset']);
    }

    public function testHttpBuildQueryProducesArrayBrackets(): void
    {
        $query = http_build_query((new MediaQuery(genres: ['Action', 'Drama']))->toParams());

        self::assertStringContainsString('genres%5B0%5D=Action', $query);
        self::assertStringContainsString('genres%5B1%5D=Drama', $query);
    }

    public function testWithOffsetAndWithLimitPreserveFilters(): void
    {
        $base = new MediaQuery(libraryId: 'l1', search: 'x', genres: ['Action']);

        $paged = $base->withOffset(20)->withLimit(10);

        self::assertSame('l1', $paged->libraryId);
        self::assertSame('x', $paged->search);
        self::assertSame(['Action'], $paged->genres);
        self::assertSame(20, $paged->offset);
        self::assertSame(10, $paged->limit);
    }

    public function testCacheKeyIgnoresPaging(): void
    {
        $a = new MediaQuery(libraryId: 'l1', search: 'x', offset: 0, limit: 18);
        $b = new MediaQuery(libraryId: 'l1', search: 'x', offset: 90, limit: 50);
        $c = new MediaQuery(libraryId: 'l2', search: 'x');

        self::assertSame($a->cacheKey(), $b->cacheKey(), 'same filters, different page → same key');
        self::assertNotSame($a->cacheKey(), $c->cacheKey(), 'different library → different key');
    }
}
