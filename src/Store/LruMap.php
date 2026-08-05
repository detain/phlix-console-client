<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Store;

/**
 * A simple Least-Recently-Used (LRU) map backed by a PHP array.
 *
 * Holds at most N entries. Accessing an entry via {@see get()} promotes it to
 * the most-recently-used end. Inserting a new entry when at capacity evicts
 * the least-recently-used entry before insertion.
 *
 * The LRU is a size bound; it works alongside TTL (time-to-live) caching.
 * Size bound prevents unbounded memory growth; TTL bound ensures stale data
 * is not served. Both mechanisms are independent and complementary.
 *
 * @template T of mixed
 */
final class LruMap implements \Countable
{
    /** @var array<string, T> */
    private array $data = [];

    /**
     * @param int<1, max> $capacity  Maximum number of entries to hold.
     */
    public function __construct(
        private readonly int $capacity,
    ) {
    }

    /**
     * Retrieve an entry by key, promoting it to the most-recently-used end.
     *
     * @return T|null
     */
    public function get(string $key): ?T
    {
        if (!array_key_exists($key, $this->data)) {
            return null;
        }

        // Snapshot value, remove from current position, re-append at end (MRU).
        $value = $this->data[$key];
        unset($this->data[$key]);
        $this->data[$key] = $value;

        return $value;
    }

    /**
     * Store an entry, evicting the least-recently-used entry if at capacity.
     *
     * @param T $value
     */
    public function set(string $key, T $value): void
    {
        // If key exists, remove it first so the re-insert lands at the MRU end.
        if (array_key_exists($key, $this->data)) {
            unset($this->data[$key]);
        }

        // Evict LRU entry when at capacity BEFORE inserting the new entry.
        if (count($this->data) >= $this->capacity) {
            foreach ($this->data as $lruKey => $evictedValue) {
                unset($this->data[$lruKey]);
                break;
            }
        }

        $this->data[$key] = $value;
    }

    /**
     * Check whether a key exists in the map.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Retrieve an entry by key WITHOUT promoting it to the most-recently-used end.
     *
     * Use this for TTL validation before deciding whether to use or discard an entry.
     * Use {@see get()} when the entry will be served to the caller (promotion is correct).
     *
     * @return T|null
     */
    public function peek(string $key): ?T
    {
        if (!array_key_exists($key, $this->data)) {
            return null;
        }

        return $this->data[$key];
    }

    /**
     * Delete a specific entry from the map.
     */
    public function delete(string $key): void
    {
        unset($this->data[$key]);
    }

    /**
     * Remove all entries from the map.
     */
    public function clear(): void
    {
        $this->data = [];
    }

    /**
     * Returns the number of entries currently cached.
     */
    public function count(): int
    {
        return count($this->data);
    }
}
