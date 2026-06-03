<?php

declare(strict_types=1);

namespace SybaseORM\Cache;

/**
 * Interface for second-level cache adapters (Redis, Memcached, etc.).
 */
interface SecondLevelCacheInterface
{
    /**
     * Retrieves a value from the cache.
     *
     * @return mixed|null The cached value or null if not found.
     */
    public function get(string $key): mixed;

    /**
     * Stores a value in the cache.
     *
     * @param int|null $ttl Time-to-live in seconds (null = no expiration).
     */
    public function put(string $key, mixed $value, ?int $ttl = null): void;

    /**
     * Removes a value from the cache.
     */
    public function delete(string $key): void;

    /**
     * Returns true if the cache contains an entry for the given key.
     */
    public function has(string $key): bool;

    /**
     * Clears all entries from the cache.
     */
    public function clear(): void;
}
