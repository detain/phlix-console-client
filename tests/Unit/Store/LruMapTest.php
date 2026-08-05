<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Store;

use Phlix\Console\Store\LruMap;
use PHPUnit\Framework\TestCase;

final class LruMapTest extends TestCase
{
    public function testInsertAndRetrieve(): void
    {
        $map = new LruMap(3);

        $map->set('a', 'alpha');
        $map->set('b', 'beta');

        self::assertSame('alpha', $map->get('a'));
        self::assertSame('beta', $map->get('b'));
        self::assertTrue($map->has('a'));
        self::assertTrue($map->has('b'));
    }

    public function testGetPromotesToMostRecentlyUsed(): void
    {
        $map = new LruMap(3);

        $map->set('a', 'alpha');
        $map->set('b', 'beta');
        $map->set('c', 'charlie');

        // Access 'a' to promote it to MRU.
        $map->get('a');

        // Inserting a new entry should evict LRU (which is now 'b', since 'a' was promoted).
        $map->set('d', 'delta');

        // 'a' was accessed and promoted, so 'b' should be the LRU and evicted.
        self::assertFalse($map->has('b'), 'LRU entry should be evicted');
        self::assertTrue($map->has('a'), 'MRU entry should remain');
        self::assertTrue($map->has('c'), 'middle entry should remain');
        self::assertTrue($map->has('d'), 'newly inserted entry should exist');
    }

    public function testEvictAtCapacity(): void
    {
        $map = new LruMap(2);

        $map->set('a', 'alpha');
        $map->set('b', 'beta');

        self::assertSame(2, $this->countMap($map));

        // Adding a third entry should trigger eviction of the LRU.
        $map->set('c', 'charlie');

        self::assertSame(2, $this->countMap($map), 'map should be at capacity after eviction');
        self::assertFalse($map->has('a'), 'LRU entry should be evicted');
        self::assertTrue($map->has('b'));
        self::assertTrue($map->has('c'));
    }

    public function testEvictOrderLeastRecentlyUsedFirst(): void
    {
        $map = new LruMap(3);

        $map->set('a', 'alpha');
        $map->set('b', 'beta');
        $map->set('c', 'charlie');

        // 'a' is LRU. Inserting three more should evict in order: 'a', then 'b', then 'c'.
        $map->set('d', 'delta');
        self::assertFalse($map->has('a'), 'a should be evicted first (LRU)');
        self::assertTrue($map->has('b'));
        self::assertTrue($map->has('c'));
        self::assertTrue($map->has('d'));

        $map->set('e', 'echo');
        self::assertFalse($map->has('b'), 'b should be evicted second');
        self::assertTrue($map->has('c'));
        self::assertTrue($map->has('d'));
        self::assertTrue($map->has('e'));

        $map->set('f', 'foxtrot');
        self::assertFalse($map->has('c'), 'c should be evicted third');
        self::assertTrue($map->has('d'));
        self::assertTrue($map->has('e'));
        self::assertTrue($map->has('f'));
    }

    public function testClear(): void
    {
        $map = new LruMap(3);

        $map->set('a', 'alpha');
        $map->set('b', 'beta');
        $map->set('c', 'charlie');

        $map->clear();

        self::assertFalse($map->has('a'));
        self::assertFalse($map->has('b'));
        self::assertFalse($map->has('c'));
        self::assertSame(0, $this->countMap($map));
    }

    public function testSetUpdatesExistingKeyAndPromotes(): void
    {
        $map = new LruMap(3);

        $map->set('a', 'alpha');
        $map->set('b', 'beta');
        $map->set('c', 'charlie');

        // Update 'a' with a new value and promote it to MRU.
        $map->set('a', 'alpha-updated');

        // Insert new entry - 'b' is now LRU and should be evicted.
        $map->set('d', 'delta');

        self::assertTrue($map->has('a'));
        self::assertFalse($map->has('b'), 'b should be evicted (was LRU before a was re-promoted)');
        self::assertTrue($map->has('c'));
        self::assertTrue($map->has('d'));
    }

    public function testDeleteRemovesSpecificEntry(): void
    {
        $map = new LruMap(3);

        $map->set('a', 'alpha');
        $map->set('b', 'beta');
        $map->set('c', 'charlie');

        $map->delete('b');

        self::assertTrue($map->has('a'));
        self::assertFalse($map->has('b'));
        self::assertTrue($map->has('c'));
        self::assertSame(2, $this->countMap($map));
    }

    public function testGetNonExistentReturnsNull(): void
    {
        $map = new LruMap(3);

        $map->set('a', 'alpha');

        self::assertNull($map->get('nonexistent'));
        self::assertFalse($map->has('nonexistent'));
    }

    public function testEmptyMapClearIsIdempotent(): void
    {
        $map = new LruMap(3);

        $map->clear();
        $map->clear();

        self::assertSame(0, $this->countMap($map));
    }

    public function testDeleteNonExistentKeyIsNoOp(): void
    {
        $map = new LruMap(3);

        $map->set('a', 'alpha');
        $map->delete('nonexistent');

        self::assertSame(1, $this->countMap($map));
        self::assertTrue($map->has('a'));
    }

    /**
     * Count entries in the LruMap by iterating all keys.
     *
     * We use has() to check each key since LruMap is internal-only.
     * For this test helper we use reflection to access the private $data property.
     */
    private function countMap(LruMap $map): int
    {
        $reflection = new \ReflectionClass($map);
        $prop = $reflection->getProperty('data');
        $prop->setAccessible(true);
        /** @var array<string, mixed> $data */
        $data = $prop->getValue($map);

        return count($data);
    }
}
