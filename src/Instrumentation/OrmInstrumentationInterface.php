<?php

declare(strict_types=1);

namespace SybaseORM\Instrumentation;

/**
 * Emits instrumentation events for profiling, debugging, and monitoring.
 *
 * Implementations can collect metrics, log to profilers, or emit traces.
 * The default NullInstrumentation has zero overhead in production.
 */
interface OrmInstrumentationInterface
{
    // ── Queries ─────────────────────────────────────────────────────

    /** Called before a SQL query is executed. */
    public function onQueryStart(string $sql, array $params, string $connection): void;

    /** Called after a SQL query completes. */
    public function onQueryEnd(string $sql, array $params, string $connection, float $timeMs): void;

    // ── Hydration ───────────────────────────────────────────────────

    /** Called when a single entity is hydrated from a DB row. */
    public function onEntityHydrated(string $entityClass, mixed $id): void;

    /** Called when a collection of entities is hydrated. */
    public function onCollectionHydrated(string $entityClass, int $count): void;

    // ── Identity Map ────────────────────────────────────────────────

    /** Called when an entity is found in the identity map (cache hit). */
    public function onIdentityMapHit(string $entityClass, mixed $id): void;

    /** Called when an entity is NOT found in the identity map (cache miss). */
    public function onIdentityMapMiss(string $entityClass, mixed $id): void;

    /** Called when an entity is stored in the identity map. */
    public function onIdentityMapPut(string $entityClass, mixed $id): void;

    // ── Unit of Work ────────────────────────────────────────────────

    /** Called when an entity is scheduled for an operation (insert/update/delete). */
    public function onEntityScheduled(string $operation, string $entityClass, mixed $id): void;

    /** Called at the start of a flush operation. */
    public function onFlushStart(int $inserts, int $updates, int $deletes): void;

    /** Called at the end of a flush operation. */
    public function onFlushEnd(float $timeMs): void;

    // ── Lazy Loading (Proxy) ────────────────────────────────────────

    /** Called when a proxy triggers lazy loading. */
    public function onLazyLoad(string $entityClass, mixed $id): void;

    // ── Second-Level Cache ──────────────────────────────────────────

    /** Called when a cache key is found (hit). */
    public function onCacheHit(string $key): void;

    /** Called when a cache key is NOT found (miss). */
    public function onCacheMiss(string $key): void;

    /** Called when a value is written to the cache. */
    public function onCacheWrite(string $key): void;

    // ── Transactions ────────────────────────────────────────────────

    /** Called when a transaction begins. */
    public function onTransactionBegin(): void;

    /** Called when a transaction is committed. */
    public function onTransactionCommit(float $durationMs): void;

    /** Called when a transaction is rolled back. */
    public function onTransactionRollback(string $reason): void;
}
