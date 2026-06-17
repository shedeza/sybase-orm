<?php

declare(strict_types=1);

namespace SybaseORM\Cache;

/**
 * Second-level cache adapter backed by a Redis connection.
 *
 * Wraps a \Redis instance and serializes values using JSON for storage.
 * JSON is preferred over PHP serialize() to prevent PHP Object Injection
 * attacks if Redis is compromised.
 */
final class RedisCacheAdapter implements SecondLevelCacheInterface
{
    private \Redis $redis;
    private string $prefix;

    /** @var list<class-string> Allowed classes for deserialization fallback */
    private array $allowedClasses;

    /**
     * @param \Redis $redis Redis connection instance
     * @param string $prefix Key prefix for namespacing
     * @param list<class-string> $allowedClasses Classes allowed during legacy unserialize fallback
     */
    public function __construct(\Redis $redis, string $prefix = 'sybase_orm:', array $allowedClasses = [])
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
        $this->allowedClasses = $allowedClasses;
    }

    public function get(string $key): mixed
    {
        $value = $this->redis->get($this->prefix . $key);

        if ($value === false) {
            return null;
        }

        // Try JSON decode first (secure format)
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // Fallback: legacy serialized data with restricted allowed classes
        $result = @unserialize($value, ['allowed_classes' => $this->allowedClasses]);

        if ($result === false && $value !== serialize(false)) {
            // Deserialization failed — corrupted or tampered data
            return null;
        }

        return $result;
    }

    public function put(string $key, mixed $value, ?int $ttl = null): void
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        if ($encoded === false) {
            // Fallback to serialize only if JSON encoding fails (e.g. objects with circular refs)
            $encoded = serialize($value);
        }

        $prefixedKey = $this->prefix . $key;

        if ($ttl !== null) {
            $this->redis->setex($prefixedKey, $ttl, $encoded);
        } else {
            $this->redis->set($prefixedKey, $encoded);
        }
    }

    public function delete(string $key): void
    {
        $this->redis->del($this->prefix . $key);
    }

    public function has(string $key): bool
    {
        return (bool) $this->redis->exists($this->prefix . $key);
    }

    public function clear(): void
    {
        // Use SCAN to find and delete only keys with our prefix
        $iterator = null;
        do {
            $keys = $this->redis->scan($iterator, $this->prefix . '*', 1000);
            if ($keys !== false && count($keys) > 0) {
                $this->redis->del(...$keys);
            }
        } while ($iterator > 0);
    }
}
