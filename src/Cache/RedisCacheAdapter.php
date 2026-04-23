<?php

declare(strict_types=1);

namespace SybaseORM\Cache;

/**
 * Second-level cache adapter backed by a Redis connection.
 *
 * Wraps a \Redis instance and serializes values for storage.
 */
final class RedisCacheAdapter implements SecondLevelCacheInterface
{
    private \Redis $redis;
    private string $prefix;

    public function __construct(\Redis $redis, string $prefix = 'sybase_orm:')
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    public function get(string $key): mixed
    {
        $value = $this->redis->get($this->prefix . $key);

        if ($value === false) {
            return null;
        }

        return unserialize($value);
    }

    public function put(string $key, mixed $value, ?int $ttl = null): void
    {
        $serialized = serialize($value);
        $prefixedKey = $this->prefix . $key;

        if ($ttl !== null) {
            $this->redis->setex($prefixedKey, $ttl, $serialized);
        } else {
            $this->redis->set($prefixedKey, $serialized);
        }
    }

    public function delete(string $key): void
    {
        $this->redis->del($this->prefix . $key);
    }

    public function clear(): void
    {
        // Use SCAN to find and delete only keys with our prefix
        $iterator = null;
        do {
            $keys = $this->redis->scan($iterator, $this->prefix . '*');
            if ($keys !== false && count($keys) > 0) {
                $this->redis->del(...$keys);
            }
        } while ($iterator > 0);
    }
}
