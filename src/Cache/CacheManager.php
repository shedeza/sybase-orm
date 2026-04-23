<?php

declare(strict_types=1);

namespace SybaseORM\Cache;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SybaseORM\ORM\IdentityMapInterface;

/**
 * Manages first-level (Identity Map) and optional second-level cache.
 *
 * First level: per-session Identity Map (always available).
 * Second level: shared cache adapter (e.g. Redis) with TTL support.
 *
 * If the second-level adapter throws, the manager logs a warning and
 * falls back to first-level only.
 */
final class CacheManager implements CacheManagerInterface
{
    private IdentityMapInterface $identityMap;
    private ?SecondLevelCacheInterface $secondLevel;
    private LoggerInterface $logger;
    private bool $secondLevelAvailable;

    public function __construct(
        IdentityMapInterface $identityMap,
        ?SecondLevelCacheInterface $secondLevel = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->identityMap = $identityMap;
        $this->secondLevel = $secondLevel;
        $this->logger = $logger ?? new NullLogger();
        $this->secondLevelAvailable = $secondLevel !== null;
    }

    public function get(string $entityClass, mixed $id): ?object
    {
        // First level: Identity Map
        $entity = $this->identityMap->get($entityClass, $id);
        if ($entity !== null) {
            return $entity;
        }

        // Second level
        if ($this->secondLevelAvailable) {
            try {
                $key = $this->entityKey($entityClass, $id);
                /** @var object|null $cached */
                $cached = $this->secondLevel->get($key);
                if ($cached !== null && is_object($cached)) {
                    // Promote to first level
                    $this->identityMap->put($entityClass, $id, $cached);
                    return $cached;
                }
            } catch (\Throwable $e) {
                $this->handleSecondLevelFailure($e);
            }
        }

        return null;
    }

    public function put(string $entityClass, mixed $id, object $entity): void
    {
        // Always store in first level
        $this->identityMap->put($entityClass, $id, $entity);

        // Store in second level if available
        if ($this->secondLevelAvailable) {
            try {
                $key = $this->entityKey($entityClass, $id);
                $this->secondLevel->put($key, $entity);
            } catch (\Throwable $e) {
                $this->handleSecondLevelFailure($e);
            }
        }
    }

    public function invalidate(string $entityClass, mixed $id): void
    {
        // Remove from first level
        $this->identityMap->remove($entityClass, $id);

        // Remove from second level
        if ($this->secondLevelAvailable) {
            try {
                $key = $this->entityKey($entityClass, $id);
                $this->secondLevel->delete($key);
            } catch (\Throwable $e) {
                $this->handleSecondLevelFailure($e);
            }
        }
    }

    public function putQueryResult(string $queryKey, array $result, ?int $ttl = null): void
    {
        if (!$this->secondLevelAvailable) {
            return;
        }

        try {
            $key = $this->queryKey($queryKey);
            $this->secondLevel->put($key, $result, $ttl);
        } catch (\Throwable $e) {
            $this->handleSecondLevelFailure($e);
        }
    }

    public function getQueryResult(string $queryKey): ?array
    {
        if (!$this->secondLevelAvailable) {
            return null;
        }

        try {
            $key = $this->queryKey($queryKey);
            $cached = $this->secondLevel->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable $e) {
            $this->handleSecondLevelFailure($e);
        }

        return null;
    }

    public function clear(): void
    {
        $this->identityMap->clear();

        if ($this->secondLevelAvailable) {
            try {
                $this->secondLevel->clear();
            } catch (\Throwable $e) {
                $this->handleSecondLevelFailure($e);
            }
        }
    }

    private function entityKey(string $entityClass, mixed $id): string
    {
        return 'entity:' . $entityClass . ':' . (string) $id;
    }

    private function queryKey(string $queryKey): string
    {
        return 'query:' . $queryKey;
    }

    private function handleSecondLevelFailure(\Throwable $e): void
    {
        $this->secondLevelAvailable = false;
        $this->logger->warning(
            'Second-level cache unavailable, falling back to first-level only: ' . $e->getMessage(),
            ['exception' => $e]
        );
    }
}
