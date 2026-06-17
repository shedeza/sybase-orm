<?php

declare(strict_types=1);

namespace SybaseORM\Cache;

use Psr\Log\LoggerInterface;
use SybaseORM\ORM\IdentityMap;
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
    private ?LoggerInterface $logger;
    private bool $secondLevelAvailable;

    /** @var int Number of consecutive failures */
    private int $failureCount = 0;

    /** @var float|null Timestamp when circuit was opened (cache disabled) */
    private ?float $circuitOpenedAt = null;

    /** @var int Maximum consecutive failures before opening the circuit */
    private int $failureThreshold;

    /** @var int Seconds to wait before retrying after circuit opens */
    private int $cooldownSeconds;

    public function __construct(
        IdentityMapInterface $identityMap,
        ?SecondLevelCacheInterface $secondLevel = null,
        ?LoggerInterface $logger = null,
        int $failureThreshold = 3,
        int $cooldownSeconds = 60,
    ) {
        $this->identityMap = $identityMap;
        $this->secondLevel = $secondLevel;
        $this->logger = $logger;
        $this->secondLevelAvailable = $secondLevel !== null;
        $this->failureThreshold = $failureThreshold;
        $this->cooldownSeconds = $cooldownSeconds;
    }

    public function get(string $entityClass, mixed $id): ?object
    {
        // First level: Identity Map
        $entity = $this->identityMap->get($entityClass, $id);
        if ($entity !== null) {
            return $entity;
        }

        // Second level
        if ($this->isSecondLevelAvailable()) {
            try {
                $key = $this->entityKey($entityClass, $id);
                /** @var object|null $cached */
                $cached = $this->secondLevel->get($key);
                if ($cached !== null && is_object($cached)) {
                    // Promote to first level
                    $this->identityMap->put($entityClass, $id, $cached);
                    $this->onSecondLevelSuccess();

                    return $cached;
                }
                $this->onSecondLevelSuccess();
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
        if ($this->isSecondLevelAvailable()) {
            try {
                $key = $this->entityKey($entityClass, $id);
                $this->secondLevel->put($key, $entity);
                $this->onSecondLevelSuccess();
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
        if ($this->isSecondLevelAvailable()) {
            try {
                $key = $this->entityKey($entityClass, $id);
                $this->secondLevel->delete($key);
                $this->onSecondLevelSuccess();
            } catch (\Throwable $e) {
                $this->handleSecondLevelFailure($e);
            }
        }
    }

    public function putQueryResult(string $queryKey, array $result, ?int $ttl = null): void
    {
        if (!$this->isSecondLevelAvailable()) {
            return;
        }

        try {
            $key = $this->queryKey($queryKey);
            $this->secondLevel->put($key, $result, $ttl);
            $this->onSecondLevelSuccess();
        } catch (\Throwable $e) {
            $this->handleSecondLevelFailure($e);
        }
    }

    public function getQueryResult(string $queryKey): ?array
    {
        if (!$this->isSecondLevelAvailable()) {
            return null;
        }

        try {
            $key = $this->queryKey($queryKey);
            $cached = $this->secondLevel->get($key);
            if (is_array($cached)) {
                $this->onSecondLevelSuccess();

                return $cached;
            }
            $this->onSecondLevelSuccess();
        } catch (\Throwable $e) {
            $this->handleSecondLevelFailure($e);
        }

        return null;
    }

    public function clear(): void
    {
        $this->identityMap->clear();

        if ($this->isSecondLevelAvailable()) {
            try {
                $this->secondLevel->clear();
                $this->onSecondLevelSuccess();
            } catch (\Throwable $e) {
                $this->handleSecondLevelFailure($e);
            }
        }
    }

    /**
     * Checks if the second-level cache is currently available.
     * Implements circuit-breaker: re-enables after cooldown period.
     */
    public function isSecondLevelAvailable(): bool
    {
        if ($this->secondLevel === null) {
            return false;
        }

        if ($this->secondLevelAvailable) {
            return true;
        }

        // Circuit is open — check if cooldown has passed
        if ($this->circuitOpenedAt !== null) {
            $elapsed = microtime(true) - $this->circuitOpenedAt;
            if ($elapsed >= $this->cooldownSeconds) {
                // Half-open: allow a retry
                $this->secondLevelAvailable = true;
                $this->circuitOpenedAt = null;
                $this->logger?->info('Second-level cache circuit half-open, retrying.');
            }
        }

        return $this->secondLevelAvailable;
    }

    private function entityKey(string $entityClass, mixed $id): string
    {
        return 'entity:' . $entityClass . ':' . IdentityMap::deriveKey($id);
    }

    private function queryKey(string $queryKey): string
    {
        return 'query:' . $queryKey;
    }

    private function handleSecondLevelFailure(\Throwable $e): void
    {
        $this->failureCount++;

        if ($this->failureCount >= $this->failureThreshold) {
            $this->secondLevelAvailable = false;
            $this->circuitOpenedAt = microtime(true);
            $this->logger?->warning(
                sprintf(
                    'Second-level cache disabled after %d consecutive failures (cooldown: %ds): %s',
                    $this->failureCount,
                    $this->cooldownSeconds,
                    $e->getMessage(),
                ),
                ['exception' => $e],
            );
        } else {
            $this->logger?->notice(
                sprintf('Second-level cache error (%d/%d): %s', $this->failureCount, $this->failureThreshold, $e->getMessage()),
                ['exception' => $e],
            );
        }
    }

    /**
     * Resets the failure counter on a successful second-level cache operation.
     */
    private function onSecondLevelSuccess(): void
    {
        if ($this->failureCount > 0) {
            $this->failureCount = 0;
            $this->logger?->info('Second-level cache recovered.');
        }
    }
}
