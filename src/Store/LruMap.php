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
 * Concrete usage in stores:
 *   - Pages:  LruMap<string, array{page: MediaPage|BookPage|..., at: float}>
 *   - Items:  LruMap<string, array{item: MediaItem|Book|Audiobook|..., at: float}>
 *   - etc.
 */
final class LruMap
{
    /** @var array<string, mixed> */
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
     * @return mixed  The value if found, null otherwise.
     */
    public function get(string $key): mixed
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
     * @param mixed $value
     */
    public function set(string $key, mixed $value): void
    {
        // If key exists, remove it first so the re-insert lands at the MRU end.
        if (array_key_exists($key, $this->data)) {
            unset($this->data[$key]);
        }

        // Evict LRU entry when at capacity BEFORE inserting the new entry.
        if (count($this->data) >= $this->capacity) {
            $lruKey = array_key_first($this->data);
            if ($lruKey !== null) {
                unset($this->data[$lruKey]);
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
     * @return mixed  The value if found, null otherwise.
     */
    public function peek(string $key): mixed
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
}
